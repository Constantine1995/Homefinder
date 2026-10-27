"""The language model behind LiteLLM: Qwen through Ollama in development, YandexGPT or GigaChat in production.

Switching models is a matter of AGENT_LLM_* settings; the code asks for structured output only.
"""

import os
from typing import Any, Self

from openai import OpenAIError
from pydantic import BaseModel, ValidationError

from homefinder.config import Settings

# LiteLLM downloads its model price list on import unless told to use the bundled copy.
os.environ.setdefault("LITELLM_LOCAL_MODEL_COST_MAP", "True")

import litellm

litellm.telemetry = False


class LlmError(Exception):
    """The model did not answer, or did not answer with the asked structure."""


class Llm:
    def __init__(self, settings: Settings, **completion_options: Any) -> None:
        """Use the model of the settings; extra options go to every LiteLLM call, e.g. `mock_response` in tests."""
        self.settings = settings
        self.completion_options = completion_options
        self.prompt_tokens = 0
        self.completion_tokens = 0

    def fresh(self) -> Self:
        """A copy with its own token counts, for one turn of a dialog."""
        copy = type(self).__new__(type(self))
        copy.__dict__.update(self.__dict__)
        copy.prompt_tokens = copy.completion_tokens = 0
        return copy

    async def text(self, messages: list[dict[str, str]]) -> str:
        """Get a plain-text answer: the agent's reply to the buyer."""
        error: Exception | None = None
        for _ in range(2):
            try:
                response = await self._complete(messages)
                content = (response.choices[0].message.content or "").strip()
                if content:
                    return content
            except OpenAIError as failure:
                error = failure
        raise LlmError(f"The model gave no answer: {error}") from error

    async def _complete(self, messages: list[dict[str, str]], **options: Any) -> Any:
        if self.settings.llm_reasoning_effort is not None:
            options["reasoning_effort"] = self.settings.llm_reasoning_effort
        if self.settings.llm_keep_alive is not None:
            options["keep_alive"] = self.settings.llm_keep_alive
        response = await litellm.acompletion(
            model=self.settings.llm_model,
            api_base=self.settings.llm_api_base,
            messages=messages,
            temperature=0,
            timeout=self.settings.llm_timeout,
            **options,
            **self.completion_options,
        )
        usage = getattr(response, "usage", None)
        self.prompt_tokens += getattr(usage, "prompt_tokens", 0) or 0
        self.completion_tokens += getattr(usage, "completion_tokens", 0) or 0
        return response

    async def structured[T: BaseModel](
        self, messages: list[dict[str, str]], schema: type[T], max_tokens: int | None = None
    ) -> T:
        """Get an answer the schema accepts; a failed call or a malformed answer is tried again once.

        An answer cut off at `max_tokens` is not tried again: at temperature 0 the model writes the same one.
        LiteLLM raises OpenAI-compatible errors whatever the provider, so OpenAIError covers them all.
        """
        options: dict[str, Any] = {"response_format": _response_format(schema)}
        if max_tokens is not None:
            options["max_tokens"] = max_tokens

        error: Exception | None = None
        for _ in range(2):
            try:
                response = await self._complete(messages, **options)
            except OpenAIError as failure:
                error = failure
                continue
            if response.choices[0].finish_reason == "length":
                raise LlmError(f"The answer of the model to {schema.__name__} was longer than {max_tokens} tokens")
            try:
                return schema.model_validate_json(response.choices[0].message.content or "")
            except ValidationError as failure:
                error = failure

        raise LlmError(f"The model gave no answer that follows {schema.__name__}: {error}") from error

    async def embed(self, texts: list[str]) -> list[list[float]]:
        """Get the vectors of the texts, in their order, from the embedding model."""
        if not texts:
            return []
        try:
            response = await litellm.aembedding(
                model=self.settings.embedding_model,
                api_base=self.settings.llm_api_base,
                input=texts,
                timeout=self.settings.llm_timeout,
                **self.completion_options,
            )
        except OpenAIError as error:
            raise LlmError(f"The embedding model failed: {error}") from error

        vectors = [item["embedding"] for item in sorted(response.data, key=lambda item: item["index"])]
        if len(vectors) != len(texts) or any(len(vector) != self.settings.embedding_dimensions for vector in vectors):
            raise LlmError(
                f"The embedding model answered with vectors of another size than {self.settings.embedding_dimensions}"
            )
        return vectors


def _response_format(schema: type[BaseModel]) -> dict[str, Any]:
    """Ask for a JSON object and leave its shape to the prompt; the schema checks the answer afterwards.

    Constrained by a JSON schema, Ollama makes the model write the fields in the order of the schema: a model that
    wants to write the finishing first then skips the price for good. On the 202 phrases of evals/filters.jsonl
    free JSON gets more fields right. Given a Pydantic model, LiteLLM would also make every field
    required, and a small model would write out every empty one.
    """
    return {"type": "json_object"}
