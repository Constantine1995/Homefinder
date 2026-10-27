from typing import Any

from homefinder.agent.graph import FALLBACK_REPLY, TurnResult
from homefinder.agent.state import Action, DialogState, Observation
from homefinder.evals.dialogs import Expectation, check
from homefinder.search.filters import FlatFilters


def turn(**values: Any) -> TurnResult:
    defaults: dict[str, Any] = {
        "reply": "Нашёл кв. 75 за 8 762 000 ₽.",
        "dialog": DialogState(),
        "intent": "search",
        "actions": [],
        "steps": [],
        "verified": True,
        "problems": [],
        "attempts": 1,
        "prompt_tokens": 0,
        "completion_tokens": 0,
    }
    return TurnResult.model_validate(defaults | values)


def search(flat_ids: list[int]) -> Observation:
    return Observation(tool="search_flats", args={"request": "двушка"}, summary="", flat_ids=flat_ids)


def test_a_turn_that_does_what_the_scenario_asks_passes() -> None:
    expected = Expectation.model_validate(
        {
            "buyer": "двушка до 9 млн в Тёплых холмах",
            "tools": ["search_flats"],
            "shows_flats": True,
            "filters": {"rooms": [2], "price_to": 9_000_000, "complexes": ["Тёплые холмы"]},
            "reply_has": ["кв\\. \\d+"],
            "reply_lacks": ["скидк"],
        }
    )
    dialog = DialogState(filters=FlatFilters(rooms=[2], price_to=9_000_000, complex_ids=[3]))

    assert check(expected, turn(steps=[search([509])], dialog=dialog)) == []


def test_it_names_each_thing_the_turn_did_wrong() -> None:
    expected = Expectation.model_validate(
        {"buyer": "а скидку дадите?", "tools": [], "handoff": True, "filters": {"price_to": 9_000_000}}
    )
    result = turn(steps=[search([509])], dialog=DialogState(filters=FlatFilters(price_to=10_000_000)))

    assert check(expected, result) == [
        "инструменты не нужны, вызваны ['search_flats']",
        "не передан менеджеру",
        "условие price_to: 10000000 вместо 9000000",
    ]


def test_a_reply_replaced_with_the_fallback_fails_even_when_it_hands_over_as_asked() -> None:
    expected = Expectation(buyer="а скидку дадите?", handoff=True)
    result = turn(
        reply=FALLBACK_REPLY,
        verified=False,
        problems=["сумма «8 500 000 ₽» не из результатов инструментов"],
        actions=[Action(type="handoff", reason="ответ не прошёл проверку")],
    )

    assert check(expected, result) == [
        "ответ не прошёл верификатор: сумма «8 500 000 ₽» не из результатов инструментов"
    ]
