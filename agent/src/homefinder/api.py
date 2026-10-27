"""HTTP API of the service, called by Laravel over the internal network."""

import secrets
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import date, datetime
from typing import Annotated, Any
from zoneinfo import ZoneInfo

from fastapi import APIRouter, Depends, FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from psycopg import AsyncConnection
from pydantic import BaseModel, ConfigDict, Field

from homefinder.agent.graph import TurnResult, run_turn
from homefinder.agent.state import DialogState, Turn
from homefinder.agent.tools import ToolContext
from homefinder.config import Settings, get_settings
from homefinder.db import create_pool
from homefinder.knowledge import KnowledgeResult, embed_chunks, search_knowledge
from homefinder.llm import Llm, LlmError
from homefinder.reranker import Reranker
from homefinder.search.complexes import complexes_by_name
from homefinder.search.filters import FlatFilters
from homefinder.search.flats import DEFAULT_LIMIT, FlatSearchResult, search_flats
from homefinder.search.relax import Relaxation, relax_filters
from homefinder.search.self_query import SelfQueryResult, parse_query
from homefinder.search.wishes import embed_flats, explain_wishes

_bearer = HTTPBearer(auto_error=False)

router = APIRouter()


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or get_settings()

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        pool = create_pool(settings)
        await pool.open()
        app.state.pool = pool
        try:
            yield
        finally:
            await pool.close()

    app = FastAPI(title="HomeFinder search", lifespan=lifespan)
    app.state.settings = settings
    app.state.llm = Llm(settings)
    app.state.reranker = Reranker(settings)
    app.include_router(router)
    app.add_exception_handler(LlmError, _model_unavailable)

    return app


async def _model_unavailable(request: Request, error: Exception) -> JSONResponse:
    """Answer 503 when a model is down, so Laravel can hand the buyer over to a manager."""
    return JSONResponse({"detail": "The language model is unavailable"}, status.HTTP_503_SERVICE_UNAVAILABLE)


async def get_connection(request: Request) -> AsyncIterator[AsyncConnection[Any]]:
    async with request.app.state.pool.connection() as connection:
        yield connection


def authorize(request: Request, credentials: Annotated[HTTPAuthorizationCredentials | None, Depends(_bearer)]) -> None:
    """Let in Laravel only: it sends the shared service token."""
    expected: str = request.app.state.settings.token.get_secret_value()
    if credentials is None or not secrets.compare_digest(credentials.credentials.encode(), expected.encode()):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, headers={"WWW-Authenticate": "Bearer"})


def get_llm(request: Request) -> Llm:
    llm: Llm = request.app.state.llm
    return llm


def get_reranker(request: Request) -> Reranker:
    reranker: Reranker = request.app.state.reranker
    return reranker


def get_today(request: Request) -> date:
    return datetime.now(ZoneInfo(request.app.state.settings.timezone)).date()


Connection = Annotated[AsyncConnection[Any], Depends(get_connection)]
Model = Annotated[Llm, Depends(get_llm)]
Today = Annotated[date, Depends(get_today)]
Ranker = Annotated[Reranker, Depends(get_reranker)]


class FlatSearchRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    """The developer whose flats are searched; Laravel takes it from the channel or the employee, never from a model."""
    filters: FlatFilters = FlatFilters()
    wishes: Annotated[str, Field(max_length=500)] | None = None
    """Soft wishes in the buyer's words; the flats closest to them come first."""
    limit: Annotated[int, Field(ge=1, le=50)] = DEFAULT_LIMIT


class FlatEmbeddingsRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    flat_ids: Annotated[list[int], Field(min_length=1, max_length=100)]


class FlatEmbedding(BaseModel):
    id: int
    embedding: list[float]


class FlatEmbeddings(BaseModel):
    model: str
    flats: list[FlatEmbedding]


class KnowledgeEmbeddingsRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    chunk_ids: Annotated[list[int], Field(min_length=1, max_length=100)]


class ChunkEmbedding(BaseModel):
    id: int
    embedding: list[float]


class KnowledgeEmbeddings(BaseModel):
    model: str
    chunks: list[ChunkEmbedding]


class KnowledgeSearchRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    question: Annotated[str, Field(min_length=1, max_length=2000)]
    complex_id: int | None = None


class ChatRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    """The developer of the channel the buyer writes to; never taken from the model."""
    dialog: DialogState = DialogState()
    """The state the previous turn returned; Laravel keeps it with the conversation."""
    history: Annotated[list[Turn], Field(max_length=50)] = []
    message: Annotated[str, Field(min_length=1, max_length=4000)]
    cards: bool = False
    """The channel shows the flats found as cards with the plan and the price, as Telegram does: the reply then
    does not list them again."""


class FlatParseRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tenant_id: int
    text: Annotated[str, Field(min_length=1, max_length=2000)]


class FlatQueryRequest(FlatParseRequest):
    limit: Annotated[int, Field(ge=1, le=50)] = DEFAULT_LIMIT


class FlatQueryResult(BaseModel):
    """A free-text search: what was understood, what was found and, when nothing was, what to ease."""

    query: SelfQueryResult
    result: FlatSearchResult
    relaxations: list[Relaxation]


@router.get("/health")
async def health(connection: Connection) -> dict[str, str]:
    await connection.execute("select 1")
    return {"status": "ok"}


@router.post("/v1/flats/search", dependencies=[Depends(authorize)])
async def flats_search(payload: FlatSearchRequest, connection: Connection, llm: Model) -> FlatSearchResult:
    wishes = (await llm.embed([payload.wishes]))[0] if payload.wishes and payload.wishes.strip() else None
    result = await search_flats(connection, payload.tenant_id, payload.filters, payload.limit, wishes)
    return result if wishes is None else await explain_wishes(llm, wishes, result)


@router.post("/v1/flats/parse", dependencies=[Depends(authorize)])
async def flats_parse(payload: FlatParseRequest, connection: Connection, llm: Model, today: Today) -> SelfQueryResult:
    """Turn a buyer's message into search filters."""
    complexes = await complexes_by_name(connection, payload.tenant_id)
    return await parse_query(llm, payload.text, complexes, today)


@router.post("/v1/flats/embeddings", dependencies=[Depends(authorize)])
async def flats_embeddings(payload: FlatEmbeddingsRequest, connection: Connection, llm: Model) -> FlatEmbeddings:
    """Compute the vectors of the flats for Laravel to store: the service itself never writes."""
    vectors = await embed_flats(connection, llm, payload.tenant_id, payload.flat_ids)
    return FlatEmbeddings(
        model=llm.settings.embedding_model,
        flats=[FlatEmbedding(id=flat_id, embedding=vector) for flat_id, vector in vectors.items()],
    )


@router.post("/v1/flats/query", dependencies=[Depends(authorize)])
async def flats_query(payload: FlatQueryRequest, connection: Connection, llm: Model, today: Today) -> FlatQueryResult:
    """Search by a buyer's message: the free-text search demo, and the path the agent's tools take."""
    complexes = await complexes_by_name(connection, payload.tenant_id)
    query = await parse_query(llm, payload.text, complexes, today)
    wishes = (await llm.embed([query.wishes]))[0] if query.wishes else None
    result = await search_flats(connection, payload.tenant_id, query.filters, payload.limit, wishes)
    if wishes is not None:
        result = await explain_wishes(llm, wishes, result)
    relaxations = [] if result.total else await relax_filters(connection, payload.tenant_id, query.filters)

    return FlatQueryResult(query=query, result=result, relaxations=relaxations)


@router.post("/v1/knowledge/embeddings", dependencies=[Depends(authorize)])
async def knowledge_embeddings(
    payload: KnowledgeEmbeddingsRequest, connection: Connection, llm: Model
) -> KnowledgeEmbeddings:
    """Compute the vectors of knowledge base fragments for Laravel to store."""
    vectors = await embed_chunks(connection, llm, payload.tenant_id, payload.chunk_ids)
    return KnowledgeEmbeddings(
        model=llm.settings.embedding_model,
        chunks=[ChunkEmbedding(id=chunk_id, embedding=vector) for chunk_id, vector in vectors.items()],
    )


@router.post("/v1/knowledge/search", dependencies=[Depends(authorize)])
async def knowledge_search(
    payload: KnowledgeSearchRequest, connection: Connection, llm: Model, reranker: Ranker
) -> KnowledgeResult:
    """Answer a question from the knowledge base: the search_knowledge tool, open to Laravel for the cabinet."""
    return await search_knowledge(connection, llm, reranker, payload.tenant_id, payload.question, payload.complex_id)


@router.post("/v1/chat", dependencies=[Depends(authorize)])
async def chat(payload: ChatRequest, connection: Connection, llm: Model, reranker: Ranker, today: Today) -> TurnResult:
    """Answer one message of a buyer: the agent's turn, with the steps and the check for the agent_runs log."""
    context = ToolContext(
        connection=connection,
        llm=llm,
        reranker=reranker,
        tenant_id=payload.tenant_id,
        today=today,
        complexes=await complexes_by_name(connection, payload.tenant_id),
        cards=payload.cards,
    )
    return await run_turn(context, payload.dialog, payload.history, payload.message)
