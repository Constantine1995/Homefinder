"""The `search_flats` tool: flats of a tenant that pass the hard filters, with the reasons they fit."""

from datetime import date
from decimal import Decimal
from typing import Any

from psycopg import AsyncConnection, sql
from psycopg.rows import dict_row
from pydantic import BaseModel

from homefinder.search.explain import reasons
from homefinder.search.filters import MANY_ROOMS, Finishing, FlatFilters, FlatStatus, PoiType

DEFAULT_LIMIT = 5
"""The tool shows a buyer up to five flats at a time, as the specification asks."""


class Place(BaseModel):
    """The nearest place of a kind to a complex."""

    name: str | None
    distance_m: int
    walk_min: int


class FlatMatch(BaseModel):
    id: int
    complex_id: int
    complex_name: str
    building_name: str
    number: str | None
    floor: int
    floors: int | None
    rooms: int
    is_euro: bool
    area: float
    kitchen_area: float | None
    finishing: Finishing | None
    price: int
    price_per_meter: int
    status: FlatStatus
    deadline: date | None
    plan_url: str | None
    description: str | None
    features: dict[str, Any]
    nearest: dict[PoiType, Place]
    """The nearest metro station, school, park and so on of the flat's complex."""
    wish_similarity: float | None = None
    """How close the flat's description is to the buyer's wishes, from -1 to 1; None without wishes."""
    reasons: list[str]
    """Why the flat fits the request, one phrase per filter: «2 комнаты», «до метро 7 мин пешком»."""


class FlatSearchResult(BaseModel):
    total: int
    """How many flats pass the filters; only the first `limit` of them are returned."""
    flats: list[FlatMatch]


# The nearest place of each kind to each of the tenant's complexes. complex_poi holds metro stations within 3 km and
# other places within 1 km (Laravel's config/geo.php), so a row here means the place is near enough to count.
_NEAREST = sql.SQL("""
    nearest as (
        select distinct on (cp.complex_id, p.type) cp.complex_id, p.type, p.name, cp.distance_m, cp.walk_min
        from complex_poi cp
        join pois p on p.id = cp.poi_id
        where cp.tenant_id = %(tenant_id)s
        order by cp.complex_id, p.type, cp.distance_m, p.id
    )
""")

_SELECT = sql.SQL("""
    with {nearest}
    select
        f.id, f.number, f.floor, f.rooms, f.is_euro, f.area, f.kitchen_area, f.finishing, f.price, f.status,
        f.plan_url, f.description, coalesce(f.features, '{{}}'::jsonb) as features,
        round(f.price / f.area)::bigint as price_per_meter,
        b.name as building_name, b.floors, b.deadline,
        c.id as complex_id, c.name as complex_name,
        coalesce((
            select jsonb_object_agg(n.type, jsonb_build_object(
                'name', n.name, 'distance_m', n.distance_m, 'walk_min', n.walk_min
            ))
            from nearest n
            where n.complex_id = c.id
        ), '{{}}'::jsonb) as nearest,
        {similarity} as wish_similarity,
        count(*) over () as total
    from flats f
    join buildings b on b.id = f.building_id
    join complexes c on c.id = b.complex_id
    where {conditions}
    order by {order}
    limit %(limit)s
""")

_AGGREGATE = sql.SQL("""
    with {nearest}
    select {aggregate}
    from flats f
    join buildings b on b.id = f.building_id
    where {conditions}
""")

_BY_PRICE = sql.SQL("f.price, f.id")

# Cosine distance of pgvector; flats without a vector yet go after those that have one.
_BY_WISHES = sql.SQL("f.embedding <=> %(wishes)s::vector nulls last, f.price, f.id")

_SIMILARITY = sql.SQL("1 - (f.embedding <=> %(wishes)s::vector)")


async def search_flats(
    connection: AsyncConnection[Any],
    tenant_id: int,
    filters: FlatFilters,
    limit: int = DEFAULT_LIMIT,
    wishes: list[float] | None = None,
) -> FlatSearchResult:
    """Find the tenant's flats that pass the filters: closest to the wishes first, or cheapest first without them.

    The tenant comes from the channel the buyer writes to, never from the model: the service sees no Eloquent
    scopes, so every query filters by it explicitly. `wishes` is the vector of the buyer's soft wishes.
    """
    conditions, params = _conditions(filters)
    query = _SELECT.format(
        nearest=_NEAREST,
        conditions=sql.SQL(" and ").join(conditions),
        similarity=sql.SQL("null::float") if wishes is None else _SIMILARITY,
        order=_BY_PRICE if wishes is None else _BY_WISHES,
    )
    if wishes is not None:
        params["wishes"] = to_vector(wishes)

    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(query, {**params, "tenant_id": tenant_id, "limit": limit})
        rows = await cursor.fetchall()

    flats = [_flat(row, filters) for row in rows]

    return FlatSearchResult(total=rows[0]["total"] if rows else 0, flats=flats)


async def get_flats(connection: AsyncConnection[Any], tenant_id: int, flat_ids: list[int]) -> list[FlatMatch]:
    """Get the tenant's flats by ID, whatever their status, in the order asked: the `get_flat` tool.

    A sold flat comes back as sold, so the agent can say so instead of offering it.
    """
    query = _SELECT.format(
        nearest=_NEAREST,
        conditions=sql.SQL("f.tenant_id = %(tenant_id)s and f.id = any(%(flat_ids)s)"),
        similarity=sql.SQL("null::float"),
        order=_BY_PRICE,
    )
    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(query, {"tenant_id": tenant_id, "flat_ids": flat_ids, "limit": len(flat_ids)})
        rows = {row["id"]: row for row in await cursor.fetchall()}

    return [_flat(rows[flat_id], FlatFilters(statuses=[])) for flat_id in flat_ids if flat_id in rows]


async def count_flats(connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters) -> int:
    """Count the tenant's flats that pass the filters."""
    return int(await _aggregate(connection, tenant_id, filters, sql.SQL("count(*)")) or 0)


async def cheapest_price(connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters) -> int | None:
    """Get the lowest price among the tenant's flats that pass the filters, or None if none does."""
    price = await _aggregate(connection, tenant_id, filters, sql.SQL("min(f.price)"))
    return None if price is None else int(price)


async def _aggregate(
    connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters, aggregate: sql.Composable
) -> Any:
    conditions, params = _conditions(filters)
    query = _AGGREGATE.format(nearest=_NEAREST, aggregate=aggregate, conditions=sql.SQL(" and ").join(conditions))
    cursor = await connection.execute(query, {**params, "tenant_id": tenant_id})
    row = await cursor.fetchone()
    return row[0] if row else None


def _conditions(filters: FlatFilters) -> tuple[list[sql.Composable], dict[str, Any]]:
    conditions: list[sql.Composable] = [sql.SQL("f.tenant_id = %(tenant_id)s")]
    params: dict[str, Any] = {}

    def where(condition: str, **values: Any) -> None:
        conditions.append(sql.SQL(condition))
        params.update(values)

    if filters.complex_ids:
        where("b.complex_id = any(%(complex_ids)s)", complex_ids=filters.complex_ids)
    if filters.rooms:
        exact_rooms = [rooms for rooms in filters.rooms if rooms != MANY_ROOMS]
        if MANY_ROOMS in filters.rooms:
            where("(f.rooms = any(%(rooms)s) or f.rooms >= %(many_rooms)s)", rooms=exact_rooms, many_rooms=MANY_ROOMS)
        else:
            where("f.rooms = any(%(rooms)s)", rooms=exact_rooms)
    if filters.is_euro is not None:
        where("f.is_euro = %(is_euro)s", is_euro=filters.is_euro)
    if filters.price_from is not None:
        where("f.price >= %(price_from)s", price_from=filters.price_from)
    if filters.price_to is not None:
        where("f.price <= %(price_to)s", price_to=filters.price_to)
    if filters.area_from is not None:
        where("f.area >= %(area_from)s", area_from=Decimal(str(filters.area_from)))
    if filters.area_to is not None:
        where("f.area <= %(area_to)s", area_to=Decimal(str(filters.area_to)))
    if filters.floor_from is not None:
        where("f.floor >= %(floor_from)s", floor_from=filters.floor_from)
    if filters.floor_to is not None:
        where("f.floor <= %(floor_to)s", floor_to=filters.floor_to)
    if filters.not_first_floor:
        where("f.floor > 1")
    if filters.not_last_floor:
        where("(b.floors is null or f.floor < b.floors)")
    if filters.deadline_to is not None:
        where("b.deadline <= %(deadline_to)s", deadline_to=filters.deadline_to)
    if filters.metro_walk_to is not None:
        where(
            "exists (select from nearest n where n.complex_id = b.complex_id and n.type = %(metro)s "
            "and n.walk_min <= %(metro_walk_to)s)",
            metro=PoiType.METRO.value,
            metro_walk_to=filters.metro_walk_to,
        )
    if filters.nearby:
        nearby = sorted({poi_type.value for poi_type in filters.nearby})
        where(
            "(select count(*) from nearest n where n.complex_id = b.complex_id and n.type = any(%(nearby)s)) "
            "= %(nearby_count)s",
            nearby=nearby,
            nearby_count=len(nearby),
        )
    if filters.finishings:
        where("f.finishing = any(%(finishings)s)", finishings=[finishing.value for finishing in filters.finishings])
    if filters.statuses:
        where("f.status = any(%(statuses)s)", statuses=[status.value for status in filters.statuses])

    return conditions, params


def _flat(row: dict[str, Any], filters: FlatFilters) -> FlatMatch:
    flat = FlatMatch(
        id=row["id"],
        complex_id=row["complex_id"],
        complex_name=row["complex_name"],
        building_name=row["building_name"],
        number=row["number"],
        floor=row["floor"],
        floors=row["floors"],
        rooms=row["rooms"],
        is_euro=row["is_euro"],
        area=float(row["area"]),
        kitchen_area=None if row["kitchen_area"] is None else float(row["kitchen_area"]),
        finishing=row["finishing"],
        price=row["price"],
        price_per_meter=row["price_per_meter"],
        status=row["status"],
        deadline=row["deadline"],
        plan_url=row["plan_url"],
        description=row["description"],
        features=row["features"],
        nearest=row["nearest"],
        wish_similarity=None if row["wish_similarity"] is None else round(row["wish_similarity"], 4),
        reasons=[],
    )

    return flat.model_copy(update={"reasons": reasons(flat, filters)})


def to_vector(values: list[float]) -> str:
    """Write a vector in pgvector's text form; the service needs no pgvector package for one parameter."""
    return "[" + ",".join(repr(float(value)) for value in values) + "]"
