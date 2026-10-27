"""The `relax_filters` tool: when nothing fits, which condition to ease and how many flats that gives.

«До 9 млн ничего нет, а до 9,5 млн — 12 квартир». Each filter is eased by the smallest of a few steps that finds
something; the steps are fixed in code, so the agent offers real numbers rather than guesses.
"""

from collections.abc import Iterator
from datetime import date
from typing import Any

from psycopg import AsyncConnection
from pydantic import BaseModel

from homefinder.search.explain import POI_LABELS, money, number, plural, quarter
from homefinder.search.filters import MANY_ROOMS, FlatFilters, PoiType
from homefinder.search.flats import cheapest_price, count_flats

MAX_RELAXATIONS = 3
"""The agent offers a buyer up to three ways out, not a menu of every filter."""

PRICE_STEPS = (1.05, 1.1, 1.2)
AREA_STEPS = (0.9, 0.8)
METRO_STEPS = (5, 10, 20)
DEADLINE_STEPS_MONTHS = (6, 12)


class Relaxation(BaseModel):
    field: str
    """The filter eased, as named in FlatFilters."""
    filters: FlatFilters
    """All the filters with this one eased: the agent searches with them once the buyer agrees."""
    total: int
    description: str
    """What changes and what it gives, for the agent to say: «до 9,5 млн ₽ — 12 квартир»."""


type Candidate = tuple[str, FlatFilters, str]
"""A filter, the filters with it eased, and what the change is in words."""


async def relax_filters(
    connection: AsyncConnection[Any], tenant_id: int, filters: FlatFilters, limit: int = MAX_RELAXATIONS
) -> list[Relaxation]:
    """Find the smallest easing of each filter that finds flats; the gentlest steps and the most flats come first."""
    found: list[tuple[int, Relaxation]] = []
    cheapest = None
    if filters.price_to is not None:
        cheapest = await cheapest_price(connection, tenant_id, filters.model_copy(update={"price_to": None}))

    for field, ladder in _ladders(filters, cheapest):
        for step, (_, relaxed, change) in enumerate(ladder):
            total = await count_flats(connection, tenant_id, relaxed)
            if total > 0:
                description = f"{change} — {total} {plural(total, 'квартира', 'квартиры', 'квартир')}"
                found.append((step, Relaxation(field=field, filters=relaxed, total=total, description=description)))
                break

    found.sort(key=lambda item: (item[0], -item[1].total))

    return [relaxation for _, relaxation in found[:limit]]


def _ladders(filters: FlatFilters, cheapest: int | None) -> Iterator[tuple[str, list[Candidate]]]:
    """Ways to ease each filter that is set, the gentlest first.

    The budget is raised by a few steps, and at last up to the cheapest flat that passes the other filters: when
    the cheapest one-room flat costs 6,8 million, a buyer with 5 learns that, rather than nothing.
    """

    def eased(field: str, change: str, **values: Any) -> Candidate:
        return field, filters.model_copy(update=values), change

    if filters.price_to is not None:
        budgets = [_round_price(filters.price_to * step) for step in PRICE_STEPS]
        if cheapest is not None and cheapest > budgets[-1]:
            budgets.append(_round_price(cheapest))
        yield "price_to", [eased("price_to", f"до {money(budget)}", price_to=budget) for budget in budgets]
    if filters.price_from is not None:
        yield "price_from", [eased("price_from", "без нижней границы цены", price_from=None)]
    if filters.area_from is not None:
        area_from = filters.area_from
        yield (
            "area_from",
            [
                eased("area_from", f"от {number(round(area_from * step))} м²", area_from=float(round(area_from * step)))
                for step in AREA_STEPS
            ],
        )
    if filters.area_to is not None:
        yield "area_to", [eased("area_to", "без верхней границы площади", area_to=None)]
    if filters.rooms:
        yield "rooms", [eased("rooms", _rooms_change(rooms), rooms=rooms) for rooms in _wider_rooms(filters.rooms)]
    if filters.is_euro is not None:
        yield "is_euro", [eased("is_euro", "любая планировка, не только " + _euro_label(filters.is_euro), is_euro=None)]
    if filters.metro_walk_to is not None:
        walk = filters.metro_walk_to
        yield (
            "metro_walk_to",
            [
                eased("metro_walk_to", f"до метро до {walk + step} мин пешком", metro_walk_to=min(60, walk + step))
                for step in METRO_STEPS
                if walk < 60
            ]
            + [eased("metro_walk_to", "без условия о метро", metro_walk_to=None)],
        )
    if filters.deadline_to is not None:
        deadline = filters.deadline_to
        yield (
            "deadline_to",
            [
                eased("deadline_to", f"сдача до {quarter(later)}", deadline_to=later)
                for later in (_end_of_quarter_after(deadline, months) for months in DEADLINE_STEPS_MONTHS)
            ],
        )
    for poi_type in filters.nearby:
        nearby = [kept for kept in filters.nearby if kept is not poi_type]
        yield f"nearby.{poi_type.value}", [eased("nearby", _without_nearby(poi_type), nearby=nearby)]
    if filters.floor_from is not None or filters.floor_to is not None:
        yield "floor", [eased("floor", "любой этаж", floor_from=None, floor_to=None)]
    if filters.not_first_floor:
        yield "not_first_floor", [eased("not_first_floor", "можно первый этаж", not_first_floor=False)]
    if filters.not_last_floor:
        yield "not_last_floor", [eased("not_last_floor", "можно последний этаж", not_last_floor=False)]
    if filters.finishings:
        yield "finishings", [eased("finishings", "любая отделка", finishings=[])]
    if filters.complex_ids:
        yield "complex_ids", [eased("complex_ids", "в других ЖК застройщика", complex_ids=[])]


def _round_price(rubles: float) -> int:
    """Round an eased budget up to a hundred thousand rubles, the way people name budgets."""
    return -(-int(rubles) // 100_000) * 100_000


def _wider_rooms(rooms: list[int]) -> list[list[int]]:
    """Add one more room, then one fewer: a buyer of a two-room flat looks at three rooms sooner than at studios."""
    wider: list[list[int]] = []
    more, fewer = max(rooms) + 1, min(rooms) - 1
    if more <= MANY_ROOMS:
        wider.append(sorted({*rooms, more}))
    if fewer >= 0:
        wider.append(sorted({*rooms, fewer}))
    return wider


def _rooms_change(rooms: list[int]) -> str:
    """Name the room counts: «2 или 3 комнаты», «студия или 1 комната»; the widest one is never a studio."""
    labels = ["студия" if count == 0 else f"{count}+" if count == MANY_ROOMS else str(count) for count in rooms]
    return f"{', '.join(labels[:-1])} или {labels[-1]} {plural(rooms[-1], 'комната', 'комнаты', 'комнат')}"


def _euro_label(is_euro: bool) -> str:
    return "евро" if is_euro else "классическая"


def _without_nearby(poi_type: PoiType) -> str:
    return f"без условия «рядом {POI_LABELS[poi_type]}»"


def _end_of_quarter_after(day: date, months: int) -> date:
    month = day.month - 1 + months
    year, month = day.year + month // 12, month % 12 + 1
    end_month = ((month - 1) // 3 + 1) * 3
    return date(year, end_month, 31 if end_month in (3, 12) else 30)
