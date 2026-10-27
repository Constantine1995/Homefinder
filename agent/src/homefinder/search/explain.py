"""Why a flat fits a request: one phrase per hard filter, built from the flat's own values.

The phrases come from the data rather than the model, so the agent can quote them without inventing anything.
"""

from __future__ import annotations

from datetime import date
from typing import TYPE_CHECKING

from homefinder.search.filters import Finishing, FlatFilters, FlatStatus, PoiType

if TYPE_CHECKING:
    from homefinder.search.flats import FlatMatch, Place

FINISHING_LABELS = {
    Finishing.ROUGH: "без отделки",
    Finishing.WHITE_BOX: "white box",
    Finishing.FINISHED: "чистовая отделка",
    Finishing.FURNISHED: "под ключ",
}

STATUS_LABELS = {
    FlatStatus.AVAILABLE: "свободна",
    FlatStatus.RESERVED: "бронь",
    FlatStatus.SOLD: "продана",
    FlatStatus.WITHDRAWN: "снята с продажи",
}

POI_LABELS = {
    PoiType.METRO: "метро",
    PoiType.SCHOOL: "школа",
    PoiType.KINDERGARTEN: "детский сад",
    PoiType.PARK: "парк",
    PoiType.SHOP: "магазин",
}


def reasons(flat: FlatMatch, filters: FlatFilters) -> list[str]:
    """Explain how the flat meets each filter that was set."""
    phrases: list[str] = []

    if filters.complex_ids:
        phrases.append(f"ЖК «{flat.complex_name}»")
    if filters.rooms or filters.is_euro is not None:
        phrases.append(rooms_label(flat.rooms, flat.is_euro))
    if filters.price_to is not None:
        phrases.append(f"{money(flat.price)} — в бюджете до {money(filters.price_to)}")
    elif filters.price_from is not None:
        phrases.append(f"{money(flat.price)} — от {money(filters.price_from)}")
    if filters.area_from is not None or filters.area_to is not None:
        phrases.append(f"{number(flat.area)} м²")
    if (
        filters.floor_from is not None
        or filters.floor_to is not None
        or filters.not_first_floor
        or filters.not_last_floor
    ):
        phrases.append(f"{flat.floor} этаж из {flat.floors}" if flat.floors else f"{flat.floor} этаж")
    if filters.deadline_to is not None and flat.deadline is not None:
        phrases.append(f"сдача {quarter(flat.deadline)}")
    if filters.metro_walk_to is not None and (metro := flat.nearest.get(PoiType.METRO)) is not None:
        station = f"м. «{metro.name}»" if metro.name else "метро"
        phrases.append(f"до {station} {metro.walk_min} мин пешком")
    for poi_type in filters.nearby:
        if (place := flat.nearest.get(poi_type)) is not None:
            phrases.append(place_label(poi_type, place))
    if filters.finishings and flat.finishing is not None:
        phrases.append(FINISHING_LABELS[flat.finishing])
    if filters.statuses != [FlatStatus.AVAILABLE] and filters.statuses:
        phrases.append(STATUS_LABELS[flat.status])

    return phrases


def describe(filters: FlatFilters, complex_names: dict[int, str]) -> str:
    """Put the search conditions into a buyer's words, «2-комн. до 9 млн ₽ в ЖК «Тёплые холмы»», to name a saved
    search in the messages about it."""
    parts = []
    if filters.rooms:
        parts.append(" или ".join(_rooms_short(rooms) for rooms in sorted(filters.rooms)))
    if filters.is_euro is not None:
        parts.append("евроформат" if filters.is_euro else "не евроформат")
    if filters.price_from is not None:
        parts.append(f"от {money(filters.price_from)}")
    if filters.price_to is not None:
        parts.append(f"до {money(filters.price_to)}")
    if filters.area_from is not None:
        parts.append(f"от {number(filters.area_from)} м²")
    if filters.area_to is not None:
        parts.append(f"до {number(filters.area_to)} м²")
    if filters.deadline_to is not None:
        parts.append(f"сдача до {quarter(filters.deadline_to)}")
    if filters.metro_walk_to is not None:
        parts.append(f"до метро {filters.metro_walk_to} мин пешком")
    parts += [f"рядом {POI_LABELS[poi_type]}" for poi_type in filters.nearby]
    parts += [FINISHING_LABELS[finishing] for finishing in filters.finishings]
    if filters.not_first_floor:
        parts.append("не первый этаж")
    if filters.not_last_floor:
        parts.append("не последний этаж")
    parts += [f"ЖК «{complex_names[complex_id]}»" for complex_id in filters.complex_ids if complex_id in complex_names]

    return ", ".join(parts)


def _rooms_short(rooms: int) -> str:
    return "студия" if rooms == 0 else "4+ комн." if rooms >= 4 else f"{rooms}-комн."


def rooms_label(rooms: int, is_euro: bool) -> str:
    if rooms == 0:
        return "студия"

    label = f"{rooms} {plural(rooms, 'комната', 'комнаты', 'комнат')}"

    return f"{label}, евроформат" if is_euro else label


def place_label(poi_type: PoiType, place: Place) -> str:
    label = POI_LABELS[poi_type]
    if place.name and poi_type is PoiType.PARK:
        label = f"{label} «{place.name}»"

    return f"{label} в {distance(place.distance_m)}"


def money(rubles: int) -> str:
    """Format a price the way buyers say it: «9,14 млн ₽», «850 тыс. ₽»."""
    if rubles >= 1_000_000:
        return f"{number(round(rubles / 1_000_000, 2))} млн ₽"

    return f"{round(rubles / 1000)} тыс. ₽"


def rubles(amount: int) -> str:
    """Write an exact sum with thin groups, as payments are quoted: «47 964 ₽»."""
    return f"{amount:,} ₽".replace(",", " ")


def distance(meters: int) -> str:
    if meters < 1000:
        return f"{round(meters, -1)} м"

    return f"{number(round(meters / 1000, 1))} км"


def quarter(day: date) -> str:
    return f"{('I', 'II', 'III', 'IV')[(day.month - 1) // 3]} кв. {day.year}"


def number(value: float) -> str:
    """Format a number with a decimal comma and without trailing zeros."""
    return f"{value:g}".replace(".", ",") if value != int(value) else str(int(value))


def plural(count: int, one: str, few: str, many: str) -> str:
    if count % 10 == 1 and count % 100 != 11:
        return one
    if 2 <= count % 10 <= 4 and not 12 <= count % 100 <= 14:
        return few

    return many
