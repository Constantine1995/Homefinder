from collections.abc import AsyncIterator
from datetime import date
from typing import Any

import pytest
from httpx import ASGITransport, AsyncClient
from psycopg import AsyncConnection
from pydantic import SecretStr

from homefinder.api import create_app, get_connection, get_llm, get_today
from homefinder.config import Settings
from homefinder.llm import Llm
from tests.conftest import Catalog
from tests.test_wishes import KeywordLlm, pgvector, vector

pytestmark = pytest.mark.anyio

TOKEN = "test-token-of-sixteen-chars"
SETTINGS = Settings(token=SecretStr(TOKEN))
AUTHORIZATION = {"Authorization": f"Bearer {TOKEN}"}


class ModelAnswer:
    """What the mocked model answers; the tests set it before calling the API."""

    content = "{}"


@pytest.fixture
async def client(connection: AsyncConnection[Any]) -> AsyncIterator[AsyncClient]:
    app = create_app(SETTINGS)

    async def test_connection() -> AsyncIterator[AsyncConnection[Any]]:
        yield connection

    app.dependency_overrides[get_connection] = test_connection
    app.dependency_overrides[get_llm] = lambda: Llm(SETTINGS, mock_response=ModelAnswer.content)
    app.dependency_overrides[get_today] = lambda: date(2026, 10, 20)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://agent") as client:
        yield client


async def test_it_searches_flats_for_laravel(client: AsyncClient, catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id, "Отрадный квартал"))
    await catalog.flat(tenant_id, building_id, number="12", rooms=2, price=8_900_000)
    await catalog.flat(tenant_id, building_id, number="13", rooms=2, price=9_900_000)

    response = await client.post(
        "/v1/flats/search",
        json={"tenant_id": tenant_id, "filters": {"rooms": [2], "price_to": 9_000_000}},
        headers=AUTHORIZATION,
    )

    assert response.status_code == 200
    assert response.json()["total"] == 1
    [flat] = response.json()["flats"]
    assert flat["number"] == "12"
    assert flat["complex_name"] == "Отрадный квартал"
    assert flat["reasons"] == ["2 комнаты", "8,9 млн ₽ — в бюджете до 9 млн ₽"]


@pytest.mark.parametrize("headers", [{}, {"Authorization": "Bearer wrong"}], ids=["no token", "wrong token"])
async def test_it_lets_in_laravel_only(client: AsyncClient, headers: dict[str, str]) -> None:
    response = await client.post("/v1/flats/search", json={"tenant_id": 1}, headers=headers)

    assert response.status_code == 401


@pytest.mark.parametrize(
    "payload",
    [
        pytest.param({"filters": {}}, id="no tenant"),
        pytest.param({"tenant_id": 1, "filters": {"rooms": [9]}}, id="rooms out of range"),
        pytest.param({"tenant_id": 1, "filters": {"price_to": -1}}, id="negative price"),
        pytest.param({"tenant_id": 1, "filters": {"finishings": ["marble"]}}, id="unknown finishing"),
        pytest.param({"tenant_id": 1, "filters": {"view": "park"}}, id="unknown filter"),
        pytest.param({"tenant_id": 1, "limit": 500}, id="too many flats"),
    ],
)
async def test_it_rejects_invalid_searches(client: AsyncClient, payload: dict[str, Any]) -> None:
    response = await client.post("/v1/flats/search", json=payload, headers=AUTHORIZATION)

    assert response.status_code == 422


async def test_it_reports_its_health(client: AsyncClient) -> None:
    response = await client.get("/health")

    assert response.json() == {"status": "ok"}


async def test_it_turns_a_message_into_filters_with_the_tenants_complexes(
    client: AsyncClient, catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    complex_id = await catalog.complex(tenant_id, "Отрадный квартал")
    other_tenant_id = await catalog.tenant("Другой застройщик")
    await catalog.complex(other_tenant_id, "Речная терраса")
    ModelAnswer.content = '{"complexes": ["Отрадный квартал", "Речная терраса"], "rooms": [2], "ready": true}'

    response = await client.post(
        "/v1/flats/parse", json={"tenant_id": tenant_id, "text": "двушка в готовом доме"}, headers=AUTHORIZATION
    )

    assert response.status_code == 200
    assert response.json()["filters"]["complex_ids"] == [complex_id]
    assert response.json()["filters"]["rooms"] == [2]
    assert response.json()["filters"]["deadline_to"] == "2026-10-20"
    assert response.json()["unknown_complexes"] == ["Речная терраса"]


async def test_it_reports_a_model_that_answers_nonsense_as_unavailable(client: AsyncClient) -> None:
    ModelAnswer.content = "not json"

    response = await client.post("/v1/flats/parse", json={"tenant_id": 1, "text": "двушка"}, headers=AUTHORIZATION)

    assert response.status_code == 503


@pytest.mark.parametrize("payload", [{"tenant_id": 1, "text": ""}, {"text": "двушка"}], ids=["empty", "no tenant"])
async def test_it_rejects_invalid_messages(client: AsyncClient, payload: dict[str, Any]) -> None:
    response = await client.post("/v1/flats/parse", json=payload, headers=AUTHORIZATION)

    assert response.status_code == 422


async def test_it_parses_for_laravel_only(client: AsyncClient) -> None:
    response = await client.post("/v1/flats/parse", json={"tenant_id": 1, "text": "двушка"})

    assert response.status_code == 401


@pytest.fixture
def keyword_client(client: AsyncClient) -> AsyncClient:
    """The client of an app whose embedding model is KeywordLlm."""
    app: Any = client._transport.app  # type: ignore[attr-defined]
    app.dependency_overrides[get_llm] = KeywordLlm
    return client


async def test_it_computes_the_vectors_of_flats_for_laravel(keyword_client: AsyncClient, catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    flat_id = await catalog.flat(tenant_id, building_id, description="Тихий двор, окна на юг.")

    response = await keyword_client.post(
        "/v1/flats/embeddings", json={"tenant_id": tenant_id, "flat_ids": [flat_id]}, headers=AUTHORIZATION
    )

    assert response.status_code == 200
    assert response.json()["model"] == "ollama/bge-m3"
    [flat] = response.json()["flats"]
    assert flat["id"] == flat_id
    assert flat["embedding"][:4] == [0.0, 1.0, 1.0, 0.0]


@pytest.mark.parametrize("flat_ids", [[], list(range(101))], ids=["none", "too many"])
async def test_it_embeds_up_to_a_hundred_flats_at_a_time(client: AsyncClient, flat_ids: list[int]) -> None:
    response = await client.post(
        "/v1/flats/embeddings", json={"tenant_id": 1, "flat_ids": flat_ids}, headers=AUTHORIZATION
    )

    assert response.status_code == 422


async def test_it_puts_the_flats_closest_to_the_wishes_first(keyword_client: AsyncClient, catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, number="street", price=7_000_000, embedding=pgvector(vector(0, 0, 1)))
    await catalog.flat(tenant_id, building_id, number="park", price=9_000_000, embedding=pgvector(vector(1)))

    response = await keyword_client.post(
        "/v1/flats/search", json={"tenant_id": tenant_id, "wishes": "вид на парк"}, headers=AUTHORIZATION
    )

    assert [flat["number"] for flat in response.json()["flats"]] == ["park", "street"]


def understanding(answer: str) -> type[KeywordLlm]:
    """A model that answers every message with the given filters and embeds by keywords."""

    class Understanding(KeywordLlm):
        def __init__(self) -> None:
            super().__init__()
            self.completion_options = {"mock_response": answer}

    return Understanding


async def test_it_searches_by_a_message(client: AsyncClient, catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id, "Отрадный квартал"))
    await catalog.flat(
        tenant_id, building_id, number="street", rooms=2, price=8_000_000, embedding=pgvector(vector(0, 1))
    )
    await catalog.flat(tenant_id, building_id, number="park", rooms=2, price=8_500_000, embedding=pgvector(vector(1)))
    await catalog.flat(tenant_id, building_id, number="dear", rooms=2, price=9_500_000, embedding=pgvector(vector(1)))
    app: Any = client._transport.app  # type: ignore[attr-defined]
    app.dependency_overrides[get_llm] = understanding('{"rooms": [2], "price_max_mln": 9, "wishes": "вид на парк"}')

    response = await client.post(
        "/v1/flats/query",
        json={"tenant_id": tenant_id, "text": "двушка до 9 млн с видом на парк"},
        headers=AUTHORIZATION,
    )

    assert response.status_code == 200
    assert response.json()["query"]["wishes"] == "вид на парк"
    assert [flat["number"] for flat in response.json()["result"]["flats"]] == ["park", "street"]
    assert response.json()["relaxations"] == []


async def test_it_suggests_what_to_ease_when_nothing_fits(client: AsyncClient, catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, rooms=2, price=9_400_000)
    app: Any = client._transport.app  # type: ignore[attr-defined]
    app.dependency_overrides[get_llm] = understanding('{"rooms": [2], "price_max_mln": 9}')

    response = await client.post(
        "/v1/flats/query", json={"tenant_id": tenant_id, "text": "двушка до 9 млн"}, headers=AUTHORIZATION
    )

    assert response.json()["result"]["total"] == 0
    assert [relaxation["description"] for relaxation in response.json()["relaxations"]] == ["до 9,5 млн ₽ — 1 квартира"]
