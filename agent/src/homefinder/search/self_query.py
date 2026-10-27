"""Self-query: a buyer's free text → hard filters for `search_flats` and soft wishes for the vector search.

The model fills a schema made for it — prices in millions, the deadline as a year and a quarter, complexes by name —
and the code turns it into `FlatFilters`. Small models are reliable with plain fields; the arithmetic, dates and
IDs stay in code.
"""

import re
from collections.abc import Callable
from datetime import date
from difflib import get_close_matches
from typing import Annotated, Any, Literal

from pydantic import BaseModel, BeforeValidator, ConfigDict

from homefinder.llm import Llm
from homefinder.search.filters import MANY_ROOMS, Finishing, FlatFilters, PoiType

WALKING_DISTANCE_MIN = 15
"""Minutes to the metro that «пешком», «рядом с метро» and «в шаговой доступности» stand for."""

COMPLEX_NAME_SIMILARITY = 0.85
"""How alike a named complex must be to one of the developer's, from 0 to 1, to be taken for it."""

WITH_FINISHING = "with_finishing"
"""What the model answers to «с отделкой», «с ремонтом»: any finishing a buyer can move in with."""

MOVE_IN_FINISHINGS = (Finishing.FINISHED, Finishing.FURNISHED)

RUBLES_THRESHOLD = 1000
"""A price above this many «millions» is taken as rubles: the model sometimes multiplies on its own."""


def _listed(value: Any) -> Any:
    """Read a single value where a list is asked for as a list of it ("park" for ["park"]), and null as none."""
    if value is None:
        return []
    return [value] if isinstance(value, str | int) and not isinstance(value, bool) else value


def _known_only(allowed: set[str]) -> Callable[[Any], Any]:
    """Keep the list items the model may give, so one made-up value does not throw the whole answer away."""

    def keep(values: Any) -> Any:
        values = _listed(values)
        return [value for value in values if value in allowed] if isinstance(values, list) else values

    return keep


def _room_counts(values: Any) -> Any:
    """Read «5 rooms» as four and more, and drop what is not a room count."""
    values = _listed(values)
    if not isinstance(values, list):
        return values
    return [min(value, MANY_ROOMS) for value in values if isinstance(value, int) and value >= 0]


def _quarter(value: Any) -> Any:
    return value if value in (1, 2, 3, 4) else None


class ParsedQuery(BaseModel):
    """What the model extracts from a buyer's message.

    Unknown fields are ignored and values out of range dropped: a free JSON answer is read as far as it makes sense.
    """

    model_config = ConfigDict(extra="ignore")

    complexes: Annotated[list[str], BeforeValidator(_listed)] = []
    rooms: Annotated[list[int], BeforeValidator(_room_counts)] = []
    euro: bool | None = None
    price_min_mln: float | None = None
    price_max_mln: float | None = None
    area_min: float | None = None
    area_max: float | None = None
    floor_min: int | None = None
    floor_max: int | None = None
    not_first_floor: bool = False
    not_last_floor: bool = False
    deadline_year: int | None = None
    deadline_quarter: Annotated[int | None, BeforeValidator(_quarter)] = None
    ready: bool = False
    metro_walk_max_min: int | None = None
    nearby: Annotated[
        list[Literal["school", "kindergarten", "park", "shop"]],
        BeforeValidator(_known_only({"school", "kindergarten", "park", "shop"})),
    ] = []
    finishings: Annotated[
        list[Finishing | Literal["with_finishing"]],
        BeforeValidator(_known_only({finishing.value for finishing in Finishing} | {WITH_FINISHING})),
    ] = []
    """Finishings, or WITH_FINISHING for «с отделкой»: one word is easier for the model than the pair it stands for."""
    wishes: str | None = None


class SelfQueryResult(BaseModel):
    filters: FlatFilters
    wishes: str | None
    """Soft wishes in the buyer's words: «светлая, вид на парк». They are matched by vectors, not by SQL."""
    unknown_complexes: list[str]
    """Complexes the buyer named that the developer does not have, for the agent to ask about."""


SYSTEM_PROMPT = """\
Ты разбираешь сообщение покупателя квартиры в новостройке и заполняешь поля поиска. Сегодня {today}.
Ответь одним JSON-объектом только с теми полями, о которых покупатель сказал явно; остальные поля не пиши.
Каждое условие сообщения должно попасть в своё поле: проверь цену, площадь, этаж, срок, метро и отделку.

Поля:
- complexes: названия ЖК из списка, если покупатель назвал ЖК. ЖК застройщика: {complexes}.
  Пиши название точно как в списке, даже если покупатель склонил его («в Отрадном квартале» → «Отрадный квартал»).
  Если назван ЖК не из списка — напиши его как назвал покупатель.
- rooms: комнатность. 0 — студия, 1 — однушка, 2 — двушка, 3 — трёшка, 4 — четыре комнаты и больше.
  «студия или однушка» → [0, 1]; «от двух комнат» → [2, 3, 4]; «многокомнатная» → [4].
  Сколько людей в семье — не комнатность: «нас четверо», «семья из четырёх человек» не заполняют rooms и не wishes.
- euro: true — евроформат («евродвушка», «евротрёшка», «кухня-гостиная»); false — «не евро», «классическая
  планировка». Евродвушка — это rooms [2] и euro true. Просто «двушка» — euro null.
- price_min_mln, price_max_mln: цена в миллионах рублей. «до 9 млн», «до 9 лямов», «не дороже 9», «бюджет 9 миллионов»,
  «в пределах 9» → price_max_mln 9. «от 7 до 9» → 7 и 9. «12,5 млн» → 12.5. «850 тысяч» → 0.85.
- area_min, area_max: площадь в квадратных метрах. «от 50 метров», «не меньше 50 квадратов» → area_min 50.
- floor_min, floor_max: этажи. «выше 5 этажа» → floor_min 6; «с 5 этажа» → floor_min 5; «до 10 этажа» → floor_max 10.
- not_first_floor: «не первый этаж». not_last_floor: «не последний этаж».
- deadline_year, deadline_quarter: самый поздний срок сдачи дома. «сдача не позже 2027», «до конца 2027», «в 2027» →
  год 2027 без квартала; «до середины 2027» → 2027, квартал 2; «в первом квартале 2027» → 2027, квартал 1;
  «в этом году» → текущий год; «в следующем году» → следующий год.
- ready: true, если нужен уже сданный дом: «готовое жильё», «дом сдан», «ключи сразу», «чтобы сразу въехать».
  Это только о сроке дома: «сдача в аренду», «для сдачи» (сдавать жильцам) и «ключи в 2027» — не ready.
- metro_walk_max_min: сколько минут пешком до метро. «10 минут до метро» → 10. «до метро пешком», «рядом с метро»,
  «у метро», «в шаговой доступности от метро» → 15. Названная станция — тоже метро: «рядом с Тютчевской»,
  «у станции Речной вокзал» → 15, а nearby не заполнять.
- nearby: что должно быть рядом с домом: school — школа; kindergarten — детский сад, садик; park — парк рядом;
  shop — магазины, супермаркет. Только если покупатель назвал школу, сад, парк или магазин.
- finishings: отделка. rough — «без отделки», «черновая»; white_box — «предчистовая», «white box», «вайтбокс»;
  finished — «чистовая»; furnished — «под ключ», «с мебелью»; with_finishing — «с отделкой», «с ремонтом»,
  «отделка обязательна». «Отделка любая» — поле не заполнять.
- wishes: мягкие пожелания своими словами покупателя, которые не ложатся в поля выше: «светлая», «вид на парк»,
  «тихий двор», «окна на юг», «высокий этаж», «большая кухня», «балкон». Вид из окна на парк — это wishes, а не nearby.
  «Подешевле», «недорого», «бюджетные», «самые дешёвые» — не wishes: найденное и так идёт от дешёвых.
  Нет таких пожеланий — null.
"""

EXAMPLES: list[tuple[str, ParsedQuery]] = [
    (
        "трёшка до 14 лямов, метро пешком, сдача не позже 2028",
        ParsedQuery(rooms=[3], price_max_mln=14, metro_walk_max_min=WALKING_DISTANCE_MIN, deadline_year=2028),
    ),
    (
        "Евродвушка в Речной терассе от 50 квадратов, не первый этаж, с отделкой и чтобы окна во двор",
        ParsedQuery(
            complexes=["Речная терраса"],
            rooms=[2],
            euro=True,
            area_min=50,
            not_first_floor=True,
            finishings=[WITH_FINISHING],
            wishes="окна во двор",
        ),
    ),
    (
        "ищу студию или однушку, бюджет от 5 до 7,5 млн, рядом школа и садик",
        ParsedQuery(rooms=[0, 1], price_min_mln=5, price_max_mln=7.5, nearby=["school", "kindergarten"]),
    ),
    ("хочу светлую квартиру с панорамными окнами", ParsedQuery(wishes="светлая, панорамные окна")),
    ("нас четверо, смотрим трёшку до 12 млн", ParsedQuery(rooms=[3], price_max_mln=12)),
    ("семья из пяти человек, бюджет 20 млн", ParsedQuery(price_max_mln=20)),
    (
        "однушка рядом с Тютчевской, не первый этаж",
        ParsedQuery(rooms=[1], metro_walk_max_min=WALKING_DISTANCE_MIN, not_first_floor=True),
    ),
    (
        "предчистовая, этаж с 3 по 12, до 11,2 млн, дом уже сдан",
        ParsedQuery(finishings=[Finishing.WHITE_BOX], floor_min=3, floor_max=12, price_max_mln=11.2, ready=True),
    ),
]


async def parse_query(llm: Llm, text: str, complexes: dict[str, int], today: date) -> SelfQueryResult:
    """Turn the buyer's text into filters; `complexes` are the developer's complexes by name."""
    messages = [{"role": "system", "content": _system_prompt(complexes, today)}]
    for example, parsed in EXAMPLES:
        messages.append({"role": "user", "content": example})
        messages.append({"role": "assistant", "content": parsed.model_dump_json(exclude_defaults=True)})
    messages.append({"role": "user", "content": text})

    parsed = await llm.structured(messages, ParsedQuery)

    return to_filters(parsed, complexes, today)


def to_filters(parsed: ParsedQuery, complexes: dict[str, int], today: date) -> SelfQueryResult:
    """Turn what the model extracted into filters: millions into rubles, a quarter into a date, names into IDs."""
    known = {_normalize(name): complex_id for name, complex_id in complexes.items()}
    complex_ids: list[int] = []
    unknown_complexes: list[str] = []
    for name in parsed.complexes:
        complex_id = _complex_id(name, known)
        if complex_id is None:
            unknown_complexes.append(name)
        elif complex_id not in complex_ids:
            complex_ids.append(complex_id)

    filters = FlatFilters(
        complex_ids=complex_ids,
        rooms=sorted(set(parsed.rooms)),
        is_euro=parsed.euro,
        price_from=_rubles(parsed.price_min_mln),
        price_to=_rubles(parsed.price_max_mln),
        area_from=_positive(parsed.area_min),
        area_to=_positive(parsed.area_max),
        floor_from=_clamp(parsed.floor_min, -5, 200),
        floor_to=_clamp(parsed.floor_max, -5, 200),
        not_first_floor=parsed.not_first_floor,
        not_last_floor=parsed.not_last_floor,
        deadline_to=_deadline(parsed, today),
        metro_walk_to=_clamp(parsed.metro_walk_max_min, 1, 60),
        nearby=sorted({PoiType(poi_type) for poi_type in parsed.nearby}),
        finishings=sorted(
            {
                finishing
                for chosen in parsed.finishings
                for finishing in (MOVE_IN_FINISHINGS if chosen == WITH_FINISHING else (Finishing(chosen),))
            }
        ),
    )
    wishes = parsed.wishes.strip() if parsed.wishes else None

    return SelfQueryResult(filters=filters, wishes=wishes or None, unknown_complexes=unknown_complexes)


def _system_prompt(complexes: dict[str, int], today: date) -> str:
    names = ", ".join(f"«{name}»" for name in complexes) or "нет"
    return SYSTEM_PROMPT.format(today=today.isoformat(), complexes=names)


def _complex_id(name: str, known: dict[str, int]) -> int | None:
    """Find a complex by its name, forgiving a typo or two: «Речная терасса» is still «Речная терраса»."""
    normalized = _normalize(name)
    if normalized in known:
        return known[normalized]
    matches = get_close_matches(normalized, known, n=1, cutoff=COMPLEX_NAME_SIMILARITY)
    return known[matches[0]] if matches else None


def _normalize(name: str) -> str:
    """Compare complex names regardless of case, «ё», quotes and the «ЖК» prefix."""
    name = name.casefold().replace("ё", "е")
    name = re.sub(r"[«»\"'“”„]", "", name)
    name = re.sub(r"^\s*(жк|жилой комплекс)\s+", "", name)
    return " ".join(name.split())


def _rubles(millions: float | None) -> int | None:
    if millions is None or millions <= 0:
        return None
    if millions > RUBLES_THRESHOLD:
        return round(millions)
    return round(millions * 1_000_000)


def _positive(value: float | None) -> float | None:
    return value if value is not None and value > 0 else None


def _clamp(value: int | None, lowest: int, highest: int) -> int | None:
    return None if value is None else max(lowest, min(highest, value))


def _deadline(parsed: ParsedQuery, today: date) -> date | None:
    """The latest completion date: the end of the named year or quarter, or today for a completed building."""
    deadlines: list[date] = []
    if parsed.ready:
        deadlines.append(today)
    if parsed.deadline_year is not None and 2000 <= parsed.deadline_year <= 2100:
        quarter = parsed.deadline_quarter or 4
        deadlines.append(_end_of_quarter(parsed.deadline_year, quarter))

    return min(deadlines, default=None)


def _end_of_quarter(year: int, quarter: int) -> date:
    month = quarter * 3
    day = 31 if month in (3, 12) else 30
    return date(year, month, day)
