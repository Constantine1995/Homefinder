from typing import Any, ClassVar

import litellm
import pytest
from psycopg import AsyncConnection
from pydantic import SecretStr

from homefinder.config import Settings
from homefinder.llm import Llm, LlmError
from homefinder.search.filters import FlatFilters
from homefinder.search.flats import search_flats
from homefinder.search.wishes import embed_flats, explain_wishes, flat_document
from tests.conftest import Catalog

pytestmark = pytest.mark.anyio

SETTINGS = Settings(token=SecretStr("x" * 16))
DIMENSIONS = SETTINGS.embedding_dimensions


def vector(*weights: float) -> list[float]:
    """A vector whose first components are the weights: «park», «quiet», «south»..."""
    return [*weights, *[0.0] * (DIMENSIONS - len(weights))]


def pgvector(values: list[float]) -> str:
    return "[" + ",".join(map(str, values)) + "]"


class KeywordLlm(Llm):
    """Embeds a text by the words it has: a stand-in for bge-m3 that tests can reason about."""

    WORDS = ("парк", "тих", "юг")

    def __init__(self) -> None:
        super().__init__(SETTINGS)
        self.texts: list[str] = []

    async def embed(self, texts: list[str]) -> list[list[float]]:
        self.texts.extend(texts)
        return [vector(*(1.0 if word in text.lower() else 0.0 for word in self.WORDS)) for text in texts]


async def test_it_puts_the_flats_closest_to_the_wishes_first(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, number="street", price=7_000_000, embedding=pgvector(vector(0, 0, 1)))
    await catalog.flat(tenant_id, building_id, number="park", price=9_000_000, embedding=pgvector(vector(1, 0.2, 0)))
    await catalog.flat(tenant_id, building_id, number="no vector", price=6_000_000)
    await catalog.flat(
        tenant_id, building_id, number="park and quiet", price=8_000_000, embedding=pgvector(vector(1, 1))
    )

    result = await search_flats(connection, tenant_id, FlatFilters(), wishes=vector(1, 1))

    assert [flat.number for flat in result.flats] == ["park and quiet", "park", "street", "no vector"]
    assert result.flats[0].wish_similarity == 1.0
    assert result.flats[2].wish_similarity == 0.0
    assert result.flats[3].wish_similarity is None


async def test_it_orders_by_wishes_among_the_flats_that_pass_the_filters(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, number="park, too dear", price=20_000_000, embedding=pgvector(vector(1)))
    await catalog.flat(tenant_id, building_id, number="street", price=8_000_000, embedding=pgvector(vector(0, 1)))

    result = await search_flats(connection, tenant_id, FlatFilters(price_to=10_000_000), wishes=vector(1))

    assert [flat.number for flat in result.flats] == ["street"]


async def test_it_embeds_the_descriptions_of_the_tenants_flats(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    park_id = await catalog.flat(tenant_id, building_id, description="Окна выходят на парк.")
    bare_id = await catalog.flat(tenant_id, building_id, features='{"window_view": "во двор"}')
    empty_id = await catalog.flat(tenant_id, building_id)
    other_tenant_id = await catalog.tenant("Другой застройщик")
    other_building_id = await catalog.building(other_tenant_id, await catalog.complex(other_tenant_id))
    foreign_id = await catalog.flat(other_tenant_id, other_building_id, description="Чужая квартира у парка.")
    llm = KeywordLlm()

    vectors = await embed_flats(connection, llm, tenant_id, [park_id, bare_id, empty_id, foreign_id])

    assert sorted(vectors) == sorted([park_id, bare_id])
    assert vectors[park_id][:3] == [1.0, 0.0, 0.0]
    assert llm.texts == ["Окна выходят на парк.", "Окна: во двор"]


def test_it_describes_a_flat_without_a_description_by_its_features() -> None:
    features = {"window_view": "во двор", "balcony": "лоджия", "ceiling_height": 2.7, "unknown": "x"}

    assert flat_document(None, features) == "Окна: во двор. Балкон или лоджия: лоджия. Потолки, м: 2.7"
    assert flat_document("  Светлая студия. ", features) == "Светлая студия."
    assert flat_document("", {}) == ""


async def test_it_rejects_vectors_of_another_size(monkeypatch: pytest.MonkeyPatch) -> None:
    class Response:
        data: ClassVar = [{"index": 0, "embedding": [0.1, 0.2]}]

    async def embedding(**options: Any) -> Response:
        return Response()

    monkeypatch.setattr(litellm, "aembedding", embedding)

    with pytest.raises(LlmError, match="size"):
        await Llm(SETTINGS).embed(["текст"])


async def test_it_quotes_the_sentence_that_meets_the_wishes(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(
        tenant_id,
        building_id,
        number="park",
        rooms=2,
        description="Двухкомнатная квартира. Окна выходят на парк. Балкон.",
        embedding=pgvector(vector(1)),
    )
    await catalog.flat(tenant_id, building_id, number="street", rooms=2, description="Окна на улицу.")
    llm = KeywordLlm()
    wishes = vector(1)
    result = await search_flats(connection, tenant_id, FlatFilters(rooms=[2]), wishes=wishes)

    explained = await explain_wishes(llm, wishes, result)

    assert [flat.reasons for flat in explained.flats] == [
        ["2 комнаты", "по описанию: «Окна выходят на парк»"],
        ["2 комнаты"],
    ]
    assert sorted(llm.texts) == sorted(
        ["Двухкомнатная квартира.", "Окна выходят на парк.", "Балкон.", "Окна на улицу."]
    )
