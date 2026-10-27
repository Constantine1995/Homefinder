"""How the agent holds whole dialogs: `uv run python -m homefinder.evals.dialogs`.

Each line of evals/dialogs.jsonl is a scenario: a persona and a goal, as in the buyer simulator, and the
buyer's messages, each with what the turn has to do — the tools it calls, the flats it shows, the search conditions it
keeps, whether it hands the dialog over or finds no answer in the knowledge base, what the reply says or must not say.
All of it is checked by code; the verifier's own verdict counts too: a turn that ended with the fallback failed.

The turns are played in order through the agent's graph, carrying the dialog state and the history as Laravel does;
after a handoff the AI stops answering, so the turns after it are not played. The run needs the database with the
demo developer, Ollama and the reranker, and only reads. `--model` compares models on the same scenarios.
"""

import argparse
import asyncio
import json
import re
import statistics
import time
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import UTC, date, datetime
from pathlib import Path
from typing import Any

from psycopg import AsyncConnection
from pydantic import BaseModel, ConfigDict

from homefinder.agent.graph import TurnResult, run_turn
from homefinder.agent.state import DialogState, Intent, Turn
from homefinder.agent.tools import ToolContext
from homefinder.config import Settings, get_settings
from homefinder.evals.filters import COMPLEXES, EVALS_DIR
from homefinder.llm import Llm, LlmError
from homefinder.reranker import Reranker
from homefinder.search.complexes import complexes_by_name

TODAY = date(2026, 10, 22)
"""The date the scenarios are written against: «сдача в этом году» and the validity of mortgage programs."""

TARGET = 0.85
"""The target for whole dialogs: the goal of the scenario reached."""

CLIENT_TIMEOUT = 120
"""Seconds Laravel waits for a turn (AGENT_TIMEOUT); a longer turn hands the dialog over to a manager."""


class Expectation(BaseModel):
    """One message of the buyer and what the agent's turn has to do with it; what is left out is not checked."""

    model_config = ConfigDict(extra="forbid")

    buyer: str
    intent: Intent | list[Intent] | None = None
    """Reported as intent accuracy, not failed on: the tools and the handoff show what the turn did."""
    tools: list[str] | None = None
    """Tools the turn has to call; an empty list means none at all."""
    tools_any: list[str] = []
    """At least one of these tools."""
    handoff: bool | None = False
    """Whether the turn hands the dialog over; None when either is right, as for a prompt injection."""
    gap: bool | None = None
    """True when the knowledge base has no answer and the turn has to say so and ask a manager."""
    shows_flats: bool | None = None
    filters: dict[str, Any] = {}
    """Search conditions the dialog keeps after the turn, with complexes by name."""
    reply_has: list[str] = []
    """Patterns the reply must match."""
    reply_lacks: list[str] = []
    """Patterns the reply must not match."""


class Scenario(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    persona: str
    goal: str
    turns: list[Expectation]


def check(expected: Expectation, result: TurnResult) -> list[str]:
    """What the turn did wrong; the reply that failed the verifier is the fallback, the turn fails with it."""
    problems = []
    called = [step.tool for step in result.steps]
    actions = {action.type for action in result.actions}

    if not result.verified:
        problems.append("ответ не прошёл верификатор: " + "; ".join(result.problems))
    if expected.tools == [] and called:
        problems.append(f"инструменты не нужны, вызваны {called}")
    for tool in expected.tools or []:
        if tool not in called:
            problems.append(f"не вызван {tool}, вызваны {called}")
    if expected.tools_any and not set(expected.tools_any) & set(called):
        problems.append(f"не вызван ни один из {expected.tools_any}, вызваны {called}")
    if expected.handoff is not None and expected.handoff != ("handoff" in actions):
        problems.append("не передан менеджеру" if expected.handoff else "передан менеджеру без причины")
    if expected.gap is not None and expected.gap != ("knowledge_gap" in actions):
        problems.append("ответ из базы знаний, которого в ней нет" if expected.gap else "ответ в базе не найден")
    if expected.shows_flats is not None:
        shown = any(step.flat_ids for step in result.steps)
        if expected.shows_flats != shown:
            problems.append(
                "квартиры не показаны" if expected.shows_flats else "показаны квартиры, которых быть не должно"
            )
    problems += _filter_problems(expected.filters, result.dialog)
    problems += [f"в ответе нет /{pattern}/" for pattern in expected.reply_has if not re.search(pattern, result.reply)]
    problems += [f"в ответе есть /{pattern}/" for pattern in expected.reply_lacks if re.search(pattern, result.reply)]
    return problems


def _filter_problems(expected: dict[str, Any], dialog: DialogState) -> list[str]:
    values = dict(expected)
    if "complexes" in values:
        values["complex_ids"] = [COMPLEXES[name] for name in values.pop("complexes")]
    actual = dialog.filters.model_dump(mode="json")
    problems = []
    for name, value in values.items():
        have = actual[name]
        same = sorted(have) == sorted(value) if isinstance(value, list) else have == value
        if not same:
            problems.append(f"условие {name}: {have!r} вместо {value!r}")
    return problems


async def play(context: ToolContext, scenario: Scenario) -> dict[str, Any]:
    """Play the buyer's messages one by one, as Laravel would send them."""
    dialog = DialogState()
    history: list[Turn] = []
    turns: list[dict[str, Any]] = []

    for expected in scenario.turns:
        started = time.perf_counter()
        try:
            result = await run_turn(context, dialog, history, expected.buyer)
        except LlmError as error:
            # The service answers 503 and Laravel hands the dialog over: the scenario ends here.
            turns.append({"buyer": expected.buyer, "error": str(error), "problems": ["модель не ответила"]})
            break
        seconds = round(time.perf_counter() - started, 1)
        problems = check(expected, result)
        if seconds > CLIENT_TIMEOUT:
            problems.append(f"ход {seconds} с — дольше, чем ждёт Laravel")
        turns.append(
            {
                "buyer": expected.buyer,
                "reply": result.reply,
                "intent": result.intent,
                "intent_expected": expected.intent,
                "intent_right": _intent_right(expected.intent, result.intent),
                "tools": [{"tool": step.tool, "args": step.args} for step in result.steps],
                "actions": [action.model_dump() for action in result.actions],
                "handoff_expected": expected.handoff,
                "verified": result.verified,
                "attempts": result.attempts,
                "verifier": result.problems,
                "seconds": seconds,
                "prompt_tokens": result.prompt_tokens,
                "completion_tokens": result.completion_tokens,
                "problems": problems,
            }
        )
        history += [Turn(role="user", text=expected.buyer), Turn(role="assistant", text=result.reply)]
        dialog = result.dialog
        if any(action.type == "handoff" for action in result.actions):
            break

    played_all = len(turns) == len(scenario.turns)
    return {
        "name": scenario.name,
        "passed": played_all and not any(turn["problems"] for turn in turns),
        "not_played": len(scenario.turns) - len(turns),
        "turns": turns,
    }


def _intent_right(expected: Intent | list[Intent] | None, actual: Intent) -> bool | None:
    if expected is None:
        return None
    return actual in expected if isinstance(expected, list) else actual == expected


@asynccontextmanager
async def tool_context(settings: Settings, tenant_id: int) -> AsyncIterator[ToolContext]:
    """The agent's tools on the demo developer's catalog, in a read-only session, on the date of the scenarios."""
    async with await AsyncConnection.connect(
        str(settings.database_url),
        options="-c default_transaction_read_only=on -c hnsw.iterative_scan=relaxed_order",
        autocommit=True,
    ) as connection:
        yield ToolContext(
            connection=connection,
            llm=Llm(settings),
            reranker=Reranker(settings),
            tenant_id=tenant_id,
            today=TODAY,
            complexes=await complexes_by_name(connection, tenant_id),
        )


async def run(scenarios: list[Scenario], settings: Settings, tenant_id: int) -> dict[str, Any]:
    results = []

    async with tool_context(settings, tenant_id) as context:
        for number, scenario in enumerate(scenarios, 1):
            results.append(await play(context, scenario))
            mark = "✓" if results[-1]["passed"] else "✗"
            print(f"{number}/{len(scenarios)} {mark} {scenario.name}", flush=True)
            for turn in results[-1]["turns"]:
                if turn["problems"]:
                    print(f"    «{turn['buyer']}»: {'; '.join(turn['problems'])}", flush=True)

    return summarize(results, settings)


def summarize(results: list[dict[str, Any]], settings: Settings) -> dict[str, Any]:
    turns = [turn for result in results for turn in result["turns"] if "error" not in turn]
    errors = sum("error" in turn for result in results for turn in result["turns"])
    seconds = sorted(turn["seconds"] for turn in turns)
    intents = [turn["intent_right"] for turn in turns if turn["intent_right"] is not None]
    for turn in turns:
        turn["handed_off"] = any(action["type"] == "handoff" for action in turn["actions"])
    handoffs_expected = [turn["handed_off"] for turn in turns if turn["handoff_expected"] is True]

    return {
        "model": settings.llm_model,
        "reasoning_effort": settings.llm_reasoning_effort,
        "date": datetime.now(UTC).isoformat(timespec="seconds"),
        "scenarios": len(results),
        "scenarios_passed": round(sum(result["passed"] for result in results) / len(results), 4),
        "target": TARGET,
        "turns": len(turns),
        "turns_passed": round(sum(not turn["problems"] for turn in turns) / len(turns), 4),
        "verified": round(sum(turn["verified"] for turn in turns) / len(turns), 4),
        "first_attempt": round(sum(turn["verified"] and turn["attempts"] == 1 for turn in turns) / len(turns), 4),
        "fallbacks": sum(not turn["verified"] for turn in turns),
        "model_errors": errors,
        "intent_accuracy": round(sum(intents) / len(intents), 4) if intents else None,
        "handoff_recall": round(sum(handoffs_expected) / len(handoffs_expected), 4) if handoffs_expected else None,
        "handoffs_unexpected": sum(turn["handed_off"] and turn["handoff_expected"] is False for turn in turns),
        "seconds_median": statistics.median(seconds),
        "seconds_p90": seconds[int(len(seconds) * 0.9)],
        "seconds_max": seconds[-1],
        "over_client_timeout": sum(value > CLIENT_TIMEOUT for value in seconds),
        "prompt_tokens_per_turn": round(statistics.mean(turn["prompt_tokens"] for turn in turns)),
        "completion_tokens_per_turn": round(statistics.mean(turn["completion_tokens"] for turn in turns)),
        "failures": [
            {"name": result["name"], "buyer": turn["buyer"], "problems": turn["problems"], "reply": turn.get("reply")}
            for result in results
            for turn in result["turns"]
            if turn["problems"]
        ],
        "results": results,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0] if __doc__ else None)
    parser.add_argument("--set", type=Path, default=EVALS_DIR / "dialogs.jsonl")
    parser.add_argument("--tenant", type=int, default=1, help="the demo developer")
    parser.add_argument("--model", help="a LiteLLM model name instead of AGENT_LLM_MODEL, e.g. ollama_chat/qwen3:8b")
    parser.add_argument("--reasoning-effort", help="e.g. `none` to turn off the thinking of qwen3")
    parser.add_argument("--only", help="play the scenarios whose name contains this")
    parser.add_argument("--save", action="store_true", help="write the report to evals/reports/")
    arguments = parser.parse_args()

    settings = get_settings()
    overrides = {"llm_model": arguments.model, "llm_reasoning_effort": arguments.reasoning_effort}
    settings = settings.model_copy(update={key: value for key, value in overrides.items() if value is not None})
    scenarios = [Scenario.model_validate_json(line) for line in arguments.set.read_text().splitlines() if line.strip()]
    if arguments.only:
        scenarios = [scenario for scenario in scenarios if arguments.only in scenario.name]

    report = asyncio.run(run(scenarios, settings, arguments.tenant))

    print(json.dumps({key: value for key, value in report.items() if key not in ("failures", "results")}, indent=2))
    for failure in report["failures"]:
        print(json.dumps(failure, ensure_ascii=False))

    if arguments.save:
        reports = EVALS_DIR / "reports"
        reports.mkdir(exist_ok=True)
        name = f"dialogs-{settings.llm_model.replace('/', '_').replace(':', '-')}.json"
        (reports / name).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")

    raise SystemExit(0 if report["scenarios_passed"] >= TARGET else 1)


if __name__ == "__main__":
    main()
