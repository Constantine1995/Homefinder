from dataclasses import dataclass
from datetime import date
from typing import Any

import pytest
from psycopg import AsyncConnection

from homefinder.search.filters import FlatFilters, PoiType
from homefinder.search.flats import search_flats
from tests.conftest import Catalog

pytestmark = pytest.mark.anyio


@dataclass
class TwoComplexes:
    tenant_id: int
    otradny_id: int
    river_id: int


async def two_complexes(catalog: Catalog) -> TwoComplexes:
    """The catalog of Laravel's CatalogControllerTest, so both searches are held to the same expectations.

    «Отрадный квартал» is 8 minutes from the metro and completed in 2026; «Речная терраса» is 28 minutes away, but has
    a shop 2 minutes away, and is completed in 2028.
    """
    tenant_id = await catalog.tenant()
    otradny_id = await catalog.complex(tenant_id, "Отрадный квартал")
    river_id = await catalog.complex(tenant_id, "Речная терраса")
    otradny_building = await catalog.building(tenant_id, otradny_id, floors=17, deadline=date(2026, 12, 31))
    river_building = await catalog.building(tenant_id, river_id, floors=24, deadline=date(2028, 12, 31))
    await catalog.near(tenant_id, otradny_id, await catalog.poi("metro", "Владыкино"), distance_m=480, walk_min=8)
    await catalog.near(tenant_id, otradny_id, await catalog.poi("metro", "Отрадное"), distance_m=1100, walk_min=18)
    await catalog.near(tenant_id, river_id, await catalog.poi("metro", "Терехово"), distance_m=1685, walk_min=28)
    await catalog.near(tenant_id, river_id, await catalog.poi("shop", "Пятёрочка"), distance_m=90, walk_min=2)

    flats: dict[str, tuple[int, dict[str, Any]]] = {
        "studio": (otradny_building, {"rooms": 0, "area": 25, "price": 6_500_000, "floor": 1, "finishing": "finished"}),
        "one": (otradny_building, {"rooms": 1, "area": 38.5, "price": 9_000_000, "floor": 5, "finishing": "rough"}),
        "euro-two": (
            otradny_building,
            {"rooms": 2, "is_euro": True, "area": 45, "price": 10_500_000, "floor": 17, "finishing": "white_box"},
        ),
        "three": (river_building, {"rooms": 3, "area": 80, "price": 30_000_000, "floor": 10, "finishing": "finished"}),
        "five": (river_building, {"rooms": 5, "area": 120, "price": 55_000_000, "floor": 24, "finishing": "furnished"}),
        "reserved": (
            otradny_building,
            {"rooms": 1, "area": 40, "price": 9_500_000, "floor": 6, "status": "reserved"},
        ),
        "sold": (river_building, {"rooms": 2, "area": 60, "price": 25_000_000, "floor": 3, "status": "sold"}),
    }
    for number, (building_id, values) in flats.items():
        await catalog.flat(tenant_id, building_id, number=number, **values)

    return TwoComplexes(tenant_id, otradny_id, river_id)


async def numbers(connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters) -> list[str]:
    result = await search_flats(connection, tenant_id, filters, limit=50)
    return sorted(flat.number or "" for flat in result.flats)


@pytest.mark.parametrize(
    ("filters", "expected"),
    [
        pytest.param({}, ["euro-two", "five", "one", "studio", "three"], id="no filters: the flats on sale"),
        pytest.param({"rooms": [0, 1]}, ["one", "studio"], id="studios and one-room flats"),
        pytest.param({"rooms": [4]}, ["five"], id="four rooms and more"),
        pytest.param({"rooms": [1, 4]}, ["five", "one"], id="one room or four and more"),
        pytest.param({"is_euro": True}, ["euro-two"], id="euro layouts only"),
        pytest.param({"is_euro": False}, ["five", "one", "studio", "three"], id="no euro layouts"),
        pytest.param({"price_from": 9_000_000, "price_to": 11_000_000}, ["euro-two", "one"], id="price range"),
        pytest.param({"area_from": 40, "area_to": 100}, ["euro-two", "three"], id="area range"),
        pytest.param({"area_from": 45, "area_to": 45}, ["euro-two"], id="area range of one value"),
        pytest.param({"floor_from": 5, "floor_to": 17}, ["euro-two", "one", "three"], id="floor range"),
        pytest.param({"not_first_floor": True}, ["euro-two", "five", "one", "three"], id="not the first floor"),
        pytest.param({"not_last_floor": True}, ["one", "studio", "three"], id="not the last floor"),
        pytest.param({"deadline_to": "2026-12-31"}, ["euro-two", "one", "studio"], id="completed by the end of 2026"),
        pytest.param({"deadline_to": "2026-12-30"}, [], id="completed a day before the deadline"),
        pytest.param({"metro_walk_to": 10}, ["euro-two", "one", "studio"], id="within 10 minutes of the metro"),
        pytest.param({"metro_walk_to": 7}, [], id="within 7 minutes of the metro"),
        pytest.param({"nearby": ["shop"]}, ["five", "three"], id="a shop nearby"),
        pytest.param({"nearby": ["shop", "school"]}, [], id="a shop and a school nearby"),
        pytest.param({"finishings": ["finished", "furnished"]}, ["five", "studio", "three"], id="finishings"),
        pytest.param({"statuses": ["reserved", "sold"]}, ["reserved", "sold"], id="reserved and sold flats"),
        pytest.param({"statuses": []}, ["euro-two", "five", "one", "reserved", "sold", "studio", "three"], id="any"),
    ],
)
async def test_it_finds_the_flats_that_pass_the_filters(
    connection: AsyncConnection[Any], catalog: Catalog, filters: dict[str, Any], expected: list[str]
) -> None:
    complexes = await two_complexes(catalog)

    assert await numbers(connection, complexes.tenant_id, FlatFilters.model_validate(filters)) == expected


async def test_it_counts_four_rooms_among_four_and_more(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    await catalog.flat(tenant_id, building_id, number="four", rooms=4)

    assert await numbers(connection, tenant_id, FlatFilters(rooms=[4])) == ["four"]


async def test_it_narrows_the_search_down_to_a_complex(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    found = await numbers(connection, complexes.tenant_id, FlatFilters(complex_ids=[complexes.river_id]))

    assert found == ["five", "three"]


async def test_it_shows_the_cheapest_flats_first_and_counts_them_all(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    complexes = await two_complexes(catalog)

    result = await search_flats(connection, complexes.tenant_id, FlatFilters(), limit=2)

    assert result.total == 5
    assert [flat.number for flat in result.flats] == ["studio", "one"]


async def test_it_finds_nothing_with_a_count_of_zero(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    result = await search_flats(connection, complexes.tenant_id, FlatFilters(price_to=1_000_000))

    assert result.total == 0
    assert result.flats == []


async def test_it_searches_the_flats_of_the_tenant_only(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)
    other_tenant_id = await catalog.tenant("Другой застройщик")
    other_complex_id = await catalog.complex(other_tenant_id, "Чужой ЖК")
    other_building_id = await catalog.building(other_tenant_id, other_complex_id)
    await catalog.flat(other_tenant_id, other_building_id, number="foreign", price=1_000_000)

    assert "foreign" not in await numbers(connection, complexes.tenant_id, FlatFilters())
    assert await numbers(connection, complexes.tenant_id, FlatFilters(complex_ids=[other_complex_id])) == []
    assert await numbers(connection, other_tenant_id, FlatFilters()) == ["foreign"]


async def test_it_ignores_the_distances_another_tenant_has_for_the_same_complex(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    complexes = await two_complexes(catalog)
    other_tenant_id = await catalog.tenant("Другой застройщик")
    school_id = await catalog.poi("school", "Школа № 1")
    await catalog.near(other_tenant_id, complexes.river_id, school_id, distance_m=300, walk_min=4)

    assert await numbers(connection, complexes.tenant_id, FlatFilters(nearby=[PoiType.SCHOOL])) == []


async def test_it_tells_the_nearest_places_and_why_the_flat_fits(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    complexes = await two_complexes(catalog)
    filters = FlatFilters(
        complex_ids=[complexes.otradny_id],
        rooms=[2],
        price_to=11_000_000,
        area_from=40,
        not_first_floor=True,
        deadline_to=date(2026, 12, 31),
        metro_walk_to=10,
        finishings=["white_box"],
    )

    result = await search_flats(connection, complexes.tenant_id, filters)

    [flat] = result.flats
    assert flat.number == "euro-two"
    assert flat.price_per_meter == 233_333
    assert flat.nearest[PoiType.METRO].name == "Владыкино"
    assert flat.nearest[PoiType.METRO].walk_min == 8
    assert flat.reasons == [
        "ЖК «Отрадный квартал»",
        "2 комнаты, евроформат",
        "10,5 млн ₽ — в бюджете до 11 млн ₽",
        "45 м²",
        "17 этаж из 17",
        "сдача IV кв. 2026",
        "до м. «Владыкино» 8 мин пешком",
        "white box",
    ]


async def test_it_explains_a_nearby_place_by_its_distance(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    complexes = await two_complexes(catalog)

    result = await search_flats(connection, complexes.tenant_id, FlatFilters(nearby=[PoiType.SHOP], rooms=[3]))

    assert result.flats[0].reasons == ["3 комнаты", "магазин в 90 м"]


def test_metro_is_filtered_by_the_walk_rather_than_as_a_nearby_place() -> None:
    with pytest.raises(ValueError, match="metro_walk_to"):
        FlatFilters(nearby=[PoiType.METRO])
