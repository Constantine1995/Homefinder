from datetime import date
from typing import Any

import pytest
from psycopg import AsyncConnection

from homefinder.search.compare import compare
from homefinder.search.flats import get_flats
from tests.conftest import Catalog

pytestmark = pytest.mark.anyio


async def test_it_gets_flats_by_id_in_the_order_asked_whatever_their_status(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id, "Отрадный квартал"))
    sold_id = await catalog.flat(tenant_id, building_id, number="sold", status="sold", price=5_000_000)
    available_id = await catalog.flat(tenant_id, building_id, number="available", price=9_000_000)
    other_tenant_id = await catalog.tenant("Другой застройщик")
    other_building_id = await catalog.building(other_tenant_id, await catalog.complex(other_tenant_id))
    foreign_id = await catalog.flat(other_tenant_id, other_building_id, number="foreign")

    flats = await get_flats(connection, tenant_id, [available_id, foreign_id, sold_id, 0])

    assert [(flat.number, flat.status.value) for flat in flats] == [("available", "available"), ("sold", "sold")]
    assert flats[0].complex_name == "Отрадный квартал"
    assert flats[0].reasons == []


async def test_it_compares_flats_row_by_row(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    tenant_id = await catalog.tenant()
    near_id = await catalog.complex(tenant_id, "Отрадный квартал")
    far_id = await catalog.complex(tenant_id, "Речная терраса")
    await catalog.near(tenant_id, near_id, await catalog.poi("metro", "Владыкино"), distance_m=480, walk_min=8)
    await catalog.near(tenant_id, far_id, await catalog.poi("metro", "Терехово"), distance_m=1685, walk_min=28)
    early = await catalog.building(tenant_id, near_id, floors=17, deadline=date(2026, 12, 31))
    late = await catalog.building(tenant_id, far_id, floors=24, deadline=date(2028, 6, 30))
    first = await catalog.flat(
        tenant_id, early, number="12", rooms=2, area=55, kitchen_area=11, price=11_000_000, floor=5, finishing="rough"
    )
    second = await catalog.flat(
        tenant_id, late, number="40", rooms=2, is_euro=True, area=60, kitchen_area=None, price=9_800_000, floor=24
    )

    comparison = compare(await get_flats(connection, tenant_id, [first, second]))

    assert [(row.label, row.values, row.best) for row in comparison.rows] == [
        ("Комнаты", ["2 комнаты", "2 комнаты, евроформат"], []),
        ("Цена", ["11 млн ₽", "9,8 млн ₽"], [1]),
        ("Цена за м²", ["200 тыс. ₽", "163 тыс. ₽"], [1]),
        ("Площадь", ["55 м²", "60 м²"], [1]),
        ("Кухня", ["11 м²", "—"], []),
        ("Этаж", ["5 из 17", "24 из 24"], []),
        ("Сдача", ["IV кв. 2026", "II кв. 2028"], [0]),
        ("До метро", ["8 мин пешком", "28 мин пешком"], [0]),
        ("Отделка", ["без отделки", "—"], []),
    ]
    assert comparison.summary == ["кв. № 40 дешевле кв. № 12 на 1,2 млн ₽", "кв. № 40 больше кв. № 12 на 5 м²"]


async def test_it_names_flats_by_id_when_their_numbers_repeat(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    first = await catalog.flat(tenant_id, building_id, number="12", price=9_000_000)
    second = await catalog.flat(tenant_id, building_id, number="12", price=9_500_000)

    comparison = compare(await get_flats(connection, tenant_id, [first, second]))

    assert comparison.summary == [f"квартира {first} дешевле квартиры {second} на 500 тыс. ₽"]


@pytest.mark.parametrize("count", [1, 4])
async def test_it_compares_two_or_three_flats(connection: AsyncConnection[Any], catalog: Catalog, count: int) -> None:
    tenant_id = await catalog.tenant()
    building_id = await catalog.building(tenant_id, await catalog.complex(tenant_id))
    ids = [await catalog.flat(tenant_id, building_id) for _ in range(count)]

    with pytest.raises(ValueError, match="compare 2 or 3 flats"):
        compare(await get_flats(connection, tenant_id, ids))
