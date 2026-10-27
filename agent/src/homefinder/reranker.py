"""bge-reranker-v2-m3 behind llama.cpp's server: how well each fragment answers the question.

Ollama has no reranking, so the model runs in its own llama.cpp server: on the host on a Mac, for Metal, or in the
compose service `reranker` on a Linux server.
"""

import httpx

from homefinder.config import Settings
from homefinder.llm import LlmError


class Reranker:
    def __init__(self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.transport = transport

    async def scores(self, query: str, documents: list[str]) -> list[float]:
        """Score each document against the query, in the order given; higher is better, unbounded logits."""
        if not documents:
            return []
        try:
            async with httpx.AsyncClient(
                base_url=self.settings.reranker_url, timeout=self.settings.reranker_timeout, transport=self.transport
            ) as client:
                response = await client.post("/v1/rerank", json={"query": query, "documents": documents})
                response.raise_for_status()
        except httpx.HTTPError as error:
            raise LlmError(f"The reranker failed: {error}") from error

        scores = [0.0] * len(documents)
        for result in response.json()["results"]:
            scores[result["index"]] = float(result["relevance_score"])
        return scores
