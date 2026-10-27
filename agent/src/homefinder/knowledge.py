"""The `search_knowledge` tool: answers about complexes, buying, mortgage and documents from the knowledge base.

Hybrid retrieval: full-text search with Russian morphology and bge-m3 vectors, fused by
Reciprocal Rank Fusion, then bge-reranker-v2-m3. The corrective step, as in Corrective RAG: the fragments that clear
the reranker's threshold, but not by far, go to the model, which has to quote the sentence that answers the question;
the code checks that the quote is in the fragment. When there is no answer, the question is rewritten once and searched
again; when it still finds nothing, the agent says it will check with a manager rather than make something up.

The reranker alone cannot tell «on topic» from «answers it»: «работает ли офис 31 декабря» scores the opening hours
of the office as high as some true answers score, see evals/knowledge.jsonl.
"""

from typing import Any

from psycopg import AsyncConnection
from psycopg.rows import dict_row
from pydantic import BaseModel

from homefinder.llm import Llm
from homefinder.reranker import Reranker
from homefinder.search.flats import to_vector

CANDIDATES_PER_RETRIEVER = 20
"""Fragments each retriever proposes before fusion."""

RERANKED = 10
"""Fused fragments the reranker scores: the slow step, a few hundred milliseconds per fragment on a CPU."""

RESULTS = 5

RRF_K = 60
"""The constant of Reciprocal Rank Fusion: 1 / (k + rank) from each retriever."""

RELEVANCE_THRESHOLD = -4.5
"""The reranker score a fragment needs at all: a loose cut that keeps nearly every true answer and drops what is
plainly off topic; set by the question set, see evals/knowledge.jsonl."""

ACCEPT_SCORE = 0.0
"""From this score of the best fragment on, it answers without the model's check: no question without an answer in
the set came within 2 of it. The check costs a call of the model, seconds on a laptop."""


class KnowledgeChunk(BaseModel):
    id: int
    document_id: int
    title: str
    source: str
    """Where to read more: the page or file the fragment comes from."""
    section: str
    text: str
    score: float
    """The reranker's score; fragments below RELEVANCE_THRESHOLD are not returned."""


class RankedSection(BaseModel):
    source: str
    section: str
    score: float


class KnowledgeResult(BaseModel):
    found: bool
    """False means: say «уточню у менеджера», do not answer from the model's own knowledge."""
    query: str
    """The query the fragments were found by: the question, or its rewrite."""
    rewritten: bool
    chunks: list[KnowledgeChunk]
    """The fragments that answer: those the reranker is sure of, or the one the model quoted."""
    quote: str | None = None
    """The sentence the model found the answer in, when it was asked; the code checked it is in the first fragment."""
    ranking: list[RankedSection] = []
    """How the reranker scored the candidates for the question as asked, above the threshold or not: for traces
    and for calibrating the threshold, not for the model's answer."""


class _Rewrite(BaseModel):
    query: str


class _Answer(BaseModel):
    fragment: int = 0
    """The number of the fragment with the answer, as numbered in the prompt; 0 when none has it."""
    quote: str = ""


REWRITE_PROMPT = """\
Перепиши вопрос покупателя квартиры в короткий поисковый запрос к базе знаний застройщика: \
главные слова темы, без приветствий и вежливых слов, раскрой разговорные слова («машиноместо» — «паркинг», \
«садик» — «детский сад», «коммуналка» — «управляющая компания»). Пиши по-русски кириллицей. \
Не добавляй тем, которых нет в вопросе. Ответь JSON: {"query": "..."}."""

# A small model asked for «the numbers of the fragments that answer» returned none at all for half of the true
# answers; asked to quote the answer, it finds most of them, and a quote can be checked.
ANSWER_PROMPT = """\
Ты проверяешь, есть ли в базе знаний застройщика ответ на вопрос покупателя квартиры. \
Найди во фрагментах предложение, которое отвечает на вопрос, и перепиши его дословно. \
Если фрагмент только на ту же тему, а нужного факта в нём нет, это не ответ. \
Ответь JSON: {"fragment": номер фрагмента, "quote": "дословная цитата"}; \
если ответа нет — {"fragment": 0, "quote": ""}."""


_SEARCH = """
    with terms as (
        -- Any of the question's words, not all of them: a buyer's question has more words than the answer.
        select replace(plainto_tsquery('russian', %(query)s)::text, '&', '|')::tsquery as q
    ), fts as (
        select c.id, row_number() over (order by ts_rank_cd(c.search, terms.q) desc, c.id) as rank
        from kb_chunks c
        join kb_documents d on d.id = c.kb_document_id
        cross join terms
        where c.tenant_id = %(tenant_id)s and c.search @@ terms.q and {complex}
        order by rank
        limit %(candidates)s
    ), nearest as (
        -- A plain nearest-neighbour query, so that it runs on the HNSW index once the knowledge base is large.
        select c.id, c.embedding <=> %(vector)s::vector as distance
        from kb_chunks c
        join kb_documents d on d.id = c.kb_document_id
        where c.tenant_id = %(tenant_id)s and c.embedding is not null and {complex}
        order by c.embedding <=> %(vector)s::vector
        limit %(candidates)s
    ), vectors as (
        select id, row_number() over (order by distance, id) as rank from nearest
    )
    select
        c.id, d.id as document_id, d.title, d.source, c.section, c.text,
        coalesce(1.0 / (%(k)s + fts.rank), 0) + coalesce(1.0 / (%(k)s + vectors.rank), 0) as fused
    from kb_chunks c
    join kb_documents d on d.id = c.kb_document_id
    left join fts on fts.id = c.id
    left join vectors on vectors.id = c.id
    where c.id in (select id from fts union select id from vectors)
    order by fused desc, c.id
    limit %(reranked)s
"""

# A question about a complex is answered from its documents and from the developer's general ones.
_ANY_COMPLEX = "true"
_THIS_COMPLEX = "(d.complex_id is null or d.complex_id = %(complex_id)s)"


async def search_knowledge(
    connection: AsyncConnection[Any],
    llm: Llm,
    reranker: Reranker,
    tenant_id: int,
    question: str,
    complex_id: int | None = None,
    threshold: float = RELEVANCE_THRESHOLD,
) -> KnowledgeResult:
    """Find the fragments that answer the question, rewriting it once when nothing clears the threshold."""
    ranked = await rank(connection, llm, reranker, tenant_id, question, complex_id)
    ranking = [
        RankedSection(source=row["source"], section=row["section"], score=round(score, 3)) for score, row in ranked
    ]
    chunks, quote = await answering(llm, question, _relevant(ranked, threshold))
    if chunks:
        return KnowledgeResult(found=True, query=question, rewritten=False, chunks=chunks, quote=quote, ranking=ranking)

    rewrite = await llm.structured(
        [{"role": "system", "content": REWRITE_PROMPT}, {"role": "user", "content": question}], _Rewrite
    )
    query = rewrite.query.strip() or question
    ranked = await rank(connection, llm, reranker, tenant_id, query, complex_id)
    # The buyer's own question decides what answers it: the rewrite only finds other fragments.
    chunks, quote = await answering(llm, question, _relevant(ranked, threshold))

    return KnowledgeResult(found=bool(chunks), query=query, rewritten=True, chunks=chunks, quote=quote, ranking=ranking)


async def candidates(
    connection: AsyncConnection[Any], llm: Llm, tenant_id: int, query: str, complex_id: int | None
) -> list[dict[str, Any]]:
    """Fuse the full-text and vector rankings of the tenant's fragments, best first."""
    [vector] = await llm.embed([query])
    sql = _SEARCH.format(complex=_ANY_COMPLEX if complex_id is None else _THIS_COMPLEX)
    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(
            sql,
            {
                "tenant_id": tenant_id,
                "query": query,
                "vector": to_vector(vector),
                "complex_id": complex_id,
                "candidates": CANDIDATES_PER_RETRIEVER,
                "reranked": RERANKED,
                "k": RRF_K,
            },
        )
        return await cursor.fetchall()


def _relevant(ranked: list[tuple[float, dict[str, Any]]], threshold: float) -> list[KnowledgeChunk]:
    return [
        KnowledgeChunk(
            id=row["id"],
            document_id=row["document_id"],
            title=row["title"],
            source=row["source"],
            section=row["section"],
            text=row["text"],
            score=round(score, 3),
        )
        for score, row in ranked[:RESULTS]
        if score >= threshold
    ]


async def answering(llm: Llm, question: str, chunks: list[KnowledgeChunk]) -> tuple[list[KnowledgeChunk], str | None]:
    """The corrective step's check: the fragments that answer, or none, and the model's quote when it was asked."""
    if not chunks:
        return [], None
    if chunks[0].score >= ACCEPT_SCORE:
        return [chunk for chunk in chunks if chunk.score >= ACCEPT_SCORE], None
    fragments = "\n\n".join(
        f"[{number}] {document_text(chunk.section, chunk.text)}" for number, chunk in enumerate(chunks, 1)
    )
    answer = await llm.structured(
        [
            {"role": "system", "content": ANSWER_PROMPT},
            {"role": "user", "content": f"Вопрос: {question}\n\nФрагменты:\n\n{fragments}"},
        ],
        _Answer,
    )
    if not 1 <= answer.fragment <= len(chunks) or not _quotes(chunks[answer.fragment - 1], answer.quote):
        return [], None
    return [chunks[answer.fragment - 1]], answer.quote.strip()


def _quotes(chunk: KnowledgeChunk, quote: str) -> bool:
    """Whether the quote is the fragment's own words: case, spacing, quotation marks and end punctuation aside."""
    wanted = _plain(quote)
    return len(wanted) >= 10 and wanted in _plain(document_text(chunk.section, chunk.text))


def _plain(text: str) -> str:
    return " ".join(text.lower().replace("ё", "е").translate(_MARKS).split()).strip(" .!?;:")


_MARKS = str.maketrans("", "", "«»\"'“”„")


async def rank(
    connection: AsyncConnection[Any],
    llm: Llm,
    reranker: Reranker,
    tenant_id: int,
    query: str,
    complex_id: int | None,
) -> list[tuple[float, dict[str, Any]]]:
    """Score the fused candidates with the reranker, best first."""
    rows = await candidates(connection, llm, tenant_id, query, complex_id)
    scores = await reranker.scores(query, [document_text(row["section"], row["text"]) for row in rows])
    return sorted(zip(scores, rows, strict=True), key=lambda pair: -pair[0])


def document_text(section: str, text: str) -> str:
    """The text a fragment is embedded and reranked by: its headings name the complex and the topic."""
    return f"{section}\n{text}"


async def embed_chunks(
    connection: AsyncConnection[Any], llm: Llm, tenant_id: int, chunk_ids: list[int]
) -> dict[int, list[float]]:
    """Compute the vectors of the tenant's fragments for Laravel to store."""
    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(
            "select id, section, text from kb_chunks where tenant_id = %s and id = any(%s) order by id",
            (tenant_id, chunk_ids),
        )
        rows = await cursor.fetchall()

    vectors = await llm.embed([document_text(row["section"], row["text"]) for row in rows])
    return {row["id"]: vector for row, vector in zip(rows, vectors, strict=True)}
