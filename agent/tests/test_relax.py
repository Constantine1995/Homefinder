from typing import Any

import pytest
from psycopg import AsyncConnection

from homefinder.search.filters import FlatFilters, PoiType
from homefinder.search.relax import relax_filters
from tests.conftest import Catalog
from tests.test_search_flats import two_complexes

pytestmark = pytest.mark.anyio


async def descriptions(connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters) -> list[str]:
    return [relaxation.description for relaxation in await relax_filters(connection, tenant_id, filters)]


async def test_it_eases_each_filter_by_its_smallest_step_that_finds_flats(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    complexes = await two_complexes(catalog)
    other_tenant_id = await catalog.tenant("Другой застройщик")
    other_building_id = await catalog.building(other_tenant_id, await catalog.complex(other_tenant_id))
    await catalog.flat(other_tenant_id, other_building_id, rooms=2, price=10_200_000)

    relaxations = await relax_filters(connection, complexes.tenant_id, FlatFilters(rooms=[2], price_to=10_000_000))

    assert [relaxation.description for relaxation in relaxations] == [
        "до 10,5 млн ₽ — 1 квартира",
        "1 или 2 комнаты — 1 квартира",
    ]
    assert relaxations[0].field == "price_to"
    assert relaxations[0].filters == FlatFilters(rooms=[2], price_to=10_500_000)
    assert relaxations[1].filters == FlatFilters(rooms=[1, 2], price_to=10_000_000)


async def test_it_offers_the_gentlest_easing_first(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    found = await descriptions(connection, complexes.tenant_id, FlatFilters(rooms=[3], metro_walk_to=10))

    assert found == ["2 или 3 комнаты — 1 квартира", "до метро до 30 мин пешком — 1 квартира"]


async def test_it_drops_a_place_that_should_be_nearby(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    found = await descriptions(connection, complexes.tenant_id, FlatFilters(rooms=[1], nearby=[PoiType.SHOP]))

    assert found == ["без условия «рядом магазин» — 1 квартира"]


@pytest.mark.parametrize(
    ("filters", "expected"),
    [
        pytest.param({"area_from": 130}, "от 117 м² — 1 квартира", id="area"),
        pytest.param({"deadline_to": "2026-06-30"}, "сдача до IV кв. 2026 — 3 квартиры", id="deadline"),
        pytest.param(
            {"is_euro": False, "rooms": [2]}, "любая планировка, не только классическая — 1 квартира", id="euro"
        ),
        pytest.param({"floor_from": 30}, "любой этаж — 5 квартир", id="floor"),
        pytest.param({"rooms": [0], "not_first_floor": True}, "можно первый этаж — 1 квартира", id="first floor"),
        pytest.param({"rooms": [5 - 1], "not_last_floor": True}, "можно последний этаж — 1 квартира", id="last floor"),
        pytest.param({"rooms": [3], "finishings": ["rough"]}, "любая отделка — 1 квартира", id="finishing"),
        pytest.param({"price_from": 60_000_000}, "без нижней границы цены — 5 квартир", id="lowest price"),
        pytest.param({"area_to": 20}, "без верхней границы площади — 5 квартир", id="largest area"),
    ],
)
async def test_it_eases_every_kind_of_filter(
    connection: AsyncConnection[Any], catalog: Catalog, filters: dict[str, Any], expected: str
) -> None:
    complexes = await two_complexes(catalog)

    found = await descriptions(connection, complexes.tenant_id, FlatFilters.model_validate(filters))

    assert expected in found


async def test_it_looks_in_the_other_complexes(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    found = await descriptions(
        connection, complexes.tenant_id, FlatFilters(complex_ids=[complexes.river_id], rooms=[0], price_to=7_000_000)
    )

    assert "в других ЖК застройщика — 1 квартира" in found


async def test_it_offers_no_more_ways_out_than_asked(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    relaxations = await relax_filters(connection, complexes.tenant_id, FlatFilters(rooms=[2], price_to=10_000_000), 1)

    assert [relaxation.field for relaxation in relaxations] == ["price_to"]


async def test_it_has_nothing_to_offer_without_flats(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()

    assert await relax_filters(connection, tenant_id, FlatFilters(rooms=[2], price_to=9_000_000)) == []


async def test_it_raises_the_budget_up_to_the_cheapest_flat_that_fits_the_rest(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    complexes = await two_complexes(catalog)

    relaxations = await relax_filters(connection, complexes.tenant_id, FlatFilters(rooms=[3], price_to=10_000_000))

    assert [relaxation.description for relaxation in relaxations] == ["до 30 млн ₽ — 1 квартира"]
    assert relaxations[0].filters.price_to == 30_000_000


async def test_it_offers_a_gentler_easing_before_one_that_finds_more(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, rooms=2, price=10_400_000)
    await catalog.flat(tenant_id, building_id, rooms=1, price=9_000_000)
    await catalog.flat(tenant_id, building_id, rooms=1, price=9_500_000)

    found = await descriptions(connection, tenant_id, FlatFilters(rooms=[2], price_to=10_000_000))

    assert found == ["до 10,5 млн ₽ — 1 квартира", "1 или 2 комнаты — 2 квартиры"]


async def test_it_adds_a_room_before_taking_one_away(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, rooms=1)
    await catalog.flat(tenant_id, building_id, rooms=3)

    relaxations = await relax_filters(connection, tenant_id, FlatFilters(rooms=[2]))

    assert [relaxation.description for relaxation in relaxations] == ["2 или 3 комнаты — 1 квартира"]


async def test_it_drops_one_nearby_place_at_a_time(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    relaxations = await relax_filters(
        connection, complexes.tenant_id, FlatFilters(rooms=[3], nearby=[PoiType.SHOP, PoiType.SCHOOL])
    )

    assert [(relaxation.description, relaxation.filters.nearby) for relaxation in relaxations] == [
        ("без условия «рядом школа» — 1 квартира", [PoiType.SHOP])
    ]
