"""The verifier: checks by code that the reply says nothing the tools did not give.

Every price, area and flat number in the reply is in the tool results; no flat is sold at the time
of the reply; a mortgage payment comes from `mortgage_calc`. Rates in percent are checked too. A reply that fails is
written again with the problems named; the numbers the buyer wrote are allowed, as the reply may repeat them.

So are the numbers of the agent's earlier replies: each of them passed the verifier or was replaced with the fallback,
which has none. Asked about the payments it had named a turn before, the model repeated them without a tool, was
turned down twice, and the turn took longer than Laravel waits.
"""

import re
from collections.abc import Iterable, Iterator
from dataclasses import dataclass, field
from typing import Any

from pydantic import BaseModel

from homefinder.agent.state import Observation

_NUMBER = r"(\d{1,3}(?:[   ]\d{3})+|\d+)(?:[.,](\d+))?"
_MONEY = re.compile(
    _NUMBER + r"\s*(млрд|млн|миллион\w*|лям\w*|тыс\.?|тысяч\w*|т\.р\.)?\s*(₽|руб\w*|р\.)?", re.IGNORECASE
)
_AREA = re.compile(_NUMBER + r"\s*(?:м²|м2|кв\.\s*м|квадрат\w*|метр\w*\s+квадрат\w*)", re.IGNORECASE)
# «от 3 до 7 м²»: the first number of a range is an area too.
_AREA_RANGE = re.compile(_NUMBER + r"\s*(?:до|–|—|-)\s*" + _AREA.pattern, re.IGNORECASE)
_PERCENT = re.compile(_NUMBER + r"\s*%")
# «кв.» after a Roman numeral is a quarter, «сдача III кв. 2027», not a flat.
_FLAT_NUMBER = re.compile(r"(?:(?<![IV]\s)\bкв\.|\bквартир[аеуыой]+)\s*№?\s*(\d+)", re.IGNORECASE)
_MONTHLY = re.compile(r"в\s+месяц|ежемесячн|\bплатёж|\bплатеж", re.IGNORECASE)

_SCALES = {"млрд": 1_000_000_000, "млн": 1_000_000, "мил": 1_000_000, "лям": 1_000_000, "тыс": 1_000, "т.р": 1_000}
_ALLOWED_STATUSES = {"available", "reserved"}
_PERCENT_FIELDS = {"rate", "min_down_pct"}


class Verdict(BaseModel):
    ok: bool
    problems: list[str]


@dataclass
class _Facts:
    """The numbers the reply may use, gathered from the tool results and the buyer's own words."""

    amounts: set[float] = field(default_factory=set)
    areas: set[float] = field(default_factory=set)
    percents: set[float] = field(default_factory=set)
    payments: set[int] = field(default_factory=set)
    flat_numbers: dict[str, str] = field(default_factory=dict)
    """Flat number → status, of the flats the tools returned."""


def verify(
    reply: str, observations: list[Observation], buyer_texts: Iterable[str], earlier_replies: Iterable[str] = ()
) -> Verdict:
    facts = _facts(observations, buyer_texts, earlier_replies)
    problems: list[str] = []

    for value, tolerance, text in _amounts(reply):
        if value >= 1000 and not _known(value, tolerance, facts.amounts | facts.payments):
            problems.append(f"сумма «{text}» не из результатов инструментов")
    for value, tolerance, text in _matches(_AREA, reply):
        if not _known(value, tolerance, facts.areas):
            problems.append(f"площадь «{text}» не из результатов инструментов")
    for value, tolerance, text in _matches(_PERCENT, reply):
        if not _known(value, tolerance, facts.percents):
            problems.append(f"процент «{text}» не из результатов инструментов")
    for match in _FLAT_NUMBER.finditer(reply):
        flat_number = match.group(1)
        status = facts.flat_numbers.get(flat_number)
        if status is None:
            problems.append(f"квартиры № {flat_number} нет в результатах инструментов")
        elif status not in _ALLOWED_STATUSES:
            problems.append(f"квартира № {flat_number} уже продана или снята с продажи")
    if _MONTHLY.search(reply):
        for value, tolerance, text in _amounts(_monthly_sentences(reply)):
            if 1000 <= value < 1_000_000 and not _known(value, tolerance, {float(p) for p in facts.payments}):
                problems.append(f"платёж «{text}» не из mortgage_calc")

    return Verdict(ok=not problems, problems=list(dict.fromkeys(problems)))


def _facts(observations: list[Observation], buyer_texts: Iterable[str], earlier_replies: Iterable[str]) -> _Facts:
    facts = _Facts()
    texts = list(buyer_texts)
    for earlier in earlier_replies:
        texts.append(earlier)
        facts.payments.update(int(value) for value, _, _ in _amounts(_monthly_sentences(earlier)))

    for observation in observations:
        texts.append(observation.summary)
        data = observation.data
        for flat in _flats(data):
            if flat.get("number"):
                facts.flat_numbers[str(flat["number"])] = flat["status"]
            facts.areas.update(float(value) for key in ("area", "kitchen_area") if (value := flat.get(key)) is not None)
        if observation.tool == "mortgage_calc":
            for calculation in data.get("calculations", []):
                facts.payments.add(int(calculation["monthly_payment"]))
                facts.percents.update({float(calculation["rate"])})
        facts.amounts.update(float(value) for value in _numbers(data) if value >= 1000)
        facts.percents.update(_percents(data))

    for text in texts:
        facts.amounts.update(value for value, _, _ in _amounts(text))
        facts.areas.update(value for value, _, _ in _matches(_AREA, text))
        facts.areas.update(value for value, _, _ in _matches(_AREA_RANGE, text))
        facts.percents.update(value for value, _, _ in _matches(_PERCENT, text))

    return facts


def _flats(data: Any) -> Iterator[dict[str, Any]]:
    """Find the flats anywhere in a tool result: a search, a card, a comparison."""
    if isinstance(data, dict):
        if "price" in data and "status" in data and "area" in data:
            yield data
        for value in data.values():
            yield from _flats(value)
    elif isinstance(data, list):
        for item in data:
            yield from _flats(item)


def _percents(data: Any) -> Iterator[float]:
    """The rates and shares of a tool result. Any number up to 100 used to count, and a knowledge fragment's reranker
    score of 0.1 let through a made-up «компенсация 0,1% за месяц просрочки»: other percents are in the texts."""
    if isinstance(data, dict):
        for key, value in data.items():
            if key in _PERCENT_FIELDS and isinstance(value, int | float | str) and not isinstance(value, bool):
                yield float(value)
            else:
                yield from _percents(value)
    elif isinstance(data, list):
        for item in data:
            yield from _percents(item)


def _numbers(data: Any) -> Iterator[float]:
    if isinstance(data, bool):
        return
    if isinstance(data, int | float):
        yield float(data)
    elif isinstance(data, dict):
        for value in data.values():
            yield from _numbers(value)
    elif isinstance(data, list):
        for item in data:
            yield from _numbers(item)


def _amounts(text: str) -> list[tuple[float, float, str]]:
    """Find sums of money: «8 762 000 ₽», «8,76 млн», «9 лямов», «850 тыс. ₽»; bare numbers are not sums."""
    found = []
    for match in _MONEY.finditer(text):
        whole, fraction, scale, currency = match.groups()
        if scale is None and currency is None:
            continue
        multiplier = next((value for prefix, value in _SCALES.items() if scale and scale.lower().startswith(prefix)), 1)
        value, step = _value(whole, fraction)
        found.append((value * multiplier, step * multiplier / 2, match.group(0).strip()))
    return found


def _matches(pattern: re.Pattern[str], text: str) -> list[tuple[float, float, str]]:
    found = []
    for match in pattern.finditer(text):
        value, step = _value(match.group(1), match.group(2))
        found.append((value, step / 2, match.group(0).strip()))
    return found


def _value(whole: str, fraction: str | None) -> tuple[float, float]:
    """Read «8 762 000» or «8,76»; the step is the precision it was written with."""
    digits = re.sub(r"\D", "", whole)
    if fraction:
        return float(f"{digits}.{fraction}"), 10.0 ** -len(fraction)
    return float(digits), 1.0


def _known(value: float, tolerance: float, facts: set[float]) -> bool:
    """A number counts as known if a fact rounds to it at the precision it was written with."""
    return any(abs(fact - value) <= tolerance + 1e-6 for fact in facts)


def _monthly_sentences(reply: str) -> str:
    return " ".join(sentence for sentence in re.split(r"(?<=[.!?\n])\s*", reply) if _MONTHLY.search(sentence))
