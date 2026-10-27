from collections import Counter
from datetime import date
from typing import Any

import pytest
from psycopg import AsyncConnection
from pydantic import BaseModel

from homefinder.agent.graph import CARDS_RULE, FALLBACK_REPLY, HANDOFF_REPLY, MAX_TOOL_STEPS, NO_FLATS_RULE, run_turn
from homefinder.agent.state import DialogState, Lead, Turn
from homefinder.agent.tools import ToolContext
from homefinder.llm import LlmError
from homefinder.mortgage import DISCLAIMER
from homefinder.search.filters import FlatFilters
from tests.conftest import Catalog
from tests.test_knowledge import KeywordReranker
from tests.test_wishes import KeywordLlm

pytestmark = pytest.mark.anyio

TODAY = date(2026, 10, 22)


class ScriptedLlm(KeywordLlm):
    """Answers each kind of request with the next scripted answer, and remembers what it was shown and asked."""

    def __init__(self, answers: dict[str, list[dict[str, Any]]], replies: list[str | LlmError]) -> None:
        super().__init__()
        self.answers = answers
        self.replies = replies
        self.seen: list[str] = []
        self.asked: Counter[str] = Counter()

    async def structured[T: BaseModel](
        self, messages: list[dict[str, str]], schema: type[T], max_tokens: int | None = None
    ) -> T:
        self.seen.extend(message["content"] for message in messages)
        self.asked[schema.__name__] += 1
        queue = self.answers.get(schema.__name__, [])
        return schema.model_validate(queue.pop(0) if queue else {"tool": "answer"} if schema.__name__ == "Plan" else {})

    async def text(self, messages: list[dict[str, str]]) -> str:
        self.seen.extend(message["content"] for message in messages)
        reply = self.replies.pop(0)
        if isinstance(reply, LlmError):
            raise reply
        return reply


async def demo(catalog: Catalog) -> dict[str, int]:
    tenant_id = await catalog.tenant()
    complex_id = await catalog.complex(tenant_id, "Тёплые холмы")
    building_id = await catalog.building(tenant_id, complex_id, name="корпус 3", floors=12, deadline=date(2028, 6, 30))
    flat_id = await catalog.flat(
        tenant_id, building_id, number="75", rooms=2, is_euro=True, area=34.8, price=8_762_000, floor=9
    )
    sold_id = await catalog.flat(tenant_id, building_id, number="12", rooms=2, price=7_900_000, status="sold")
    await catalog.mortgage_program(tenant_id, "Семейная ипотека", rate=6)
    return {"tenant": tenant_id, "complex": complex_id, "flat": flat_id, "sold": sold_id}


def context(connection: AsyncConnection[Any], llm: ScriptedLlm, ids: dict[str, int]) -> ToolContext:
    return ToolContext(
        connection=connection,
        llm=llm,
        reranker=KeywordReranker(),
        tenant_id=ids["tenant"],
        today=TODAY,
        complexes={"Тёплые холмы": ids["complex"]},
    )


async def test_it_searches_flats_and_answers_with_what_it_found(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search", "lead": {"budget_mln": 9, "payment": "mortgage"}}],
            "Plan": [{"tool": "search_flats", "args": {"request": "евродвушка до 9 млн"}}, {"tool": "answer"}],
            "ParsedQuery": [{"rooms": [2], "euro": True, "price_max_mln": 9}],
        },
        ["Нашёл кв. 75 в ЖК «Тёплые холмы»: 34,8 м², 8 762 000 ₽. Посчитать ипотеку?"],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "евродвушка до 9 млн, в ипотеку")

    assert result.verified
    assert result.reply.startswith("Нашёл кв. 75")
    assert [step.tool for step in result.steps] == ["search_flats"]
    assert result.dialog.shown_flat_ids == [ids["flat"]]
    assert result.dialog.filters.price_to == 9_000_000
    assert (result.dialog.lead.budget, result.dialog.lead.payment) == (9_000_000, "mortgage")
    assert result.dialog.lead.rooms == [2]
    assert any("Найдено квартир: 1." in seen for seen in llm.seen)
    assert llm.asked["Plan"] == 1, "the search answers the turn: the model is not asked what to do next"


async def test_it_computes_the_mortgage_of_a_shown_flat_by_its_price_from_the_database(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "mortgage"}],
            "Plan": [
                {"tool": "mortgage_calc", "args": {"flat_ids": [ids["flat"]], "price": 1, "down_payment": 2_000_000}}
            ],
        },
        ["По семейной ипотеке под 6% платёж — 40 542 ₽ в месяц."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(shown_flat_ids=[ids["flat"]]), [], "сколько в месяц по семейной?"
    )

    [step] = result.steps
    assert step.data["calculations"][0]["price"] == 8_762_000
    assert step.data["calculations"][0]["monthly_payment"] == 40_542
    assert step.summary.startswith("Квартира: кв. 75 в ЖК «Тёплые холмы», цена 8,76 млн ₽."), "not by its ID"
    assert result.verified
    assert result.reply == f"По семейной ипотеке под 6% платёж — 40 542 ₽ в месяц.\n\n{DISCLAIMER}"


async def test_it_hands_a_bargaining_buyer_over_to_a_manager(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "handoff", "handoff_reason": "просит скидку"}]},
        ["Скидку обсудит менеджер отдела продаж — он скоро напишет вам."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "а скидку дадите, если сегодня?")

    assert [(action.type, action.reason) for action in result.actions] == [("handoff", "просит скидку")]
    assert [step.tool for step in result.steps] == ["handoff_to_human"]
    assert result.reply == HANDOFF_REPLY


async def test_it_writes_the_reply_again_when_the_verifier_finds_a_made_up_price(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "details"}], "Plan": [{"tool": "get_flat", "args": {"flat_ids": [ids["flat"]]}}]},
        ["Кв. 75 стоит 8 500 000 ₽.", "Кв. 75 стоит 8 762 000 ₽."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(shown_flat_ids=[ids["flat"]]), [], "сколько стоит 75-я?"
    )

    assert result.reply == "Кв. 75 стоит 8 762 000 ₽."
    assert result.attempts == 2
    assert result.verified
    assert any("сумма «8 500 000 ₽» не из результатов инструментов" in seen for seen in llm.seen)
    assert "Кв. 75 стоит 8 500 000 ₽." in llm.seen, "the model rewrites its reply, not writes it anew"


async def test_it_falls_back_to_a_manager_when_the_reply_fails_twice(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "details"}]}, ["Кв. 118 за 5 млн ₽.", "Кв. 118 за 5 млн ₽."])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "есть что-то за 5 млн?")

    assert result.reply == FALLBACK_REPLY
    assert not result.verified
    assert result.actions[0].type == "handoff"


async def test_it_never_offers_a_sold_flat(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "details"}], "Plan": [{"tool": "get_flat", "args": {"flat_ids": [ids["sold"]]}}]},
        ["Кв. 12 за 7 900 000 ₽ ещё свободна!", "Кв. 12 уже продана, покажу похожие."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(shown_flat_ids=[ids["sold"]]), [], "а кв. 12?")

    assert result.attempts == 2
    assert "квартира № 12 уже продана или снята с продажи" in llm.seen[-1] or result.verified


async def test_it_stops_the_tool_loop_after_five_steps(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    plans = [{"tool": "search_flats", "args": {"request": f"квартира до {n} млн"}} for n in range(MAX_TOOL_STEPS + 3)]
    llm = ScriptedLlm({"Understanding": [{"intent": "details"}], "Plan": plans}, ["Подберу варианты."])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "что-нибудь")

    assert len(result.steps) == MAX_TOOL_STEPS


async def test_it_answers_instead_of_repeating_a_call(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    plans = [{"tool": "search_knowledge", "args": {"question": "паркинг", "complex?": "Тёплые холмы"}}] * 3
    llm = ScriptedLlm({"Understanding": [{"intent": "knowledge"}], "Plan": plans}, ["Уточню у менеджера."])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "есть паркинг?")

    assert [step.summary for step in result.steps] == ["Ошибка в аргументах: complex?: Extra inputs are not permitted"]


async def test_it_reports_a_wrong_tool_call_back_to_the_model(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "compare"}],
            "Plan": [{"tool": "compare", "args": {"flat_ids": [ids["flat"]]}}, {"tool": "book_now", "args": {}}],
        },
        ["Чтобы сравнить, выберите ещё одну квартиру."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "сравни")

    assert [step.summary.split(":")[0] for step in result.steps] == ["Ошибка в аргументах", "Ошибка"]
    assert "инструмента «book_now» нет" in result.steps[1].summary


async def test_it_keeps_phones_and_emails_from_the_model(connection: AsyncConnection[Any], catalog: Catalog) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "smalltalk"}]}, ["Спасибо, записал!"])
    history = [Turn(role="user", text="почта anna@mail.ru")]

    await run_turn(context(connection, llm, ids), DialogState(), history, "мой телефон +7 916 123-45-67")

    assert not any("916" in seen or "anna@mail.ru" in seen for seen in llm.seen)
    assert any("[телефон]" in seen for seen in llm.seen)


async def test_it_asks_for_a_manager_task_when_the_knowledge_base_has_no_answer(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "knowledge"}],
            "Plan": [{"tool": "search_knowledge", "args": {"question": "Есть ли бассейн?"}}, {"tool": "answer"}],
            "_Rewrite": [{"query": "бассейн"}],
        },
        ["Уточню у менеджера и вернусь с ответом."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "Есть ли бассейн?")

    assert [(action.type, action.reason) for action in result.actions] == [("knowledge_gap", "Есть ли бассейн?")]
    assert result.verified


async def test_it_names_the_dialog_s_complex_to_the_planner_as_the_tools_take_it(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "knowledge"}], "_Rewrite": [{"query": "паркинг"}]}, ["Уточню."])

    await run_turn(context(connection, llm, ids), DialogState(complex_id=ids["complex"]), [], "а паркинг там есть?")

    planner_prompt = next(seen for seen in llm.seen if seen.startswith("Ты подбираешь инструменты"))
    assert '"ЖК диалога": "Тёплые холмы"' in planner_prompt


async def test_it_takes_flats_only_from_those_shown_and_names_them_by_place(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "mortgage"}],
            "Plan": [{"tool": "mortgage_calc", "args": {"flat_ids": [75], "down_payment": 2_000_000}}],
        },
        ["Уточните, какую квартиру посчитать."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(shown_flat_ids=[ids["flat"]]), [], "посчитай ипотеку за первую"
    )

    [step] = result.steps
    assert step.summary == (
        f"Ошибка: квартиры с id [75] покупателю не показывали. "
        f"Показанные квартиры: 1-я — id {ids['flat']}, кв. 75, 8,76 млн ₽"
    )
    assert any(f"1-я — id {ids['flat']}, кв. 75" in seen for seen in llm.seen)


async def test_it_looks_into_the_knowledge_base_before_answering_a_question_about_the_complex(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "knowledge"}], "Plan": [{"tool": "answer"}], "_Rewrite": [{"query": "акции"}]},
        ["Уточню у менеджера."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "а скидки сейчас есть?")

    [step] = result.steps
    assert (step.tool, step.args["question"]) == ("search_knowledge", "а скидки сейчас есть?")


async def test_it_greets_back_without_tools_when_the_buyer_said_nothing_about_themselves(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "smalltalk", "lead": None}]}, ["Добрый вечер! Чем помочь?"])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "Добрый вечер!")

    assert (result.intent, result.steps, result.reply) == ("smalltalk", [], "Добрый вечер! Чем помочь?")
    assert any(NO_FLATS_RULE in seen for seen in llm.seen), "without flats in the results the model must not name any"


async def test_it_hands_the_dialog_over_when_the_model_does_not_write_the_reply(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "smalltalk"}]}, [LlmError("timeout")])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "Добрый вечер!")

    assert (result.reply, result.verified) == (FALLBACK_REPLY, False)
    assert [(action.type, action.reason) for action in result.actions] == [("handoff", "модель не ответила")]


async def test_it_keeps_the_shown_flats_after_the_card_of_one_of_them(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "details"}], "Plan": [{"tool": "get_flat", "args": {"flat_ids": [ids["sold"]]}}]},
        ["Вторая уже продана, покажу похожие."],
    )
    shown = DialogState(shown_flat_ids=[ids["flat"], ids["sold"]])

    result = await run_turn(context(connection, llm, ids), shown, [], "подробнее про вторую")

    assert result.dialog.shown_flat_ids == [ids["flat"], ids["sold"]]


async def test_it_links_an_answer_from_the_knowledge_base_to_its_source(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    source = "https://demo-developer.example/warm-hills"
    document_id = await catalog.document(ids["tenant"], "ЖК «Тёплые холмы»", source, ids["complex"])
    await catalog.chunk(ids["tenant"], document_id, "Паркинг", "Подземный паркинг на 300 машино-мест.")
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "knowledge"}],
            "Plan": [{"tool": "search_knowledge", "args": {"question": "есть подземный паркинг?"}}],
        },
        ["Да, есть подземный паркинг."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "есть подземный паркинг?")

    assert result.reply == f"Да, есть подземный паркинг.\n\nИсточник: ЖК «Тёплые холмы» — {source}"


async def test_it_takes_empty_fields_of_the_lead_for_not_said(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    lead = {"budget_mln": 0, "deadline_year": 0, "family": "", "sells_own_flat": "", "payment": "cash"}
    llm = ScriptedLlm({"Understanding": [{"intent": "handoff", "lead": lead}]}, [])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "а скидку за наличные сделаете?")

    assert result.intent == "handoff"
    assert result.dialog.lead == Lead(payment="cash")


async def test_it_does_not_take_what_a_small_model_makes_up_for_the_lead(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    lead = {"payment": "mortgage", "family": "Семейная ипотека", "sells_own_flat": False}
    llm = ScriptedLlm({"Understanding": [{"intent": "handoff", "lead": lead}]}, [])

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "по семейной ипотеке что-то сделаете?")

    assert result.dialog.lead == Lead(payment="mortgage")


async def test_it_lets_the_reply_name_a_flat_shown_in_an_earlier_turn(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "mortgage"}],
            "Plan": [{"tool": "mortgage_calc", "args": {"flat_ids": [ids["flat"]]}}],
        },
        ["Для кв. 75 (34,8 м²) посчитал платёж по программам застройщика. Расчёт предварительный."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(shown_flat_ids=[ids["flat"]]), [], "а платёж за первую?"
    )

    assert (result.verified, result.attempts) == (True, 1)


async def test_it_lets_the_reply_name_a_flat_of_a_search_before_the_last_one(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    building_id = await catalog.building(ids["tenant"], ids["complex"])
    other = await catalog.flat(ids["tenant"], building_id, number="52", rooms=3, area=61.2, price=12_400_000)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}],
            "Plan": [{"tool": "search_flats", "args": {"request": "трёшка"}}],
            "ParsedQuery": [{"rooms": [3]}],
        },
        ["Трёшка — кв. 52 за 12,4 млн ₽, а кв. 75 из прошлой подборки стоит 8 762 000 ₽."],
    )
    dialog = DialogState(shown_flat_ids=[ids["flat"]], offered_flat_ids=[ids["flat"]])

    result = await run_turn(context(connection, llm, ids), dialog, [], "а трёшки есть? и сколько стоила кв. 75?")

    assert (result.verified, result.attempts) == (True, 1)
    assert result.dialog.shown_flat_ids == [other]
    assert result.dialog.offered_flat_ids == [ids["flat"], other]


async def test_it_offers_the_planner_only_the_tools_of_the_intent(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}],
            "Plan": [{"tool": "search_knowledge", "args": {"question": "двушки в Тёплых холмах"}}],
        },
        ["Уточните, что подобрать."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "а в Тёплых холмах есть?")

    [step] = result.steps
    assert step.summary == "Ошибка: «search_knowledge» здесь не нужен, можно: search_flats, relax_filters."
    planner_prompt = next(seen for seen in llm.seen if seen.startswith("Ты подбираешь инструменты"))
    assert "- search_flats:" in planner_prompt
    assert "search_knowledge" not in planner_prompt


async def test_it_compares_the_shown_flats_without_searching_anew(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "compare"}], "Plan": [{"tool": "search_flats", "args": {"request": "двушки"}}]},
        ["Сравню, когда уточните, какие квартиры."],
    )
    shown = DialogState(shown_flat_ids=[ids["flat"], ids["sold"]])

    result = await run_turn(context(connection, llm, ids), shown, [], "сравните первую и вторую")

    [step] = result.steps
    assert step.summary == "Ошибка: «search_flats» здесь не нужен, можно: compare."
    assert result.dialog.shown_flat_ids == [ids["flat"], ids["sold"]]


async def test_it_leaves_the_flats_to_the_cards_of_a_channel_that_shows_them(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}],
            "Plan": [{"tool": "search_flats", "args": {"request": "евродвушка до 9 млн"}}],
            "ParsedQuery": [{"rooms": [2], "euro": True, "price_max_mln": 9}],
        },
        ["Нашлась одна евродвушка в бюджете — карточка ниже."],
    )
    with_cards = context(connection, llm, ids)
    with_cards.cards = True

    result = await run_turn(with_cards, DialogState(), [], "евродвушка до 9 млн")

    assert result.verified
    reply_prompt = next(seen for seen in llm.seen if seen.startswith("Ты — вежливый ИИ-менеджер"))
    assert CARDS_RULE in reply_prompt


async def test_it_subscribes_the_buyer_who_asks_by_the_conditions_they_name(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "subscribe"}],
            "ParsedQuery": [{"rooms": [2], "price_max_mln": 9, "complexes": ["Тёплые холмы"]}],
        },
        ["Готово: напишу, когда появится двушка до 9 млн ₽ в ЖК «Тёплые холмы». Сейчас подходит 1 квартира."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(), [], "напишите, если появится двушка до 9 млн в Тёплых холмах"
    )

    [action] = result.actions
    assert action.type == "save_search"
    assert action.reason == "2-комн., до 9 млн ₽, ЖК «Тёплые холмы»"
    assert action.filters is not None
    assert (action.filters["rooms"], action.filters["price_to"]) == ([2], 9_000_000)
    assert action.filters["complex_ids"] == [ids["complex"]]
    assert result.steps[0].summary.startswith(
        "Подписка оформлена: 2-комн., до 9 млн ₽, ЖК «Тёплые холмы». Подходящих квартир сейчас: 1."
    )
    assert result.verified
    assert llm.asked["Plan"] == 0, "a subscription needs no planning"


async def test_it_subscribes_by_the_dialog_s_search_when_the_buyer_names_no_conditions(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "subscribe"}], "ParsedQuery": [{}]}, ["Хорошо, напишу."])
    dialog = DialogState(filters=FlatFilters(rooms=[3], price_to=12_000_000))

    result = await run_turn(context(connection, llm, ids), dialog, [], "сообщите, когда появятся")

    [action] = result.actions
    assert action.reason == "3-комн., до 12 млн ₽"
    assert result.steps[0].data["total"] == 0


async def test_it_subscribes_nobody_when_nothing_tells_what_to_look_for(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {"Understanding": [{"intent": "subscribe"}], "ParsedQuery": [{}]},
        ["Подскажите, какую квартиру ищете, — и я напишу, когда она появится."],
    )

    result = await run_turn(context(connection, llm, ids), DialogState(), [], "напишите, когда что-нибудь появится")

    assert result.actions == []
    assert result.steps[0].failed
    assert result.reply.startswith("Подскажите")


async def test_it_tells_the_lowest_price_found_and_that_easing_the_conditions_finds_nothing_cheaper(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    building_id = await catalog.building(ids["tenant"], ids["complex"])
    for price in range(8_800_000, 9_000_000, 50_000):
        await catalog.flat(ids["tenant"], building_id, rooms=2, price=price)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}, {"intent": "search"}],
            "Plan": [{"tool": "search_flats", "args": {"request": "двушка до 9 млн"}}, {"tool": "relax_filters"}],
            "ParsedQuery": [{"rooms": [2], "price_max_mln": 9}],
        },
        ["Нашёл кв. 75 в ЖК «Тёплые холмы» за 8,76 млн ₽.", "Дешевле 8,76 млн ₽ двушек нет."],
    )
    agent = context(connection, llm, ids)

    searched = await run_turn(agent, DialogState(), [], "двушка до 9 млн")
    asked = await run_turn(agent, searched.dialog, [], "а подешевле?")

    assert "Дешевле 8,76 млн ₽ с этими условиями квартир нет" in searched.steps[0].summary
    assert searched.steps[0].data["cheapest_price"] == 8_762_000
    assert (
        asked.steps[0].summary
        == "Дешевле 8,76 млн ₽ с этими условиями квартир нет; подходит 5 квартир. Ослаблять условия незачем."
    )
    assert asked.verified


async def test_it_searches_by_the_dialog_s_conditions_when_the_request_names_none(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    await catalog.flat(ids["tenant"], await catalog.building(ids["tenant"], ids["complex"]), rooms=3, price=7_000_000)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}],
            "Plan": [{"tool": "search_flats", "args": {"request": "показать дешёвые варианты"}}],
            "ParsedQuery": [{}],
        },
        ["Вот кв. 75 в ЖК «Тёплые холмы» за 8,76 млн ₽."],
    )
    dialog = DialogState(filters=FlatFilters(rooms=[2], price_to=9_000_000))

    result = await run_turn(context(connection, llm, ids), dialog, [], "можно показать дешёвые варианты")

    assert result.dialog.filters == FlatFilters(rooms=[2], price_to=9_000_000)
    assert result.dialog.shown_flat_ids == [ids["flat"]]


async def test_it_keeps_the_dialog_s_conditions_the_request_does_not_name_again(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    building_id = await catalog.building(ids["tenant"], ids["complex"])
    await catalog.flat(ids["tenant"], building_id, number="51", rooms=3, price=15_590_000)
    await catalog.flat(ids["tenant"], building_id, number="7", rooms=3, price=9_800_000)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search"}, {"intent": "search"}],
            "Plan": [
                {"tool": "search_flats", "args": {"request": "трёшки в Тёплых холмах"}},
                {"tool": "search_flats", "args": {"request": "от 9 млн"}},
            ],
            "ParsedQuery": [{"rooms": [3], "complexes": ["Тёплые холмы"]}, {"price_min_mln": 9}],
        },
        ["Трёшка — кв. 7 за 9,8 млн ₽.", "Ничего не нашлось."],
    )
    dialog = DialogState(filters=FlatFilters(rooms=[2], price_to=10_000_000, not_first_floor=True))

    result = await run_turn(context(connection, llm, ids), dialog, [], "а трёшки в Тёплых холмах?")

    assert result.dialog.filters == FlatFilters(
        complex_ids=[ids["complex"]], rooms=[3], price_to=10_000_000, not_first_floor=True
    )
    assert "Условия поиска: 3-комн., до 10 млн ₽, не первый этаж, ЖК «Тёплые холмы»." in result.steps[0].summary

    widened = DialogState(filters=FlatFilters(rooms=[3], price_to=10_000_000))
    result = await run_turn(context(connection, llm, ids), widened, [], "а от 9 млн?")

    assert result.dialog.filters == FlatFilters(rooms=[3], price_from=9_000_000)


async def test_it_computes_the_mortgage_of_each_flat_named_in_one_call(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    building_id = await catalog.building(ids["tenant"], ids["complex"])
    other = await catalog.flat(ids["tenant"], building_id, number="40", rooms=1, area=36.6, price=9_050_000)
    await catalog.mortgage_program(ids["tenant"], "Стандартная ипотека", rate=17.5)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "mortgage"}],
            "Plan": [{"tool": "mortgage_calc", "args": {"flat_ids": [ids["flat"], other], "down_payment": 2_000_000}}],
        },
        ["По семейной ипотеке: кв. 75 — 40 542 ₽ в месяц, кв. 40 — 42 268 ₽ в месяц."],
    )

    result = await run_turn(
        context(connection, llm, ids),
        DialogState(shown_flat_ids=[ids["flat"], other]),
        [],
        "посчитайте ипотеку на эти квартиры",
    )

    [step] = result.steps
    assert [(calc["price"], calc["program"]) for calc in step.data["calculations"]] == [
        (8_762_000, "Семейная ипотека"),
        (9_050_000, "Семейная ипотека"),
    ], "the program with the lowest payment of each flat"
    assert "Квартира: кв. 40 в ЖК «Тёплые холмы», цена 9,05 млн ₽." in step.summary
    assert step.flat_ids == [ids["flat"], other]
    assert result.verified


async def test_it_computes_the_mortgage_of_the_flats_found_when_the_buyer_asks_for_both(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm(
        {
            "Understanding": [{"intent": "search", "mortgage_too": True}],
            "Plan": [{"tool": "search_flats", "args": {"request": "евродвушка до 9 млн"}}],
            "ParsedQuery": [{"rooms": [2], "euro": True, "price_max_mln": 9}],
        },
        ["Нашёл кв. 75 в ЖК «Тёплые холмы» за 8,76 млн ₽: по семейной ипотеке 42 026 ₽ в месяц."],
    )

    result = await run_turn(
        context(connection, llm, ids), DialogState(), [], "покажите евродвушки до 9 млн и посчитайте ипотеку"
    )

    assert [(step.tool, step.args.get("flat_ids")) for step in result.steps] == [
        ("search_flats", None),
        ("mortgage_calc", [ids["flat"]]),
    ]
    assert llm.asked["Plan"] == 1, "the mortgage of the found flats is not the model's to plan"
    assert result.verified


async def test_it_lets_the_reply_repeat_a_payment_it_named_in_an_earlier_turn(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    ids = await demo(catalog)
    llm = ScriptedLlm({"Understanding": [{"intent": "smalltalk"}]}, ["Напоминаю: 40 542 ₽ в месяц под 6%."])
    history = [
        Turn(role="user", text="сколько в месяц по семейной?"),
        Turn(role="assistant", text="По семейной ипотеке под 6% платёж — 40 542 ₽ в месяц."),
    ]

    result = await run_turn(context(connection, llm, ids), DialogState(), history, "напомните, сколько в месяц?")

    assert (result.verified, result.attempts) == (True, 1)
