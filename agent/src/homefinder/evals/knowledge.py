"""How well search_knowledge answers: `uv run python -m homefinder.evals.knowledge`.

Each line of evals/knowledge.jsonl holds a buyer's question, optionally the complex the dialog is about, and the
sections that answer it as [document, section] — the document is the last part of its source URL; questions the
knowledge base cannot answer have none. The targets: Recall@5 ≥ 0.9, and «уточню» rather than a made-up
answer on the questions without one. The run needs the database with the demo knowledge base, Ollama and the
reranker, and only reads.
"""

import argparse
import asyncio
import json
import time
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from psycopg import AsyncConnection
from pydantic import BaseModel

from homefinder.config import get_settings
from homefinder.evals.filters import EVALS_DIR
from homefinder.knowledge import RELEVANCE_THRESHOLD, RESULTS, search_knowledge
from homefinder.llm import Llm
from homefinder.reranker import Reranker
from homefinder.search.complexes import complexes_by_name

TARGET_RECALL = 0.9


class Question(BaseModel):
    question: str
    complex: str | None = None
    expected: list[tuple[str, str]]


def label(source: str, section: str) -> tuple[str, str]:
    """Name a fragment as the set does: the last part of its document's source and its own heading."""
    return source.rstrip("/").rsplit("/", 1)[-1], section.rsplit(" › ", 1)[-1]


async def run(questions: list[Question], tenant_id: int, threshold: float) -> dict[str, Any]:
    settings = get_settings()
    llm, reranker = Llm(settings), Reranker(settings)
    rows: list[dict[str, Any]] = []

    async with await AsyncConnection.connect(
        str(settings.database_url),
        options="-c default_transaction_read_only=on -c hnsw.iterative_scan=relaxed_order",
        autocommit=True,
    ) as connection:
        complexes = await complexes_by_name(connection, tenant_id)
        for number, item in enumerate(questions, 1):
            complex_id = complexes[item.complex] if item.complex else None
            started = time.perf_counter()
            result = await search_knowledge(connection, llm, reranker, tenant_id, item.question, complex_id, threshold)
            expected = {tuple(pair) for pair in item.expected}
            found = [label(chunk.source, chunk.section) for chunk in result.chunks]
            rows.append(
                {
                    "question": item.question,
                    "complex": item.complex,
                    "expected": sorted(expected),
                    "answerable": bool(expected),
                    "found": result.found,
                    "hit": bool(expected & set(found)),
                    "rewritten": result.query if result.rewritten else None,
                    "results": [[*label(chunk.source, chunk.section), chunk.score] for chunk in result.chunks],
                    "ranked": [[*label(item.source, item.section), item.score] for item in result.ranking],
                    "seconds": round(time.perf_counter() - started, 2),
                }
            )
            print(f"\r{number}/{len(questions)}", end="", flush=True)

    print()
    return summarize(rows, settings.llm_model, threshold)


def summarize(rows: list[dict[str, Any]], model: str, threshold: float) -> dict[str, Any]:
    answerable = [row for row in rows if row["answerable"]]
    unanswerable = [row for row in rows if not row["answerable"]]
    seconds = sorted(row["seconds"] for row in rows)

    return {
        "model": model,
        "date": datetime.now(UTC).isoformat(timespec="seconds"),
        "threshold": threshold,
        "questions": len(rows),
        "recall_at_5": round(sum(row["hit"] for row in answerable) / len(answerable), 4),
        "target": TARGET_RECALL,
        "abstained_without_answer": round(sum(not row["found"] for row in unanswerable) / len(unanswerable), 4),
        "rewritten": sum(row["rewritten"] is not None for row in rows),
        "seconds_median": seconds[len(seconds) // 2],
        "thresholds": sweep(rows),
        "misses": [row for row in rows if row["answerable"] and not row["hit"]],
        "made_up": [row for row in rows if not row["answerable"] and row["found"]],
    }


def sweep(rows: list[dict[str, Any]]) -> list[dict[str, float]]:
    """Recall and abstention at other thresholds, from the first ranking of each question: no rewrites counted."""
    table = []
    for threshold in (-4.0, -3.0, -2.0, -1.0, -0.5, 0.0, 0.5, 1.0, 2.0):
        hits = abstained = 0
        for row in rows:
            above = [(document, section) for document, section, score in row["ranked"] if score >= threshold]
            if row["answerable"]:
                hits += bool(set(map(tuple, row["expected"])) & set(above[:RESULTS]))
            else:
                abstained += not above
        answerable = sum(row["answerable"] for row in rows)
        table.append(
            {
                "threshold": threshold,
                "recall_at_5": round(hits / answerable, 4),
                "abstained": round(abstained / (len(rows) - answerable), 4),
            }
        )
    return table


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0] if __doc__ else None)
    parser.add_argument("--set", type=Path, default=EVALS_DIR / "knowledge.jsonl")
    parser.add_argument("--tenant", type=int, default=1, help="the developer whose knowledge base is searched")
    parser.add_argument("--threshold", type=float, default=RELEVANCE_THRESHOLD)
    parser.add_argument("--limit", type=int)
    parser.add_argument("--save", action="store_true", help="write the report to evals/reports/")
    arguments = parser.parse_args()

    questions = [Question.model_validate_json(line) for line in arguments.set.read_text().splitlines() if line.strip()]
    report = asyncio.run(run(questions[: arguments.limit], arguments.tenant, arguments.threshold))

    print(json.dumps({key: value for key, value in report.items() if key not in ("misses", "made_up")}, indent=2))
    for row in report["misses"] + report["made_up"]:
        print(
            json.dumps(
                {key: row[key] for key in ("question", "complex", "expected", "results", "rewritten")},
                ensure_ascii=False,
            )
        )

    if arguments.save:
        reports = EVALS_DIR / "reports"
        reports.mkdir(exist_ok=True)
        (reports / "knowledge.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")

    passed = report["recall_at_5"] >= TARGET_RECALL and not report["made_up"]
    raise SystemExit(0 if passed else 1)


if __name__ == "__main__":
    main()
