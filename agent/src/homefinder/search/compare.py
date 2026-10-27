"""The `compare` tool: two or three flats side by side, with the better value of each row marked by code."""

from collections.abc import Callable
from datetime import date

from pydantic import BaseModel

from homefinder.search.explain import FINISHING_LABELS, money, number, quarter, rooms_label
from homefinder.search.filters import PoiType
from homefinder.search.flats import FlatMatch

MIN_FLATS, MAX_FLATS = 2, 3


class ComparisonRow(BaseModel):
    label: str
    values: list[str]
    """One value per flat, in the order of the flats."""
    best: list[int]
    """Positions of the flats with the best value; empty when the row is not better or worse, like the floor."""


class Comparison(BaseModel):
    flats: list[FlatMatch]
    rows: list[ComparisonRow]
    summary: list[str]
    """Plain differences for the agent to quote: «№ 12 дешевле № 40 на 1,2 млн ₽»."""


def compare(flats: list[FlatMatch]) -> Comparison:
    """Compare the flats row by row; the agent quotes the rows rather than working the differences out."""
    if not MIN_FLATS <= len(flats) <= MAX_FLATS:
        raise ValueError(f"compare {MIN_FLATS} or {MAX_FLATS} flats, not {len(flats)}")

    def metro(flat: FlatMatch) -> int | None:
        station = flat.nearest.get(PoiType.METRO)
        return station.walk_min if station else None

    rows = [
        _row("Комнаты", flats, lambda flat: rooms_label(flat.rooms, flat.is_euro)),
        _row("Цена", flats, lambda flat: money(flat.price), key=lambda flat: flat.price, lower_is_better=True),
        _row(
            "Цена за м²",
            flats,
            lambda flat: money(flat.price_per_meter),
            key=lambda flat: flat.price_per_meter,
            lower_is_better=True,
        ),
        _row("Площадь", flats, lambda flat: f"{number(flat.area)} м²", key=lambda flat: flat.area),
        _row(
            "Кухня",
            flats,
            lambda flat: f"{number(flat.kitchen_area)} м²" if flat.kitchen_area else "—",
            key=lambda flat: flat.kitchen_area,
        ),
        _row("Этаж", flats, lambda flat: f"{flat.floor} из {flat.floors}" if flat.floors else str(flat.floor)),
        _row(
            "Сдача",
            flats,
            lambda flat: quarter(flat.deadline) if flat.deadline else "—",
            key=lambda flat: flat.deadline,
            lower_is_better=True,
        ),
        _row(
            "До метро",
            flats,
            lambda flat: f"{metro(flat)} мин пешком" if metro(flat) is not None else "—",
            key=metro,
            lower_is_better=True,
        ),
        _row(
            "Отделка",
            flats,
            lambda flat: FINISHING_LABELS[flat.finishing] if flat.finishing else "—",
        ),
    ]

    return Comparison(flats=flats, rows=rows, summary=_summary(flats))


type Comparable = int | float | date | None


def _row(
    label: str,
    flats: list[FlatMatch],
    value: Callable[[FlatMatch], str],
    key: Callable[[FlatMatch], Comparable] | None = None,
    lower_is_better: bool = False,
) -> ComparisonRow:
    best: list[int] = []
    if key is not None:
        keys = [key(flat) for flat in flats]
        known = [item for item in keys if item is not None]
        if len(set(known)) > 1:
            target = min(known) if lower_is_better else max(known)
            best = [position for position, item in enumerate(keys) if item == target]

    return ComparisonRow(label=label, values=[value(flat) for flat in flats], best=best)


def _summary(flats: list[FlatMatch]) -> list[str]:
    numbers = [flat.number for flat in flats]
    unique = len(set(numbers)) == len(numbers) and None not in numbers

    def _name(flat: FlatMatch, genitive: bool = False) -> str:
        """Name a flat by its number, or by its ID when the numbers repeat across buildings."""
        if unique:
            return f"кв. № {flat.number}"
        return f"квартиры {flat.id}" if genitive else f"квартира {flat.id}"

    cheapest = min(flats, key=lambda flat: flat.price)
    largest = max(flats, key=lambda flat: flat.area)
    lines = [
        f"{_name(cheapest)} дешевле {_name(other, genitive=True)} на {money(other.price - cheapest.price)}"
        for other in flats
        if other is not cheapest and other.price != cheapest.price
    ]
    lines += [
        f"{_name(largest)} больше {_name(other, genitive=True)} на {number(round(largest.area - other.area, 1))} м²"
        for other in flats
        if other is not largest and other.area != largest.area
    ]
    return lines
