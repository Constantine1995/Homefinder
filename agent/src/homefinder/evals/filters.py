"""How well the model turns buyers' phrases into filters: `uv run python -m homefinder.evals.filters`.

Each line of evals/filters.jsonl holds a phrase and its filters, with complexes by name. A field counts when the
labeled or the extracted filters set it, so fields that both leave empty do not inflate the score. The target is 95%
of fields right.
"""

import argparse
import asyncio
import json
import time
from collections import Counter
from dataclasses import dataclass, field
from datetime import UTC, date, datetime
from pathlib import Path
from typing import Any

from pydantic import BaseModel

from homefinder.config import get_settings
from homefinder.llm import Llm, LlmError
from homefinder.search.filters import FlatFilters
from homefinder.search.self_query import SelfQueryResult, parse_query

EVALS_DIR = Path(__file__).resolve().parents[3] / "evals"

TODAY = date(2026, 10, 20)
"""The date the relative deadlines of the set («в этом году», «готовое жильё») are labeled against."""

COMPLEXES = {"Отрадный квартал": 1, "Речная терраса": 2, "Тёплые холмы": 3}
"""The demo developer's complexes, as the set names them."""

TARGET = 0.95

FIELDS = [name for name in FlatFilters.model_fields if name != "statuses"]


class Case(BaseModel):
    text: str
    filters: dict[str, Any]
    wishes: bool = False
    unknown_complexes: bool = False

    def expected(self) -> FlatFilters:
        values = dict(self.filters)
        values["complex_ids"] = [COMPLEXES[name] for name in values.pop("complexes", [])]
        return FlatFilters.model_validate(values)


@dataclass
class Report:
    model: str
    counted: Counter[str] = field(default_factory=Counter)
    right: Counter[str] = field(default_factory=Counter)
    exact: int = 0
    wishes_right: int = 0
    failures: list[dict[str, Any]] = field(default_factory=list)
    errors: list[dict[str, str]] = field(default_factory=list)
    seconds: list[float] = field(default_factory=list)
    cases: int = 0

    @property
    def accuracy(self) -> float:
        counted = sum(self.counted.values())
        return sum(self.right.values()) / counted if counted else 1.0

    def add(self, case: Case, result: SelfQueryResult, seconds: float) -> None:
        self.cases += 1
        self.seconds.append(seconds)
        expected, actual = case.expected(), result.filters
        wrong: dict[str, Any] = {}
        for name in FIELDS:
            want, got = _comparable(getattr(expected, name)), _comparable(getattr(actual, name))
            default = _comparable(FlatFilters.model_fields[name].get_default(call_default_factory=True))
            if want == default and got == default:
                continue
            self.counted[name] += 1
            if want == got:
                self.right[name] += 1
            else:
                wrong[name] = {"expected": _jsonable(want), "actual": _jsonable(got)}
        if case.unknown_complexes != bool(result.unknown_complexes):
            wrong["unknown_complexes"] = {"expected": case.unknown_complexes, "actual": result.unknown_complexes}
        if case.wishes == (result.wishes is not None):
            self.wishes_right += 1
        if wrong:
            self.failures.append({"text": case.text, "wrong": wrong, "wishes": result.wishes})
        else:
            self.exact += 1

    def summary(self) -> dict[str, Any]:
        answered = self.cases or 1
        return {
            "model": self.model,
            "date": datetime.now(UTC).isoformat(timespec="seconds"),
            "phrases": self.cases + len(self.errors),
            "field_accuracy": round(self.accuracy, 4),
            "target": TARGET,
            "exact_match": round(self.exact / answered, 4),
            "wishes_detected": round(self.wishes_right / answered, 4),
            "errors": len(self.errors),
            "seconds_median": round(sorted(self.seconds)[len(self.seconds) // 2], 2) if self.seconds else None,
            "fields": {
                name: {"counted": self.counted[name], "accuracy": round(self.right[name] / self.counted[name], 4)}
                for name in FIELDS
                if self.counted[name]
            },
        }


def load(path: Path) -> list[Case]:
    return [Case.model_validate_json(line) for line in path.read_text().splitlines() if line.strip()]


async def run(cases: list[Case]) -> Report:
    settings = get_settings()
    llm = Llm(settings)
    report = Report(model=settings.llm_model)

    for number, case in enumerate(cases, 1):
        started = time.perf_counter()
        try:
            result = await parse_query(llm, case.text, COMPLEXES, TODAY)
        except LlmError as error:
            report.errors.append({"text": case.text, "error": str(error)})
            continue
        report.add(case, result, time.perf_counter() - started)
        print(f"\r{number}/{len(cases)}  field accuracy {report.accuracy:.1%}", end="", flush=True)

    print()
    return report


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--set", type=Path, default=EVALS_DIR / "filters.jsonl", help="the labeled phrases")
    parser.add_argument("--limit", type=int, help="run the first N phrases only")
    parser.add_argument("--save", action="store_true", help="write the report to evals/reports/")
    arguments = parser.parse_args()

    report = asyncio.run(run(load(arguments.set)[: arguments.limit]))
    summary = report.summary()
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    for failure in report.failures:
        print(json.dumps(failure, ensure_ascii=False))
    for error in report.errors:
        print(json.dumps(error, ensure_ascii=False))

    if arguments.save:
        reports = EVALS_DIR / "reports"
        reports.mkdir(exist_ok=True)
        name = f"filters-{summary['model'].replace('/', '_').replace(':', '-')}.json"
        content = {**summary, "failures": report.failures, "llm_errors": report.errors}
        (reports / name).write_text(json.dumps(content, ensure_ascii=False, indent=2) + "\n")

    raise SystemExit(0 if report.accuracy >= TARGET and not report.errors else 1)


def _comparable(value: Any) -> Any:
    return frozenset(value) if isinstance(value, list) else value


def _jsonable(value: Any) -> Any:
    if isinstance(value, frozenset):
        return sorted(str(item) for item in value)
    if isinstance(value, date):
        return value.isoformat()
    return value


if __name__ == "__main__":
    main()
