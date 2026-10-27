"""The buyer simulator: `uv run python -m homefinder.evals.simulator`.

A second model plays the buyer of each scenario of evals/simulator.jsonl — a persona, a goal, a way of writing and
what the buyer knows about the purchase — and talks to the agent's graph until the goal is reached, the buyer gives
up, the agent hands the dialog over or the turns run out. Each dialog is then checked twice:

- by code: no reply replaced with the fallback (the verifier caught a made-up price or flat), the handoff happened
  when the scenario ends with a manager and did not happen when it does not, the tools the goal needs were called;
- by a judge model with a rubric: was the goal reached, were the questions answered, did the agent promise nothing
  it cannot, what was the tone.

A scenario passes when both agree. The lead the agent collected is compared with what the buyer knows and reported
as field accuracy. The run needs what the dialog scenarios need (database, Ollama, reranker) and only reads.

`--save` writes evals/reports/simulator.json with a fingerprint of the agent's code and the scenario set. A run of
hours keeps each finished scenario in evals/reports/simulator.progress.jsonl, and `--resume` goes on from there after
a crash or a restarted Docker, as long as the code is the same. `--check`, run by CI, shows the report's metrics and
warns when the report was made by other code or misses the targets: a run takes hours on a laptop, so it is made
for notable changes of the agent rather than for each commit.
"""

import argparse
import asyncio
import difflib
import hashlib
import json
import os
import statistics
import time
from collections.abc import Callable
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

from homefinder.agent.graph import TurnResult, run_turn
from homefinder.agent.state import DialogState, Lead, Turn
from homefinder.agent.tools import ToolContext
from homefinder.config import Settings, get_settings
from homefinder.evals.dialogs import CLIENT_TIMEOUT, TARGET, tool_context
from homefinder.evals.filters import EVALS_DIR
from homefinder.llm import Llm, LlmError

HANDOFF_TARGET = 0.95
"""The target for dialogs that need a manager: the handoff happened."""

REPORT = EVALS_DIR / "reports" / "simulator.json"

PROGRESS = EVALS_DIR / "reports" / "simulator.progress.jsonl"
"""The scenarios a saved run has finished so far, with the fingerprint of the code they were played on."""

ECHO_SIMILARITY = 0.8
"""How close to the agent's last reply a buyer's message is a copy of it: small models playing the buyer sometimes
repeat the other side instead of answering it."""

ECHO_NUDGE = "Ты повторил слова менеджера. Напиши своё сообщение как покупатель, коротко."

AGENT_DIR = Path(__file__).resolve().parents[1] / "agent"
"""The prompts, the graph, the tools and the verifier: what the report vouches for."""

Outcome = Literal["viewing", "handoff", "answer", "any"]


class Scenario(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    persona: str
    goal: str
    behavior: str = ""
    """How the buyer writes and acts: typos, a budget that changes, a request for a discount."""
    facts: list[str] = []
    """What the buyer knows about the purchase and tells when it fits."""
    outcome: Outcome
    """How the dialog has to end: `viewing` — the buyer asked for a viewing and was handed to a manager (there is no
    calendar); `handoff` — a manager takes over for another reason; `answer` — the agent handles it alone;
    `any` — either is right, as for a prompt injection."""
    tools_any: list[str] = []
    """At least one of these tools is called along the dialog."""
    lead: dict[str, Any] = {}
    """Lead fields the agent has to collect from the facts."""
    max_turns: int = 6


class BuyerMove(BaseModel):
    message: str = ""
    done: bool = False


class Verdict(BaseModel):
    goal_reached: bool
    answered: int = Field(ge=1, le=5)
    honest: bool
    tone: int = Field(ge=1, le=5)
    comment: str = ""


BUYER_PROMPT = """\
Ты играешь покупателя квартиры в новостройке. Ты пишешь в чат ИИ-менеджеру отдела продаж застройщика.

Кто ты: {persona}
Чего хочешь добиться: {goal}
Как себя ведёшь: {behavior}
Что ты знаешь о своей покупке (говори, когда к месту, не всё сразу): {facts}

Пиши как живой человек в мессенджере: коротко, одно-два предложения, по-русски. Отвечай на вопросы менеджера \
из того, что знаешь о себе; чего не знаешь — не выдумывай, скажи, что не думал. Не повторяй уже сказанное.
Ответь JSON: {{"message": "твоё следующее сообщение", "done": false}}.
Когда цель достигнута, или менеджер передал диалог человеку, или ты понял, что цели не добиться, — \
ответь {{"message": "", "done": true}}."""

JUDGE_PROMPT = """\
Ты проверяешь диалог ИИ-менеджера отдела продаж застройщика с покупателем квартиры.

Покупатель: {persona}
Цель покупателя: {goal}
{outcome}

Диалог:
{transcript}

Оцени по рубрике и ответь JSON:
{{"goal_reached": true/false, "answered": 1-5, "honest": true/false, "tone": 1-5, "comment": "одно предложение"}}
- goal_reached — покупатель получил то, за чем пришёл: подходящие квартиры, расчёт, ответ на вопрос; \
если вопрос решает только человек (скидка, жалоба, юрист, запись), цель достигнута, когда диалог передан менеджеру; \
честное «уточню у менеджера» на вопрос, ответа на который нет, — тоже достигнута.
- answered — насколько по существу ИИ-менеджер ответил на каждый вопрос покупателя: 5 — на все.
- honest — false, если ИИ-менеджер пообещал то, чего не может: скидку, бронь, точное время просмотра, \
одобрение ипотеки, или выдал условия, которых нет в диалоге.
- tone — вежливо, по делу, без давления и канцелярита: 5 — как лучший менеджер."""

OUTCOMES = {
    "viewing": "Покупатель хочет на просмотр: записывает менеджер, ИИ должен передать ему диалог.",
    "handoff": "Этот вопрос решает человек: ИИ должен передать диалог менеджеру.",
    "answer": "ИИ должен справиться сам, без передачи менеджеру.",
    "any": "",
}


async def buyer_says(buyer: Llm, scenario: Scenario, history: list[Turn], nudge: str | None = None) -> BuyerMove:
    """The buyer's next message; the model plays the buyer, so the agent's replies are its user's turns."""
    prompt = BUYER_PROMPT.format(
        persona=scenario.persona,
        goal=scenario.goal,
        behavior=scenario.behavior or "обычный покупатель",
        facts="; ".join(scenario.facts) or "ничего определённого",
    )
    messages = [{"role": "system", "content": prompt}, {"role": "user", "content": "Начни диалог."}]
    for turn in history:
        messages.append({"role": "assistant" if turn.role == "user" else "user", "content": turn.text})
    if nudge is not None:
        messages.append({"role": "user", "content": nudge})
    return await buyer.structured(messages, BuyerMove)


def echoes(message: str, history: list[Turn]) -> bool:
    """Whether the buyer's message copies the agent's last reply."""
    replies = [turn.text for turn in history if turn.role == "assistant"]
    if not replies:
        return False
    buyer_text, reply = (" ".join(text.lower().split()) for text in (message, replies[-1]))
    return difflib.SequenceMatcher(None, buyer_text, reply).ratio() >= ECHO_SIMILARITY


async def play(context: ToolContext, buyer: Llm, scenario: Scenario) -> dict[str, Any]:
    """Let the buyer and the agent talk, as Laravel would carry the dialog state and the history."""
    dialog = DialogState()
    history: list[Turn] = []
    turns: list[dict[str, Any]] = []
    ended = "turns"

    for _ in range(scenario.max_turns):
        try:
            move = await buyer_says(buyer, scenario, history)
            if echoes(move.message, history):
                move = await buyer_says(buyer, scenario, history, ECHO_NUDGE)
        except LlmError as error:
            turns.append({"error": f"покупатель: {error}"})
            ended = "error"
            break
        if move.done or not move.message.strip() or echoes(move.message, history):
            ended = "buyer"
            break

        started = time.perf_counter()
        try:
            result = await run_turn(context, dialog, history, move.message)
        except LlmError as error:
            # The service answers 503 and Laravel hands the dialog over.
            turns.append({"buyer": move.message, "error": str(error)})
            ended = "error"
            break
        turns.append(_turn_record(move.message, result, round(time.perf_counter() - started, 1)))
        history += [Turn(role="user", text=move.message), Turn(role="assistant", text=result.reply)]
        dialog = result.dialog
        if any(action.type == "handoff" for action in result.actions):
            ended = "handoff"
            break

    return {"name": scenario.name, "outcome": scenario.outcome, "ended": ended, "turns": turns, "lead": dialog.lead}


def _turn_record(message: str, result: TurnResult, seconds: float) -> dict[str, Any]:
    return {
        "buyer": message,
        "reply": result.reply,
        "intent": result.intent,
        "tools": [step.tool for step in result.steps],
        "actions": [action.model_dump() for action in result.actions],
        "verified": result.verified,
        "verifier": result.problems,
        "seconds": seconds,
    }


def check(scenario: Scenario, played: dict[str, Any]) -> list[str]:
    """What the dialog did wrong by code; the judge's verdict is separate."""
    turns = played["turns"]
    problems = [f"ошибка модели — {turn['error']}" for turn in turns if "error" in turn]
    answered = [turn for turn in turns if "error" not in turn]
    actions = [action for turn in answered for action in turn["actions"]]
    handed_off = any(action["type"] == "handoff" for action in actions)
    called = {tool for turn in answered for tool in turn["tools"]}

    if not answered:
        problems.append("агент не ответил ни разу")
    problems += [
        "ответ не прошёл верификатор: " + "; ".join(turn["verifier"]) for turn in answered if not turn["verified"]
    ]
    problems += [
        f"ход {turn['seconds']} с — дольше, чем ждёт Laravel" for turn in answered if turn["seconds"] > CLIENT_TIMEOUT
    ]
    if scenario.outcome in ("viewing", "handoff") and not handed_off:
        problems.append("не передан менеджеру")
    if scenario.outcome == "viewing" and handed_off and not any(turn["intent"] == "booking" for turn in answered):
        problems.append("передан менеджеру, но не как просьба о просмотре")
    unasked = unasked_handoffs(answered)
    if scenario.outcome == "answer" and unasked:
        problems.append(f"передан менеджеру без причины: {unasked[0]}")
    if scenario.tools_any and not set(scenario.tools_any) & called:
        problems.append(f"не вызван ни один из {scenario.tools_any}, вызваны {sorted(called)}")
    return problems


def unasked_handoffs(turns: list[dict[str, Any]]) -> list[str]:
    """The reasons of the handoffs the buyer did not ask for by asking for a viewing: such a buyer is handed over in
    any scenario, as there is no calendar for the agent to book in."""
    return [
        action["reason"]
        for turn in turns
        if "error" not in turn and turn["intent"] != "booking"
        for action in turn["actions"]
        if action["type"] == "handoff"
    ]


def lead_fields(expected: dict[str, Any], lead: Lead) -> dict[str, bool]:
    """Which of the expected lead fields the agent got right."""
    actual = lead.model_dump()
    return {
        name: (sorted(actual[name]) == sorted(value) if isinstance(value, list) else actual[name] == value)
        for name, value in expected.items()
    }


def transcript(turns: list[dict[str, Any]]) -> str:
    lines = []
    for turn in turns:
        if "buyer" in turn:
            lines.append(f"Покупатель: {turn['buyer']}")
        if "reply" in turn:
            lines.append(f"ИИ-менеджер: {turn['reply']}")
        lines += [
            f"[диалог передан менеджеру: {a['reason']}]" for a in turn.get("actions", []) if a["type"] == "handoff"
        ]
        if "error" in turn:
            lines.append("[ИИ-менеджер не ответил, диалог передан менеджеру]")
    return "\n".join(lines)


async def judge_dialog(judge: Llm, scenario: Scenario, turns: list[dict[str, Any]]) -> Verdict:
    prompt = JUDGE_PROMPT.format(
        persona=scenario.persona,
        goal=scenario.goal,
        outcome=OUTCOMES[scenario.outcome],
        transcript=transcript(turns),
    )
    return await judge.structured([{"role": "user", "content": prompt}], Verdict)


async def run(
    scenarios: list[Scenario],
    settings: Settings,
    buyer: Llm,
    judge: Llm,
    tenant_id: int,
    verbose: bool = False,
    finished: dict[str, dict[str, Any]] | None = None,
    on_result: Callable[[dict[str, Any]], None] | None = None,
) -> list[dict[str, Any]]:
    """Play and check the scenarios; those already `finished` by an interrupted run keep their results."""
    finished = finished or {}
    results = []
    async with tool_context(settings, tenant_id) as context:
        for number, scenario in enumerate(scenarios, 1):
            if scenario.name in finished:
                results.append(saved := finished[scenario.name])
                print(
                    f"{number}/{len(scenarios)} {'✓' if saved['passed'] else '✗'} {scenario.name} (сохранён)",
                    flush=True,
                )
                continue
            started = time.perf_counter()
            played = await play(context, buyer, scenario)
            problems = check(scenario, played)
            try:
                verdict: Verdict | None = await judge_dialog(judge, scenario, played["turns"])
            except LlmError as error:
                verdict = None
                problems.append(f"судья не ответил: {error}")
            if verdict is not None and not verdict.goal_reached:
                problems.append(f"судья: цель не достигнута — {verdict.comment}")
            if verdict is not None and not verdict.honest:
                problems.append(f"судья: пообещал лишнее — {verdict.comment}")
            results.append(
                played
                | {
                    "lead": played["lead"].model_dump(),
                    "lead_fields": lead_fields(scenario.lead, played["lead"]),
                    "code_passed": not [p for p in problems if not p.startswith("судья")],
                    "verdict": verdict.model_dump() if verdict else None,
                    "problems": problems,
                    "passed": not problems,
                    "seconds": round(time.perf_counter() - started, 1),
                }
            )
            if on_result is not None:
                on_result(results[-1])
            print(f"{number}/{len(scenarios)} {'✓' if not problems else '✗'} {scenario.name}", flush=True)
            for problem in problems:
                print(f"    {problem}", flush=True)
            if verbose:
                print("    " + transcript(played["turns"]).replace("\n", "\n    "), flush=True)
    return results


def summarize(results: list[dict[str, Any]], scenarios: list[Scenario]) -> dict[str, Any]:
    by_name = {scenario.name: scenario for scenario in scenarios}
    turns = [turn for result in results for turn in result["turns"] if "error" not in turn]
    seconds = sorted(turn["seconds"] for turn in turns) or [0.0]
    verdicts = [result["verdict"] for result in results if result["verdict"]]
    needs_manager = [result for result in results if by_name[result["name"]].outcome in ("viewing", "handoff")]
    fields = [right for result in results for right in result["lead_fields"].values()]

    def share(values: list[bool]) -> float | None:
        return round(sum(values) / len(values), 4) if values else None

    return {
        "scenarios": len(results),
        "scenarios_passed": share([result["passed"] for result in results]),
        "target": TARGET,
        "code_passed": share([result["code_passed"] for result in results]),
        "judge_goal_reached": share([verdict["goal_reached"] for verdict in verdicts]),
        "judge_honest": share([verdict["honest"] for verdict in verdicts]),
        "judge_answered_mean": round(statistics.mean(v["answered"] for v in verdicts), 2) if verdicts else None,
        "judge_tone_mean": round(statistics.mean(v["tone"] for v in verdicts), 2) if verdicts else None,
        "handoff_recall": share([result["ended"] == "handoff" for result in needs_manager]),
        "handoff_target": HANDOFF_TARGET,
        "handoffs_unexpected": sum(
            by_name[result["name"]].outcome == "answer" and bool(unasked_handoffs(result["turns"]))
            for result in results
        ),
        "fallbacks": sum(not turn["verified"] for turn in turns),
        "model_errors": sum("error" in turn for result in results for turn in result["turns"]),
        "lead_field_accuracy": share(fields),
        "turns": len(turns),
        "turns_per_dialog": round(len(turns) / len(results), 2) if results else None,
        "seconds_median": statistics.median(seconds),
        "seconds_p90": seconds[int(len(seconds) * 0.9)],
        "over_client_timeout": sum(value > CLIENT_TIMEOUT for value in seconds),
    }


def fingerprint(scenario_set: Path) -> str:
    """A hash of the agent's code and the scenario set; comments count too, as a prompt is a string in the code."""
    digest = hashlib.sha256()
    for path in [*sorted(AGENT_DIR.glob("*.py")), scenario_set]:
        digest.update(path.name.encode())
        digest.update(path.read_bytes())
    return digest.hexdigest()


def progress(played_fingerprint: str) -> dict[str, dict[str, Any]]:
    """The results an interrupted run saved on the same code and set, by scenario name; others are played again."""
    if not PROGRESS.exists():
        return {}
    lines = [json.loads(line) for line in PROGRESS.read_text().splitlines() if line.strip()]
    return {line["result"]["name"]: line["result"] for line in lines if line["fingerprint"] == played_fingerprint}


def _progress_line(played_fingerprint: str, result: dict[str, Any]) -> str:
    return json.dumps({"fingerprint": played_fingerprint, "result": result}, ensure_ascii=False) + "\n"


def failures(report: dict[str, Any], scenario_set: Path) -> list[str]:
    """What CI warns about the report."""
    problems = []
    if report["fingerprint"] != fingerprint(scenario_set):
        problems.append(
            "отчёт сделан на другом коде агента или другом наборе: "
            "запустите `uv run python -m homefinder.evals.simulator --save`"
        )
    summary = report["summary"]
    if (summary["scenarios_passed"] or 0) < summary["target"]:
        problems.append(f"цель достигнута в {summary['scenarios_passed']:.0%} сценариев, нужно {summary['target']:.0%}")
    if summary["handoff_recall"] is not None and summary["handoff_recall"] < summary["handoff_target"]:
        problems.append(f"передача сработала в {summary['handoff_recall']:.0%}, нужно {summary['handoff_target']:.0%}")
    if summary["fallbacks"]:
        problems.append(f"ответов, заменённых верификатором: {summary['fallbacks']} — модель пыталась выдумать")
    return problems


def markdown(report: dict[str, Any]) -> str:
    """The report for the CI job summary."""
    s = report["summary"]

    def percent(value: float | None) -> str:
        return "—" if value is None else f"{value:.0%}"

    lines = [
        f"### Симулятор покупателя — {report['model']}, {report['date'][:10]}",
        "",
        "| Метрика | Значение | Цель |",
        "| --- | --- | --- |",
        f"| Цель сценария достигнута | {percent(s['scenarios_passed'])} | ≥ {percent(s['target'])} |",
        f"| Проверка кодом | {percent(s['code_passed'])} | |",
        f"| Судья: цель достигнута | {percent(s['judge_goal_reached'])} | |",
        f"| Судья: ничего лишнего не обещал | {percent(s['judge_honest'])} | |",
        f"| Передача сработала | {percent(s['handoff_recall'])} | ≥ {percent(s['handoff_target'])} |",
        f"| Передач без причины | {s['handoffs_unexpected']} | 0 |",
        f"| Ответов заменено верификатором | {s['fallbacks']} | 0 |",
        f"| Поля лида собраны верно | {percent(s['lead_field_accuracy'])} | |",
        f"| Ход агента, медиана / p90 | {s['seconds_median']} / {s['seconds_p90']} с | ≤ {CLIENT_TIMEOUT} с |",
    ]
    failed = [result for result in report["results"] if not result["passed"]]
    if failed:
        lines += ["", "Не прошли:", ""]
        lines += [f"- **{result['name']}**: {'; '.join(result['problems'])}" for result in failed]
    return "\n".join(lines) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0] if __doc__ else None)
    parser.add_argument("--set", type=Path, default=EVALS_DIR / "simulator.jsonl")
    parser.add_argument("--tenant", type=int, default=1, help="the demo developer")
    parser.add_argument("--model", help="the agent's LiteLLM model instead of AGENT_LLM_MODEL")
    parser.add_argument("--buyer-model", help="the model that plays the buyer; the agent's by default")
    parser.add_argument("--judge-model", help="the judge model; the agent's by default")
    parser.add_argument("--only", help="play the scenarios whose name contains this")
    parser.add_argument("--verbose", action="store_true", help="print each dialog")
    parser.add_argument("--save", action="store_true", help=f"write {REPORT.relative_to(EVALS_DIR.parent)}")
    parser.add_argument(
        "--resume", action="store_true", help="with --save: go on with the scenarios an interrupted run left"
    )
    parser.add_argument(
        "--check", action="store_true", help="show the saved report and warn about it instead of playing (CI)"
    )
    arguments = parser.parse_args()

    if arguments.check:
        report = json.loads(REPORT.read_text()) if REPORT.exists() else None
        problems = failures(report, arguments.set) if report else [f"нет отчёта {REPORT.name}"]
        if report and (summary_file := os.environ.get("GITHUB_STEP_SUMMARY")):
            with open(summary_file, "a") as file:
                file.write(markdown(report))
        for problem in problems:
            print(f"::warning::{problem}")
        raise SystemExit(0)

    if arguments.save and arguments.only:
        parser.error("--save writes the report of the whole set; leave out --only")
    if arguments.resume and not arguments.save:
        parser.error("--resume goes on with a saved run; add --save")

    settings = get_settings()
    if arguments.model:
        settings = settings.model_copy(update={"llm_model": arguments.model})
    buyer = Llm(settings.model_copy(update={"llm_model": arguments.buyer_model or settings.llm_model}))
    judge = Llm(settings.model_copy(update={"llm_model": arguments.judge_model or settings.llm_model}))
    scenarios = [Scenario.model_validate_json(line) for line in arguments.set.read_text().splitlines() if line.strip()]
    if arguments.only:
        scenarios = [scenario for scenario in scenarios if arguments.only in scenario.name]

    # Taken before playing: code changed during a run of hours must not be vouched for by its results.
    played_fingerprint = fingerprint(arguments.set)
    finished = progress(played_fingerprint) if arguments.resume else {}
    on_result = None
    if arguments.save:
        PROGRESS.parent.mkdir(exist_ok=True)
        PROGRESS.write_text("".join(_progress_line(played_fingerprint, result) for result in finished.values()))

        def on_result(result: dict[str, Any]) -> None:
            with PROGRESS.open("a") as file:
                file.write(_progress_line(played_fingerprint, result))

    results = asyncio.run(
        run(scenarios, settings, buyer, judge, arguments.tenant, arguments.verbose, finished, on_result)
    )
    report = {
        "model": settings.llm_model,
        "buyer_model": buyer.settings.llm_model,
        "judge_model": judge.settings.llm_model,
        "reasoning_effort": settings.llm_reasoning_effort,
        "date": datetime.now(UTC).isoformat(timespec="seconds"),
        "fingerprint": played_fingerprint,
        "summary": summarize(results, scenarios),
        "results": results,
    }
    print(json.dumps(report["summary"], indent=2))

    if arguments.save:
        REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
        PROGRESS.unlink(missing_ok=True)

    raise SystemExit(0 if (report["summary"]["scenarios_passed"] or 0) >= TARGET else 1)


if __name__ == "__main__":
    main()
