"""The agent's tools: what the model may ask for, and how each request becomes a call with the tenant from context.

The model names a tool and its arguments; the tenant, the date and the prices of flats come from the context and the
database, never from the model. Each result has a short summary for the model and the data for the verifier.
"""

from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from datetime import date
from typing import Any

from psycopg import AsyncConnection
from pydantic import BaseModel, ConfigDict, Field

from homefinder.agent.state import DialogState, Observation
from homefinder.knowledge import search_knowledge
from homefinder.llm import Llm
from homefinder.mortgage import DISCLAIMER, MortgageCalculation, mortgage_calc
from homefinder.reranker import Reranker
from homefinder.search.compare import MAX_FLATS, MIN_FLATS, compare
from homefinder.search.explain import describe, money, number, plural, quarter, rooms_label, rubles
from homefinder.search.filters import FlatFilters, FlatStatus, PoiType
from homefinder.search.flats import DEFAULT_LIMIT, FlatMatch, cheapest_price, count_flats, get_flats, search_flats
from homefinder.search.relax import relax_filters
from homefinder.search.self_query import parse_query


@dataclass
class ToolContext:
    connection: AsyncConnection[Any]
    llm: Llm
    reranker: Reranker
    tenant_id: int
    today: date
    complexes: dict[str, int]
    """The tenant's complexes by name."""
    cards: bool = False
    """The channel shows the flats a search or a flat's card found as cards under the reply."""


class ToolError(Exception):
    """A tool was asked for something it cannot do; the message goes back to the model to correct itself."""


class _Args(BaseModel):
    model_config = ConfigDict(extra="forbid")


class SearchArgs(_Args):
    request: str = Field(description="что ищет покупатель, его словами, с учётом уточнений из диалога")


class RelaxArgs(_Args):
    pass


class FlatsArgs(_Args):
    flat_ids: list[int] = Field(min_length=1, max_length=MAX_FLATS)


class CompareArgs(_Args):
    flat_ids: list[int] = Field(min_length=MIN_FLATS, max_length=MAX_FLATS)


class MortgageArgs(_Args):
    flat_ids: list[int] = Field(
        [], max_length=MAX_FLATS, description="квартиры, если считаем для них, все сразу; цены возьмём из базы"
    )
    price: int | None = Field(None, description="цена в рублях, если квартира не выбрана")
    down_payment: int | None = Field(None, description="первоначальный взнос в рублях")
    years: int | None = None
    program: str | None = Field(None, description="программа, если покупатель назвал: «семейная»")


class KnowledgeArgs(_Args):
    question: str
    complex: str | None = Field(None, description="ЖК, о котором вопрос, если ясно из диалога")


class SaveSearchArgs(_Args):
    request: str = Field(
        description="о каких квартирах сообщать, словами покупателя; условия подбора из диалога — тоже"
    )


class HandoffArgs(_Args):
    reason: str


@dataclass(frozen=True)
class Tool:
    name: str
    description: str
    args: type[_Args]
    run: Callable[[ToolContext, DialogState, Any], Awaitable[Observation]]


async def _search(context: ToolContext, state: DialogState, args: SearchArgs) -> Observation:
    query = await parse_query(context.llm, args.request, context.complexes, context.today)
    # «покажите ещё», «а дешёвые варианты?» name no conditions: they are the dialog's, not «any flat».
    state.filters = _refined(state.filters, query.filters)
    state.wishes = query.wishes or state.wishes
    if query.filters.rooms:
        # The lead card's rooms are the ones the buyer searches for: the classifier hears budget and payment, not rooms.
        state.lead.rooms = list(query.filters.rooms)
    if len(query.filters.complex_ids) == 1:
        state.complex_id = query.filters.complex_ids[0]

    wishes = (await context.llm.embed([state.wishes]))[0] if state.wishes else None
    result = await search_flats(context.connection, context.tenant_id, state.filters, wishes=wishes)

    lines = [f"Найдено квартир: {result.total}." + (f" Показаны первые {len(result.flats)}." if result.flats else "")]
    complex_names = {complex_id: name for name, complex_id in context.complexes.items()}
    lines.append(f"Условия поиска: {describe(state.filters, complex_names) or 'любые квартиры в продаже'}.")
    # The flats shown are the closest to the wishes, not the cheapest: asked «а подешевле?», the model knew only them.
    cheapest = await cheapest_price(context.connection, context.tenant_id, state.filters) if result.total else None
    if cheapest is not None:
        lines.append(f"Дешевле {money(cheapest)} с этими условиями квартир нет: это самая низкая цена среди найденных.")
    lines += [_flat_line(flat) for flat in result.flats]
    if query.unknown_complexes:
        lines.append(f"У застройщика нет ЖК: {', '.join(query.unknown_complexes)}.")
    if result.total == 0:
        relaxations = await relax_filters(context.connection, context.tenant_id, state.filters)
        lines.append("Что можно ослабить: " + ("; ".join(r.description for r in relaxations) or "нечего"))

    return Observation(
        tool="search_flats",
        args=args.model_dump(),
        summary="\n".join(lines),
        data={
            "total": result.total,
            "cheapest_price": cheapest,
            "flats": [flat.model_dump(mode="json") for flat in result.flats],
        },
        flat_ids=[flat.id for flat in result.flats],
    )


_BOUNDS = (("price_from", "price_to"), ("area_from", "area_to"), ("floor_from", "floor_to"))
"""Conditions named together: «от 12 млн» in place of the dialog's «до 10 млн» is a new price, not 12 to 10."""


def _refined(dialog: FlatFilters, request: FlatFilters) -> FlatFilters:
    """The dialog's conditions with those the request names in their place. «А трёшки в Тёплых холмах?» after «до 10
    млн» kept only the rooms and the complex: the planner did not repeat the budget, the search showed flats for 15
    млн, and the reply called them «до 10 млн» after the buyer's words."""
    default = FlatFilters()
    named = {field for field in FlatFilters.model_fields if getattr(request, field) != getattr(default, field)}
    for bounds in _BOUNDS:
        if named & set(bounds):
            named |= set(bounds)
    return dialog.model_copy(update={field: getattr(request, field) for field in named})


async def _relax(context: ToolContext, state: DialogState, args: RelaxArgs) -> Observation:
    """Ways to find more when the conditions find too few. Asked «а подешевле?» after a search of 64 studios, the
    model eased the budget and offered dearer flats as the way out: when a page of flats is found, there is nothing
    to ease, and the result says how cheap the found flats get."""
    found = await count_flats(context.connection, context.tenant_id, state.filters)
    relaxations = (
        await relax_filters(context.connection, context.tenant_id, state.filters) if found < DEFAULT_LIMIT else []
    )
    lines = [f"- {relaxation.description}" for relaxation in relaxations]
    if (cheapest := await cheapest_price(context.connection, context.tenant_id, state.filters)) is not None:
        lines.insert(
            0,
            f"Дешевле {money(cheapest)} с этими условиями квартир нет; подходит "
            f"{found} {plural(found, 'квартира', 'квартиры', 'квартир')}."
            + (" Ослабление даёт больше вариантов и дороже:" if relaxations else " Ослаблять условия незачем."),
        )
    summary = "\n".join(lines) or "Ослабить нечего."
    return Observation(
        tool="relax_filters",
        args={},
        summary=summary,
        data={"relaxations": [relaxation.model_dump(mode="json") for relaxation in relaxations]},
    )


async def _get_flats(context: ToolContext, state: DialogState, args: FlatsArgs) -> Observation:
    flats = await _flats(context, state, args.flat_ids)
    return Observation(
        tool="get_flat",
        args=args.model_dump(),
        summary="\n".join(_flat_card(flat) for flat in flats),
        data={"flats": [flat.model_dump(mode="json") for flat in flats]},
        flat_ids=[flat.id for flat in flats],
    )


async def _compare(context: ToolContext, state: DialogState, args: CompareArgs) -> Observation:
    flats = await _flats(context, state, args.flat_ids)
    if len(flats) < MIN_FLATS:
        raise ToolError("для сравнения нужны две или три квартиры застройщика")
    comparison = compare(flats)
    rows = [f"{row.label}: " + " | ".join(row.values) for row in comparison.rows]
    header = " | ".join(f"[{flat.id}] кв. {flat.number}" for flat in flats)
    return Observation(
        tool="compare",
        args=args.model_dump(),
        summary="\n".join([header, *rows, *comparison.summary]),
        data=comparison.model_dump(mode="json"),
        flat_ids=[flat.id for flat in flats],
    )


async def _mortgage(context: ToolContext, state: DialogState, args: MortgageArgs) -> Observation:
    """The payment for each flat named, or for a price. «Посчитайте на эти квартиры» once took one call for the first
    flat, and the model wrote the payments of the others itself, which the verifier turned down. For several flats
    the summary says it shows the best program of each."""
    if args.flat_ids:
        flats = await _flats(context, state, args.flat_ids)
        # Shown as cards, the flats are not in the dialog's text: without its number the model named the flat by ID.
        subjects = [
            (f"Квартира: кв. {flat.number} в ЖК «{flat.complex_name}», цена {money(flat.price)}.", flat.price)
            for flat in flats
        ]
    elif args.price is not None:
        flats = []
        subjects = [(f"Цена {money(args.price)}.", args.price)]
    else:
        raise ToolError("нужны квартиры (flat_ids) или цена (price)")

    blocks, calculations = [], []
    for subject, price in subjects:
        result = await mortgage_calc(
            context.connection, context.tenant_id, context.today, price, args.down_payment, args.years, args.program
        )
        if result.unknown_program:
            return Observation(
                tool="mortgage_calc",
                args=args.model_dump(),
                summary=f"Программы «{result.unknown_program}» у застройщика нет.",
            )
        # The programs of three flats were twelve lines, and the model named none of the payments: for several flats
        # each gets the program that fits with the lowest payment.
        shown = result.calculations if len(subjects) == 1 else result.calculations[:1]
        blocks.append("\n".join([subject, *(_calculation_line(calc) for calc in shown)]))
        calculations += shown

    return Observation(
        tool="mortgage_calc",
        args=args.model_dump(),
        summary="\n\n".join(
            [
                *(
                    []
                    if len(subjects) == 1
                    else ["Для каждой квартиры — подходящая программа с самым низким платежом."]
                ),
                *blocks,
                DISCLAIMER,
            ]
        ),
        data={"calculations": [calc.model_dump(mode="json") for calc in calculations]},
        flat_ids=[flat.id for flat in flats],
    )


def _calculation_line(calc: MortgageCalculation) -> str:
    return (
        f"- {calc.program} ({calc.bank}), {number(calc.rate)}%, {calc.years} лет: "
        f"взнос {money(calc.down_payment)}, кредит {money(calc.loan)}, "
        f"платёж {rubles(calc.monthly_payment)} в месяц, переплата {money(calc.overpayment)}"
        + (f"; не подходит: {'; '.join(calc.problems)}" if calc.problems else "")
        + (f"; условия на {calc.actual_on:%d.%m.%Y}" if calc.actual_on else "")
    )


async def _knowledge(context: ToolContext, state: DialogState, args: KnowledgeArgs) -> Observation:
    complex_id = context.complexes.get(args.complex) if args.complex else state.complex_id
    result = await search_knowledge(
        context.connection, context.llm, context.reranker, context.tenant_id, args.question, complex_id
    )
    if not result.found:
        summary = "В базе знаний ответа нет. Скажи, что уточнишь у менеджера, и ничего не придумывай."
    else:
        summary = "\n\n".join(
            f"[{chunk.title} › {chunk.section.rsplit(' › ', 1)[-1]}] {chunk.text} (источник: {chunk.source})"
            for chunk in result.chunks
        )
        if result.quote:
            summary = f"Ответ: «{result.quote}»\n\n{summary}"
    return Observation(
        tool="search_knowledge",
        args=args.model_dump(),
        summary=summary,
        data=result.model_dump(mode="json", exclude={"ranking"}),
    )


async def _save_search(context: ToolContext, state: DialogState, args: SaveSearchArgs) -> Observation:
    """Subscribe the buyer by the conditions of the request, or by the dialog's when the request names none:
    «напишите, если появится» after a search means the flats of that search."""
    query = await parse_query(context.llm, args.request, context.complexes, context.today)
    if query.filters != FlatFilters():
        state.filters = query.filters
    if state.filters == FlatFilters():
        raise ToolError("непонятно, о каких квартирах сообщать: спроси, что ищет покупатель")

    description = describe(state.filters, {complex_id: name for name, complex_id in context.complexes.items()})
    total = await count_flats(context.connection, context.tenant_id, state.filters)
    return Observation(
        tool="save_search",
        args=args.model_dump(),
        summary=(
            f"Подписка оформлена: {description}. Подходящих квартир сейчас: {total}. "
            "О новых и подешевевших квартирах напишем не чаще раза в день; отписаться можно кнопкой под сообщением."
        ),
        data={"description": description, "filters": state.filters.model_dump(mode="json"), "total": total},
    )


async def _handoff(context: ToolContext, state: DialogState, args: HandoffArgs) -> Observation:
    return Observation(
        tool="handoff_to_human", args=args.model_dump(), summary=f"Диалог передан менеджеру: {args.reason}"
    )


TOOLS: dict[str, Tool] = {
    tool.name: tool
    for tool in [
        Tool("search_flats", "подобрать квартиры по запросу; до 5 вариантов с причинами", SearchArgs, _search),
        Tool(
            "relax_filters",
            "что ослабить в прежних условиях, если ничего не нашлось или покупатель хочет больше вариантов; "
            "бюджет здесь только растёт, для «подешевле» не подходит; новые условия — search_flats",
            RelaxArgs,
            _relax,
        ),
        Tool("get_flat", "карточки квартир по их id: цена, статус, отделка, окружение", FlatsArgs, _get_flats),
        Tool("compare", "сравнить 2–3 квартиры по id", CompareArgs, _compare),
        Tool("mortgage_calc", "ежемесячный платёж по ипотечным программам застройщика", MortgageArgs, _mortgage),
        Tool(
            "search_knowledge",
            "ответ из базы знаний: ЖК, паркинг, школы, покупка, документы",
            KnowledgeArgs,
            _knowledge,
        ),
        Tool(
            "save_search",
            "подписать покупателя на новые и подешевевшие квартиры по его условиям, когда он сам об этом просит",
            SaveSearchArgs,
            _save_search,
        ),
        Tool(
            "handoff_to_human",
            "передать диалог менеджеру: торг, трейд-ин, юрист, жалоба, просьба",
            HandoffArgs,
            _handoff,
        ),
    ]
}


async def _flats(context: ToolContext, state: DialogState, flat_ids: list[int]) -> list[FlatMatch]:
    """The flats the model names, from those shown in the dialog only: a small model passed a flat's number
    («кв. 52») as its id and got another flat's price."""
    unknown = [flat_id for flat_id in flat_ids if flat_id not in state.shown_flat_ids]
    if unknown:
        raise ToolError(f"квартиры с id {unknown} покупателю не показывали. {await shown_flats(context, state)}")
    flats = await get_flats(context.connection, context.tenant_id, flat_ids)
    if not flats:
        raise ToolError(f"у застройщика нет квартир с id {flat_ids}")
    return flats


async def shown_flats(context: ToolContext, state: DialogState) -> str:
    """The flats shown to the buyer by their place in the latest answer, «1-я — id 509, кв. 12», for «первая»."""
    if not state.shown_flat_ids:
        return "Квартиры ещё не показывали: сначала подбери их (search_flats)."
    flats = await get_flats(context.connection, context.tenant_id, state.shown_flat_ids)
    return "Показанные квартиры: " + "; ".join(
        f"{place}-я — id {flat.id}, кв. {flat.number}, {money(flat.price)}" for place, flat in enumerate(flats, 1)
    )


def _flat_line(flat: FlatMatch) -> str:
    metro = flat.nearest.get(PoiType.METRO)
    parts = [
        f"[{flat.id}] ЖК «{flat.complex_name}», {flat.building_name}, кв. {flat.number}",
        f"{rooms_label(flat.rooms, flat.is_euro)}, {number(flat.area)} м², этаж {flat.floor}"
        + (f" из {flat.floors}" if flat.floors else ""),
        money(flat.price),
    ]
    if flat.deadline:
        parts.append(f"сдача {quarter(flat.deadline)}")
    if metro:
        parts.append(f"м. «{metro.name}» {metro.walk_min} мин пешком")
    line = "; ".join(parts)
    if flat.reasons:
        line += f". Почему подходит: {'; '.join(flat.reasons)}"
    return line


def _flat_card(flat: FlatMatch) -> str:
    status = {FlatStatus.AVAILABLE: "свободна", FlatStatus.RESERVED: "забронирована"}.get(
        flat.status, "продана или снята с продажи — не предлагать"
    )
    lines = [_flat_line(flat), f"Статус: {status}. Цена за м²: {money(flat.price_per_meter)}."]
    if flat.kitchen_area:
        lines.append(f"Кухня {number(flat.kitchen_area)} м².")
    if flat.description:
        lines.append(flat.description)
    if flat.plan_url:
        lines.append(f"Планировка: {flat.plan_url}")
    return " ".join(lines)
