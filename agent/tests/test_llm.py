from types import SimpleNamespace
from typing import Any

import litellm
import pytest
from pydantic import BaseModel

from homefinder.llm import Llm, LlmError
from tests.test_self_query import SETTINGS

pytestmark = pytest.mark.anyio


class Plan(BaseModel):
    tool: str


class CutOffLlm(Llm):
    """Answers every call with the same JSON, cut off at the token limit."""

    def __init__(self) -> None:
        super().__init__(SETTINGS)
        self.calls: list[dict[str, Any]] = []

    async def _complete(self, messages: list[dict[str, str]], **options: Any) -> Any:
        self.calls.append(options)
        message = SimpleNamespace(content='{"tool": "answer", "args": {"flats": [{"id": 509, "pri')
        return SimpleNamespace(choices=[SimpleNamespace(finish_reason="length", message=message)])


async def test_an_answer_cut_off_at_the_token_limit_is_not_asked_again() -> None:
    llm = CutOffLlm()

    with pytest.raises(LlmError, match="longer than 200 tokens"):
        await llm.structured([{"role": "user", "content": "что дальше?"}], Plan, max_tokens=200)

    assert len(llm.calls) == 1
    assert llm.calls[0]["max_tokens"] == 200


async def test_it_asks_a_thinking_model_not_to_think_when_told_so(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[dict[str, Any]] = []

    async def completion(**options: Any) -> Any:
        calls.append(options)
        message = SimpleNamespace(content="Здравствуйте!")
        return SimpleNamespace(choices=[SimpleNamespace(finish_reason="stop", message=message)], usage=None)

    monkeypatch.setattr(litellm, "acompletion", completion)

    messages = [{"role": "user", "content": "привет"}]
    await Llm(SETTINGS.model_copy(update={"llm_reasoning_effort": "none"})).text(messages)
    await Llm(SETTINGS.model_copy(update={"llm_reasoning_effort": None})).text(messages)

    assert calls[0]["reasoning_effort"] == "none"
    assert "reasoning_effort" not in calls[1]


async def test_it_asks_ollama_to_keep_the_model_loaded_when_told_so(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[dict[str, Any]] = []

    async def completion(**options: Any) -> Any:
        calls.append(options)
        message = SimpleNamespace(content="Здравствуйте!")
        return SimpleNamespace(choices=[SimpleNamespace(finish_reason="stop", message=message)], usage=None)

    monkeypatch.setattr(litellm, "acompletion", completion)

    messages = [{"role": "user", "content": "привет"}]
    await Llm(SETTINGS.model_copy(update={"llm_keep_alive": "30m"})).text(messages)
    await Llm(SETTINGS.model_copy(update={"llm_keep_alive": None})).text(messages)

    assert calls[0]["keep_alive"] == "30m"
    assert "keep_alive" not in calls[1]
