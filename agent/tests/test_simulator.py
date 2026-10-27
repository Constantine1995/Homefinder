from pathlib import Path
from typing import Any

import pytest
from psycopg import AsyncConnection
from pydantic import BaseModel

from homefinder.agent.state import Lead
from homefinder.evals import simulator
from homefinder.evals.simulator import (
    ECHO_NUDGE,
    Scenario,
    check,
    failures,
    fingerprint,
    lead_fields,
    play,
    progress,
    summarize,
)
from tests.conftest import Catalog
from tests.test_agent import ScriptedLlm, context, demo

pytestmark = pytest.mark.anyio


class ScriptedBuyer(ScriptedLlm):
    """Plays the buyer's scripted moves and remembers the messages of its last call, roles included."""

    def __init__(self, moves: list[dict[str, Any]]) -> None:
        super().__init__({"BuyerMove": moves}, [])
        self.messages: list[dict[str, str]] = []

    async def structured[T: BaseModel](
        self, messages: list[dict[str, str]], schema: type[T], max_tokens: int | None = None
    ) -> T:
        self.messages = messages
        return await super().structured(messages, schema, max_tokens)


def scenario(**values: Any) -> Scenario:
    return Scenario.model_validate(
        {"name": "двушка", "persona": "семья", "goal": "подобрать двушку", "outcome": "answer"} | values
    )


def answered(**values: Any) -> dict[str, Any]:
    defaults = {
        "buyer": "двушка до 9 млн",
        "reply": "Нашёл кв. 75 за 8 762 000 ₽.",
        "intent": "search",
        "tools": ["search_flats"],
        "actions": [],
        "verified": True,
        "verifier": [],
        "seconds": 20.0,
    }
    return defaults | values


def handoff(reason: str = "покупатель просит скидку") -> dict[str, str]:
    return {"type": "handoff", "reason": reason}


async def test_the_buyer_and_the_agent_talk_until_the_buyer_is_done(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    agent = ScriptedLlm(
        {"Understanding": [{"intent": "search"}], "Plan": [{"tool": "search_flats", "args": {"request": "двушка"}}]},
        ["Нашёл кв. 75 за 8 762 000 ₽."],
    )
    buyer = ScriptedBuyer([{"message": "двушка до 9 млн"}, {"done": True}])

    played = await play(context(connection, agent, ids), buyer, scenario(facts=["бюджет до 9 млн"]))

    assert [(turn["buyer"], turn["reply"], turn["tools"]) for turn in played["turns"]] == [
        ("двушка до 9 млн", "Нашёл кв. 75 за 8 762 000 ₽.", ["search_flats"])
    ]
    assert played["ended"] == "buyer"
    # The buyer model is told who it plays, and its own messages are its turns: the agent is the other side.
    assert "бюджет до 9 млн" in buyer.messages[0]["content"]
    assert [(message["role"], message["content"]) for message in buyer.messages[2:]] == [
        ("assistant", "двушка до 9 млн"),
        ("user", "Нашёл кв. 75 за 8 762 000 ₽."),
    ]


async def test_the_dialog_ends_when_the_agent_hands_it_over(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    agent = ScriptedLlm({"Understanding": [{"intent": "handoff", "handoff_reason": "скидка"}]}, [])
    buyer = ScriptedBuyer([{"message": "дадите скидку 10%?"}, {"message": "ну так что?"}])

    played = await play(context(connection, agent, ids), buyer, scenario(outcome="handoff"))

    assert played["ended"] == "handoff"
    assert len(played["turns"]) == 1
    assert buyer.asked["BuyerMove"] == 1


async def test_a_buyer_who_copies_the_agent_is_asked_again(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    agent = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}, {"intent": "search"}],
            "Plan": [{"tool": "search_flats", "args": {"request": "двушка"}}] * 2,
        },
        ["Нашёл кв. 75 за 8 762 000 ₽.", "Нашёл кв. 75 за 8 762 000 ₽."],
    )
    buyer = ScriptedBuyer(
        [
            {"message": "двушка до 9 млн"},
            {"message": "Нашёл кв. 75 за 8 762 000 ₽"},
            {"message": "а она с отделкой?"},
            {"done": True},
        ]
    )

    played = await play(context(connection, agent, ids), buyer, scenario())

    assert [turn["buyer"] for turn in played["turns"]] == ["двушка до 9 млн", "а она с отделкой?"]
    assert buyer.asked["BuyerMove"] == 4


async def test_a_buyer_who_keeps_copying_the_agent_ends_the_dialog(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    agent = ScriptedLlm(
        {"Understanding": [{"intent": "search"}], "Plan": [{"tool": "search_flats", "args": {"request": "двушка"}}]},
        ["Нашёл кв. 75 за 8 762 000 ₽."],
    )
    buyer = ScriptedBuyer(
        [
            {"message": "двушка до 9 млн"},
            {"message": "Нашёл кв. 75 за 8 762 000 ₽."},
            {"message": "нашёл кв. 75 за 8 762 000 ₽"},
        ]
    )

    played = await play(context(connection, agent, ids), buyer, scenario())

    assert len(played["turns"]) == 1
    assert played["ended"] == "buyer"
    assert buyer.messages[-1] == {"role": "user", "content": ECHO_NUDGE}


def test_an_interrupted_run_goes_on_only_with_results_of_the_same_code(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(simulator, "PROGRESS", tmp_path / "simulator.progress.jsonl")
    simulator.PROGRESS.write_text(
        '{"fingerprint": "same", "result": {"name": "торг", "passed": true}}\n'
        '{"fingerprint": "other", "result": {"name": "ипотека", "passed": false}}\n'
    )

    assert progress("same") == {"торг": {"name": "торг", "passed": True}}
    assert progress("new") == {}


def test_a_buyer_who_asks_for_a_viewing_is_handed_over_in_a_dialog_to_answer_too() -> None:
    played = {
        "turns": [answered(), answered(buyer="хочу на просмотр", intent="booking", tools=[], actions=[handoff()])]
    }

    assert check(scenario(outcome="answer"), played) == []


def test_the_summary_counts_as_unexpected_only_the_handoffs_the_check_turns_down() -> None:
    viewing = answered(buyer="хочу на просмотр", intent="booking", tools=[], actions=[handoff()])
    results = [
        {"name": name, "turns": turns, "ended": "handoff", "passed": False, "code_passed": False}
        | {"verdict": None, "lead_fields": {}}
        for name, turns in [
            ("просмотр", [answered(), viewing]),
            ("скидка", [answered(), answered(intent="handoff", actions=[handoff()])]),
        ]
    ]
    scenarios = [scenario(name="просмотр"), scenario(name="скидка")]

    assert summarize(results, scenarios)["handoffs_unexpected"] == 1


def test_a_dialog_that_ends_as_the_scenario_asks_passes() -> None:
    played = {
        "turns": [answered(), answered(buyer="хочу на просмотр", intent="booking", tools=[], actions=[handoff()])]
    }

    assert check(scenario(outcome="viewing", tools_any=["search_flats"]), played) == []


@pytest.mark.parametrize(
    ("values", "turn", "problem"),
    [
        ({"outcome": "handoff"}, answered(), "не передан менеджеру"),
        ({"outcome": "viewing"}, answered(actions=[handoff()]), "передан менеджеру, но не как просьба о просмотре"),
        (
            {"outcome": "answer"},
            answered(actions=[handoff()]),
            "передан менеджеру без причины: покупатель просит скидку",
        ),
        (
            {"outcome": "answer", "tools_any": ["mortgage_calc"]},
            answered(),
            "не вызван ни один из ['mortgage_calc'], вызваны ['search_flats']",
        ),
        (
            {"outcome": "any"},
            answered(verified=False, verifier=["сумма «1 ₽» не из результатов инструментов"]),
            "ответ не прошёл верификатор: сумма «1 ₽» не из результатов инструментов",
        ),
        ({"outcome": "any"}, answered(seconds=150.0), "ход 150.0 с — дольше, чем ждёт Laravel"),
    ],
)
def test_it_names_what_the_dialog_did_wrong(values: dict[str, Any], turn: dict[str, Any], problem: str) -> None:
    assert check(scenario(**values), {"turns": [turn]}) == [problem]


def test_an_agent_that_never_answered_fails() -> None:
    played = {"turns": [{"buyer": "здравствуйте", "error": "timeout"}]}

    assert check(scenario(outcome="any"), played) == ["ошибка модели — timeout", "агент не ответил ни разу"]


def test_it_compares_the_collected_lead_with_what_the_buyer_knows() -> None:
    lead = Lead(budget=9_000_000, payment="cash", rooms=[2, 1])

    assert lead_fields({"budget": 9_000_000, "payment": "mortgage", "rooms": [1, 2]}, lead) == {
        "budget": True,
        "payment": False,
        "rooms": True,
    }


def report(scenario_set: Path, **summary: Any) -> dict[str, Any]:
    defaults = {"scenarios_passed": 0.9, "target": 0.85, "handoff_recall": 1.0, "handoff_target": 0.95, "fallbacks": 0}
    return {"fingerprint": fingerprint(scenario_set), "summary": defaults | summary}


def test_ci_accepts_a_report_of_the_current_code_that_meets_the_targets(tmp_path: Path) -> None:
    scenario_set = tmp_path / "simulator.jsonl"
    scenario_set.write_text('{"name": "двушка"}\n')

    assert failures(report(scenario_set), scenario_set) == []


def test_ci_rejects_a_report_of_other_code_or_another_set(tmp_path: Path) -> None:
    scenario_set = tmp_path / "simulator.jsonl"
    scenario_set.write_text('{"name": "двушка"}\n')
    saved = report(scenario_set)
    scenario_set.write_text('{"name": "двушка"}\n{"name": "торг"}\n')

    assert failures(saved, scenario_set) == [
        "отчёт сделан на другом коде агента или другом наборе: "
        "запустите `uv run python -m homefinder.evals.simulator --save`"
    ]


def test_ci_rejects_a_report_that_misses_the_targets(tmp_path: Path) -> None:
    scenario_set = tmp_path / "simulator.jsonl"
    scenario_set.write_text('{"name": "двушка"}\n')

    assert failures(report(scenario_set, scenarios_passed=0.8, handoff_recall=0.9, fallbacks=2), scenario_set) == [
        "цель достигнута в 80% сценариев, нужно 85%",
        "передача сработала в 90%, нужно 95%",
        "ответов, заменённых верификатором: 2 — модель пыталась выдумать",
    ]
