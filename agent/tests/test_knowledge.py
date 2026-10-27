import json
import re
from typing import Any

import httpx
import pytest
from psycopg import AsyncConnection
from pydantic import BaseModel, SecretStr

from homefinder.config import Settings
from homefinder.knowledge import ANSWER_PROMPT, RELEVANCE_THRESHOLD, embed_chunks, search_knowledge
from homefinder.llm import LlmError
from homefinder.reranker import Reranker
from tests.conftest import Catalog
from tests.test_wishes import KeywordLlm, pgvector, vector

pytestmark = pytest.mark.anyio

SETTINGS = Settings(token=SecretStr("x" * 16))


class KeywordReranker(Reranker):
    """Scores a fragment by how many of the query's words it has: a stand-in for bge-reranker-v2-m3."""

    def __init__(self) -> None:
        super().__init__(SETTINGS)
        self.queries: list[str] = []

    async def scores(self, query: str, documents: list[str]) -> list[float]:
        self.queries.append(query)
        words = [word for word in query.lower().split() if len(word) > 3]
        return [float(sum(word[:5] in document.lower() for word in words)) - 0.5 for document in documents]


class RewritingLlm(KeywordLlm):
    """Embeds by keywords, rewrites a question into the given query and, asked to check, quotes the given answer."""

    def __init__(self, rewrite: str, quote: str = "", fragment: int | None = None) -> None:
        super().__init__()
        self.rewrite = rewrite
        self.quote = quote
        self.fragment = fragment
        """The fragment the quote is said to be from; by default the one that has it, or none."""
        self.asked: list[list[dict[str, str]]] = []
        self.checked: list[str] = []

    async def structured[T: BaseModel](
        self, messages: list[dict[str, str]], schema: type[T], max_tokens: int | None = None
    ) -> T:
        if messages[0]["content"] == ANSWER_PROMPT:
            self.checked.append(messages[-1]["content"])
            fragments = re.findall(r"^\[(\d+)\] (.*?)(?=^\[\d+\] |\Z)", messages[-1]["content"], re.S | re.M)
            found = next(
                (int(number) for number, text in fragments if self.quote and self.quote.lower() in text.lower()), 0
            )
            return schema.model_validate({"fragment": self.fragment or found, "quote": self.quote})
        self.asked.append(messages)
        return schema.model_validate({"query": self.rewrite})


async def knowledge_base(catalog: Catalog) -> dict[str, int]:
    tenant_id = await catalog.tenant()
    otradny_id = await catalog.complex(tenant_id, "Отрадный квартал")
    river_id = await catalog.complex(tenant_id, "Речная терраса")
    buying = await catalog.document(tenant_id, "Покупка", "https://demo.example/faq/buying")
    otradny = await catalog.document(tenant_id, "Отрадный", "https://demo.example/otradny", otradny_id)
    river = await catalog.document(tenant_id, "Речная терраса", "https://demo.example/river-terrace", river_id)
    await catalog.chunk(tenant_id, buying, "Покупка › Бронирование", "Бронь стоит 50 000 ₽.", pgvector(vector()))
    await catalog.chunk(tenant_id, otradny, "Отрадный › Паркинг", "Подземный паркинг на 310 мест.", pgvector(vector(1)))
    await catalog.chunk(tenant_id, river, "Речная терраса › Инфраструктура", "Бассейн 25 м.", pgvector(vector(0, 1)))
    other_tenant_id = await catalog.tenant("Другой застройщик")
    other = await catalog.document(other_tenant_id, "Чужой", "https://other.example/parking")
    await catalog.chunk(other_tenant_id, other, "Чужой › Паркинг", "Чужой паркинг бесплатный.", pgvector(vector(1)))
    return {"tenant": tenant_id, "otradny": otradny_id, "river": river_id}


async def test_it_answers_from_the_fragments_the_reranker_finds_relevant(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)

    llm = RewritingLlm("unused")

    result = await search_knowledge(connection, llm, KeywordReranker(), ids["tenant"], "Есть подземный паркинг?")

    assert result.found
    assert llm.checked == []
    assert not result.rewritten
    assert [(chunk.section, chunk.source) for chunk in result.chunks] == [
        ("Отрадный › Паркинг", "https://demo.example/otradny")
    ]
    assert result.chunks[0].score == 1.5
    assert result.quote is None


async def test_it_answers_from_a_doubtful_fragment_when_the_model_quotes_the_answer_from_it(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)
    llm = RewritingLlm("unused", quote="подземный паркинг на 310 мест")

    result = await search_knowledge(connection, llm, KeywordReranker(), ids["tenant"], "где оставить машину?")

    assert result.found
    assert not result.rewritten
    assert [chunk.section for chunk in result.chunks] == ["Отрадный › Паркинг"]
    assert result.quote == "подземный паркинг на 310 мест"
    assert "Вопрос: где оставить машину?" in llm.checked[0]


async def test_it_does_not_trust_a_quote_the_fragment_does_not_have(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)
    llm = RewritingLlm("машина", quote="Паркинг бесплатный для жильцов", fragment=1)

    result = await search_knowledge(connection, llm, KeywordReranker(), ids["tenant"], "где оставить машину?")

    assert not result.found
    assert result.chunks == []
    assert len(llm.checked) == 2


async def test_it_searches_the_complex_and_the_whole_developer_only(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)
    llm = RewritingLlm("бассейн бронь")

    result = await search_knowledge(
        connection, llm, KeywordReranker(), ids["tenant"], "бассейн и бронь", complex_id=ids["otradny"]
    )

    assert [chunk.section for chunk in result.chunks] == ["Покупка › Бронирование"]


async def test_it_rewrites_the_question_once_when_nothing_is_relevant(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)
    llm, reranker = RewritingLlm("паркинг машиноместо"), KeywordReranker()

    result = await search_knowledge(connection, llm, reranker, ids["tenant"], "где оставить машину?")

    assert result.found
    assert result.rewritten
    assert result.query == "паркинг машиноместо"
    assert [chunk.section for chunk in result.chunks] == ["Отрадный › Паркинг"]
    assert reranker.queries == ["где оставить машину?", "паркинг машиноместо"]
    assert llm.asked[0][-1] == {"role": "user", "content": "где оставить машину?"}


async def test_it_says_it_found_nothing_rather_than_answer_off_topic(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await knowledge_base(catalog)
    reranker = KeywordReranker()

    result = await search_knowledge(
        connection, RewritingLlm("стоимость коммунальных услуг"), reranker, ids["tenant"], "какая плата за коммуналку?"
    )

    assert not result.found
    assert result.chunks == []
    assert len(reranker.queries) == 2


async def test_it_never_finds_another_developers_fragments(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await knowledge_base(catalog)
    other_tenant_id = ids["tenant"] + 1

    result = await search_knowledge(
        connection, RewritingLlm("паркинг"), KeywordReranker(), other_tenant_id, "Есть подземный паркинг?"
    )

    assert [chunk.source for chunk in result.chunks] == ["https://other.example/parking"]


async def test_it_embeds_the_fragments_with_their_headings(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await knowledge_base(catalog)
    llm = KeywordLlm()
    chunk_ids = [row[0] for row in await (await connection.execute("select id from kb_chunks order by id")).fetchall()]

    vectors = await embed_chunks(connection, llm, ids["tenant"], chunk_ids)

    assert len(vectors) == 3
    assert "Отрадный › Паркинг\nПодземный паркинг на 310 мест." in llm.texts


async def test_the_reranker_scores_documents_in_their_order() -> None:
    def answer(request: httpx.Request) -> httpx.Response:
        assert json.loads(request.content) == {"query": "паркинг", "documents": ["бронь", "паркинг"]}
        return httpx.Response(
            200, json={"results": [{"index": 1, "relevance_score": 3.5}, {"index": 0, "relevance_score": -9.0}]}
        )

    reranker = Reranker(SETTINGS, transport=httpx.MockTransport(answer))

    assert await reranker.scores("паркинг", ["бронь", "паркинг"]) == [-9.0, 3.5]


async def test_a_reranker_that_is_down_is_reported() -> None:
    reranker = Reranker(SETTINGS, transport=httpx.MockTransport(lambda request: httpx.Response(503)))

    with pytest.raises(LlmError, match="reranker"):
        await reranker.scores("паркинг", ["бронь"])


def test_the_threshold_is_a_reranker_score() -> None:
    assert -10 < RELEVANCE_THRESHOLD < 10
