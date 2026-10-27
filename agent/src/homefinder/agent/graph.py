"""The agent's graph: intent → state → tools (5 steps at most) → reply → verifier → answer.

The model understands the message, picks tools and writes the reply; the code runs the tools with the tenant from
the channel, updates the dialog state and checks the reply. Prices, availability and payments come from tools only.
"""

import json
from typing import Any, Literal, TypedDict

from langgraph.graph import END, START, StateGraph
from pydantic import BaseModel, ValidationError, field_validator, model_validator

from homefinder.agent.privacy import mask
from homefinder.agent.state import Action, DialogState, Intent, Observation, Turn
from homefinder.agent.tools import TOOLS, ToolContext, ToolError, shown_flats
from homefinder.agent.verifier import verify
from homefinder.llm import LlmError
from homefinder.mortgage import DISCLAIMER
from homefinder.search.compare import MAX_FLATS
from homefinder.search.flats import get_flats

MAX_TOOL_STEPS = 5
"""The cap on the tool loop of one turn."""

PLAN_MAX_TOKENS = 200
"""Enough for a tool and its arguments. A small model sometimes wrote the whole answer into the plan's arguments:
a minute of generation on a laptop, cut off by the timeout and then written again word for word."""

MAX_ATTEMPTS = 2
"""Replies written before giving up: the first, and one more with the verifier's problems named."""

HISTORY_TURNS = 8
"""The latest messages of the dialog the model sees."""

MAX_OFFERED_FLATS = 50
"""The flats of earlier searches the verifier keeps in mind: ten searches of five."""

FALLBACK_REPLY = "Хочу ответить точно, поэтому уточню у менеджера отдела продаж — он напишет вам в ближайшее время."

HANDOFF_REPLY = "Этот вопрос решит менеджер отдела продаж: передаю ему наш диалог, он напишет вам в ближайшее время."
"""The reply of a turn that hands the dialog over. Written by the model, it answered the question it hands over:
«скидок сейчас нет» to a buyer asking for a discount."""


_GROUNDING: dict[str, tuple[str, str]] = {
    "search": ("search_flats", "request"),
    "knowledge": ("search_knowledge", "question"),
}
"""The tool a turn of these intents calls with the buyer's message when the model would answer without any."""

_ANSWERING_TOOL: dict[Intent, str] = {
    "search": "search_flats",
    "details": "get_flat",
    "compare": "compare",
    "mortgage": "mortgage_calc",
    "knowledge": "search_knowledge",
    "subscribe": "save_search",
}
"""The tool whose result answers a turn of the intent: once it has run, the turn goes on to the reply. Asked again,
the model only said «answer», and on a laptop that call took longer than the tool."""

_INTENT_TOOLS: dict[Intent, tuple[str, ...]] = {
    "search": ("search_flats", "relax_filters"),
    "details": ("get_flat", "search_flats"),
    "compare": ("compare", "search_flats"),
    "mortgage": ("mortgage_calc", "search_flats", "search_knowledge"),
    "knowledge": ("search_knowledge",),
    "subscribe": ("save_search",),
}
"""The tools the planner is offered for each intent. Offered all of them, a model eased the old search conditions
with relax_filters instead of searching by the new ones, looked flats up in the knowledge base and handed a question
about mortgage approval over to a manager; whether to hand over is the classifier's call."""


class LeadUpdate(BaseModel):
    budget_mln: float | None = None
    payment: Literal["mortgage", "cash", "installment"] | None = None
    deadline_year: int | None = None
    family: str | None = None
    sells_own_flat: bool | None = None

    @model_validator(mode="before")
    @classmethod
    def _unsaid(cls, lead: Any) -> Any:
        """Empty strings and zeros mean «not said»: qwen3 fills in every field it is told about,
        «"deadline_year": 0, "sells_own_flat": ""», and an answer rejected for it lost the intent too.

        So does «"sells_own_flat": false»: qwen3 wrote it for «хочу записаться на просмотр». Only a buyer selling
        their flat matters to the sales department, so «не продаю» is lost rather than made up for everyone.
        And «семейная ипотека» is a mortgage program, not the family, whatever the prompt says: qwen3 put it there
        all the same.
        """
        if not isinstance(lead, dict):
            return lead
        return {
            key: value
            for key, value in lead.items()
            if value != ""
            and not (type(value) in (int, float) and value == 0)
            and not (key == "sells_own_flat" and value is False)
            and not (key == "family" and isinstance(value, str) and "ипотек" in value.lower())
        }


class Understanding(BaseModel):
    intent: Intent = "smalltalk"
    mortgage_too: bool = False
    """The buyer asks for the mortgage of the flats in a message of another intent: «покажите однушки и посчитайте
    ипотеку». With one intent per message the search ended the turn, the reply promised a calculation it did not
    have, and the buyer asked again and again."""
    handoff_reason: str | None = None
    lead: LeadUpdate = LeadUpdate()

    @field_validator("lead", mode="before")
    @classmethod
    def _nothing_said(cls, lead: Any) -> Any:
        """«"lead": null» when the buyer said nothing about themselves: a small model writes it for «Добрый вечер!»,
        and an answer rejected for it turned the greeting into a flat search."""
        return {} if lead is None else lead


class Plan(BaseModel):
    tool: str
    args: dict[str, Any] = {}


class TurnState(TypedDict, total=False):
    message: str
    history: list[Turn]
    dialog: DialogState
    understanding: Understanding
    observations: list[Observation]
    steps: int
    reply: str
    problems: list[str]
    attempts: int
    actions: list[Action]
    verified: bool
    gave_up: bool


class TurnResult(BaseModel):
    """What the agent answers to one message, for Laravel to send, store and apply."""

    reply: str
    dialog: DialogState
    intent: Intent
    actions: list[Action]
    steps: list[Observation]
    verified: bool
    """False when the reply had to be replaced with the fallback."""
    problems: list[str]
    attempts: int
    prompt_tokens: int
    completion_tokens: int


UNDERSTAND_PROMPT = """\
Ты — часть ИИ-менеджера отдела продаж застройщика. Определи, чего хочет покупатель в последнем сообщении, \
с учётом диалога. Сегодня {today}. Ответь JSON:
{{"intent": "...", "mortgage_too": false, "handoff_reason": null или "...", "lead": {{...}}}}

intent — одно из:
- search — подобрать квартиры или изменить условия подбора («а если до 10 млн?», «покажите трёшки», \
«а с отделкой есть?», «покажите похожие на кв. 12»);
- details — подробнее о показанной квартире; compare — сравнить показанные квартиры;
- mortgage — платёж по ипотеке, взнос, программы;
- knowledge — вопрос о ЖК, паркинге, школах, сроках, отделке («что входит в отделку?»), покупке, документах, \
акциях, офисе продаж;
- booking — записаться на просмотр или в офис;
- subscribe — просит написать, когда появится подходящая квартира или подешевеет («напишите, если появится \
двушка до 9 млн», «сообщите, когда снизят цену»);
- handoff — нужен живой менеджер: торг или просьба скидки («а скидку сделаете?», «уступите?», «можно дешевле, \
если сразу?»), трейд-ин («можно сдать старую квартиру в зачёт новой?», «зачтёте мою однушку?»), \
расчёт рассрочки («посчитайте рассрочку на кв. 59»), юридический или налоговый вопрос, жалоба или недовольство, \
покупатель сам просит человека. Вопрос «какие есть акции?» или «есть ли рассрочка?» — это knowledge, \
а просьба скидки себе — handoff. Вопрос об одобрении ипотеки («одобрят ли самозанятому?», «какие шансы?») — \
knowledge: одобрение решает банк, менеджер его тоже не обещает;
- smalltalk — приветствие, благодарность, прощание, «подумаю», «хорошо», что-то не по теме.

mortgage_too — true, если при другом intent покупатель в том же сообщении просит посчитать ипотеку или платёж \
(«покажите однушки и посчитайте ипотеку»). «Покупаю в ипотеку» — не просьба посчитать.
handoff_reason — коротко, почему нужен менеджер, только для intent handoff.
lead — что покупатель сказал о себе, только явно сказанное: budget_mln (бюджет в млн), payment (mortgage, cash, \
installment), deadline_year (к какому году нужна квартира), family (кто будет жить, словами покупателя: \
«двое детей», «с женой»; «семейная ипотека» — это payment mortgage, а не family), \
sells_own_flat (продаёт своё жильё)."""

PLAN_PROMPT = """\
Ты подбираешь инструменты, чтобы ответить покупателю квартиры. Цены, наличие, платежи и факты о ЖК бери только \
из инструментов. Сегодня {today}. Намерение покупателя: {intent}.

Инструменты (имя: назначение; аргументы):
{tools}

Состояние диалога:
{dialog}

Уже вызвано в этом ходе:
{observations}

Ответь JSON одного шага: {{"tool": "имя", "args": {{...}}}}. Если данных уже хватает для ответа, \
или инструменты не нужны, ответь ровно {{"tool": "answer"}}: ответ покупателю пишешь не ты, данные в него не \
переписывай. Не вызывай инструмент повторно с теми же аргументами."""

REPLY_PROMPT = """\
Ты — вежливый ИИ-менеджер отдела продаж застройщика в мессенджере. Ответь покупателю по-русски, коротко и по делу.

Правила:
- Цены, площади, номера квартир, проценты и платежи бери только из результатов инструментов ниже, без изменений. \
Не придумывай квартиры, цифры и факты. Если данных нет — так и скажи и предложи уточнить у менеджера.
- Ничего не считай сам: доли от цены, взносы, наценки, рассрочку — только готовые числа из результатов.
{flats_rule}
- Ипотечный расчёт называй предварительным: ставку и одобрение определяет банк. Не обещай одобрение.
- Не давай скидок и не обещай их, кроме акций из базы знаний. Не консультируй по юридическим и налоговым \
вопросам. Не обсуждай конкурентов.
- Не проси телефон, пока покупатель сам не захочет записаться или поговорить с менеджером.
- В конце предложи следующий шаг: показать похожие, посчитать ипотеку, записаться на просмотр.

Результаты инструментов этого хода:
{observations}"""

FLATS_RULE = (
    "- Квартиры называй так: «кв. <номер> в ЖК «<название>»», с ценой и главным — почему подходит. Не больше трёх."
)

CARDS_RULE = (
    "- Найденные квартиры покупатель увидит карточками под ответом: планировка, цена, площадь, этаж, срок. "
    "Не перечисляй их и не повторяй их номера, цены и площади. В одном-двух предложениях скажи, что вот подходящие "
    "варианты и чем они подходят под условия покупателя; общее число найденных не называй — карточек меньше."
)
"""For a channel with cards: a list that repeats the cards made the buyer read everything twice, and on a laptop
writing it took most of the turn."""

CARD_TOOLS = ("search_flats", "get_flat")
"""Tools whose flats Laravel sends as cards; the same list as TelegramMessenger::CARD_TOOLS."""

NO_FLATS_RULE = (
    "- Квартир в результатах нет: не называй и не придумывай квартиры, их номера, цены и площади. "
    "Другие числа — только из результатов ниже."
)
"""Without flats in the results a small model wrote a list of its own after a greeting or a legal question, with
prices and rates; the rule on how to name flats only invited it."""


def build_graph(context: ToolContext) -> Any:
    """Assemble the graph for one turn; the context carries the tenant, the database and the models."""
    llm = context.llm

    async def understand(state: TurnState) -> TurnState:
        messages = [
            {"role": "system", "content": UNDERSTAND_PROMPT.format(today=context.today.isoformat())},
            *_history_messages(state),
        ]
        try:
            understanding = await llm.structured(messages, Understanding)
        except LlmError:
            understanding = Understanding(intent="search")
        return {"understanding": understanding}

    async def update_state(state: TurnState) -> TurnState:
        dialog = state["dialog"].model_copy(deep=True)
        lead = state["understanding"].lead
        if lead.budget_mln:
            dialog.lead.budget = round(lead.budget_mln * 1_000_000)
        for field in ("payment", "deadline_year", "family", "sells_own_flat"):
            if (value := getattr(lead, field)) is not None:
                setattr(dialog.lead, field, value)
        return {"dialog": dialog, "observations": [], "steps": 0, "attempts": 0, "actions": []}

    async def act(state: TurnState) -> TurnState:
        understanding = state["understanding"]
        if understanding.intent in ("handoff", "booking"):
            reason = understanding.handoff_reason or (
                "запись на просмотр" if understanding.intent == "booking" else "покупатель просит менеджера"
            )
            plan = Plan(tool="handoff_to_human", args={"reason": reason})
        elif flat_ids := _flats_to_compute(understanding, state["observations"]):
            plan = Plan(tool="mortgage_calc", args={"flat_ids": flat_ids})
        elif understanding.intent == "subscribe" and not state["observations"]:
            # Asked for a subscription, the buyer gets it with the conditions they name: there is nothing to plan.
            plan = Plan(tool="save_search", args={"request": state["message"]})
        else:
            plan = await _plan(state)
            if plan.tool == "answer" and not state["observations"] and understanding.intent in _GROUNDING:
                # A fact without a tool is made up: a small model answered «скидок нет» from nowhere.
                tool, argument = _GROUNDING[understanding.intent]
                plan = Plan(tool=tool, args={argument: state["message"]})

        # The same call again gives the same result: a small model repeated a failing call until the cap.
        if plan.tool == "answer" or any(
            (observation.tool, observation.args) == (plan.tool, plan.args) for observation in state["observations"]
        ):
            return {"steps": MAX_TOOL_STEPS}

        observation = await _run(plan, state)
        dialog = state["dialog"]
        if observation.tool == "search_flats" and observation.flat_ids:
            # Shown now, not at the end of the turn: «посчитай ипотеку за первую» may follow in the same turn.
            # A card or a comparison keeps the list: after «подробнее про вторую», «первая» is still the first.
            offered = [flat_id for flat_id in dialog.offered_flat_ids if flat_id not in observation.flat_ids]
            dialog = dialog.model_copy(
                update={
                    "shown_flat_ids": observation.flat_ids,
                    "offered_flat_ids": [*offered, *observation.flat_ids][-MAX_OFFERED_FLATS:],
                }
            )
        actions = list(state["actions"])
        if observation.tool == "handoff_to_human":
            actions.append(Action(type="handoff", reason=str(observation.args.get("reason", ""))))
        if observation.tool == "save_search" and not observation.failed:
            actions.append(
                Action(
                    type="save_search",
                    reason=observation.data["description"],
                    filters=observation.data["filters"],
                )
            )
        if observation.tool == "search_knowledge" and observation.data.get("found") is False:
            actions.append(Action(type="knowledge_gap", reason=str(observation.args.get("question", ""))))
        observations = [*state["observations"], observation]
        answered = (
            not observation.failed
            and observation.tool in (_ANSWERING_TOOL.get(understanding.intent), "mortgage_calc")
            and not _flats_to_compute(understanding, observations)
        )
        return {
            "observations": observations,
            "steps": MAX_TOOL_STEPS if observation.tool == "handoff_to_human" or answered else state["steps"] + 1,
            "actions": actions,
            "dialog": dialog,
        }

    async def _plan(state: TurnState) -> Plan:
        prompt = PLAN_PROMPT.format(
            today=context.today.isoformat(),
            intent=state["understanding"].intent,
            tools=_tool_list(_offered_tools(state["understanding"].intent, state["dialog"])),
            dialog=_dialog_summary(state["dialog"], await shown_flats(context, state["dialog"]), context.complexes),
            observations=_observations(state["observations"]) or "ничего",
        )
        try:
            return await llm.structured(
                [{"role": "system", "content": prompt}, *_history_messages(state)], Plan, max_tokens=PLAN_MAX_TOKENS
            )
        except LlmError:
            return Plan(tool="answer")

    async def _run(plan: Plan, state: TurnState) -> Observation:
        tool = TOOLS.get(plan.tool)
        if tool is None:
            return Observation(
                tool=plan.tool, args=plan.args, summary=f"Ошибка: инструмента «{plan.tool}» нет.", failed=True
            )
        allowed = _offered_tools(state["understanding"].intent, state["dialog"])
        if state["understanding"].mortgage_too:
            allowed += ("mortgage_calc",)
        if tool.name not in allowed:
            summary = f"Ошибка: «{tool.name}» здесь не нужен, можно: {', '.join(allowed) or 'ничего'}."
            return Observation(tool=tool.name, args=plan.args, summary=summary, failed=True)
        try:
            arguments = tool.args.model_validate(plan.args)
            return await tool.run(context, state["dialog"], arguments)
        except ValidationError as error:
            return Observation(
                tool=tool.name, args=plan.args, summary=f"Ошибка в аргументах: {_short(error)}", failed=True
            )
        except ToolError as error:
            return Observation(tool=tool.name, args=plan.args, summary=f"Ошибка: {error}", failed=True)

    async def reply(state: TurnState) -> TurnState:
        if any(observation.tool == "handoff_to_human" for observation in state["observations"]):
            return {"reply": HANDOFF_REPLY, "attempts": state["attempts"] + 1}
        observations = state["observations"]
        names_flats = any(o.flat_ids for o in observations)
        shows_cards = context.cards and any(o.tool in CARD_TOOLS and o.flat_ids and not o.failed for o in observations)
        prompt = REPLY_PROMPT.format(
            flats_rule=CARDS_RULE if shows_cards else FLATS_RULE if names_flats else NO_FLATS_RULE,
            observations=_observations(observations) or "инструменты не вызывались",
        )
        messages = [{"role": "system", "content": prompt}, *_history_messages(state)]
        if state.get("problems"):
            # Told only what was wrong, the model wrote the reply anew and did the same sum again: «цена с учётом 3%»
            # was counted in both attempts. It sees its reply now and is asked to take the numbers out of it.
            messages += [
                {"role": "assistant", "content": state["reply"]},
                {
                    "role": "system",
                    "content": "Этот ответ не прошёл проверку: "
                    + "; ".join(state["problems"])
                    + ". Перепиши его: убери эти числа и ничего не считай сам. Чего нет в результатах инструментов, "
                    "так и скажи и предложи уточнить у менеджера.",
                },
            ]
        try:
            text = await llm.text(messages)
        except LlmError as error:
            # The buyer is told a manager will answer, so one has to: the dialog goes over.
            return {
                "reply": FALLBACK_REPLY,
                "attempts": state["attempts"] + 1,
                "gave_up": True,
                "verified": False,
                "problems": [f"модель не ответила: {error}"],
                "actions": [*state["actions"], Action(type="handoff", reason="модель не ответила")],
            }
        return {"reply": _with_notes(text, state["observations"]), "attempts": state["attempts"] + 1}

    async def check(state: TurnState) -> TurnState:
        buyer_texts = [turn.text for turn in state["history"] if turn.role == "user"] + [state["message"]]
        dialog = state["dialog"]
        named = list(dict.fromkeys([*_turn_flat_ids(state), *dialog.shown_flat_ids, *dialog.offered_flat_ids]))
        current = await get_flats(context.connection, context.tenant_id, named) if named else []
        # The flats shown earlier may be named again, «платёж за первую, кв. 52»: their facts are read anew. A new
        # search replaces the shown flats, and the buyer asking about кв. 52 of the search before got the fallback.
        shown = Observation(
            tool="shown_flats", args={}, summary="", data={"flats": [flat.model_dump(mode="json") for flat in current]}
        )
        earlier_replies = [turn.text for turn in state["history"] if turn.role == "assistant"]
        verdict = verify(state["reply"], [*state["observations"], shown], buyer_texts, earlier_replies)
        problems = list(verdict.problems)
        problems += [
            f"квартира № {flat.number} уже не продаётся"
            for flat in current
            if flat.status.value != "available" and flat.number and f"кв. {flat.number}" in state["reply"]
        ]
        if not problems:
            return {"problems": [], "verified": True}
        if state["attempts"] >= MAX_ATTEMPTS:
            actions = [
                *state["actions"],
                Action(type="handoff", reason="ответ не прошёл проверку: " + "; ".join(problems)),
            ]
            return {"problems": problems, "verified": False, "reply": FALLBACK_REPLY, "actions": actions}
        return {"problems": problems, "verified": False}

    def after_act(state: TurnState) -> str:
        return "reply" if state["steps"] >= MAX_TOOL_STEPS else "act"

    def after_check(state: TurnState) -> str:
        return END if state.get("verified") or state["attempts"] >= MAX_ATTEMPTS else "reply"

    graph = StateGraph(TurnState)
    graph.add_node("understand", understand)
    graph.add_node("update_state", update_state)
    graph.add_node("act", act)
    graph.add_node("reply", reply)
    graph.add_node("check", check)
    graph.add_edge(START, "understand")
    graph.add_edge("understand", "update_state")
    graph.add_conditional_edges(
        "update_state", lambda state: "reply" if state["understanding"].intent == "smalltalk" else "act"
    )
    graph.add_conditional_edges("act", after_act)
    graph.add_conditional_edges("reply", lambda state: END if state.get("gave_up") else "check")
    graph.add_conditional_edges("check", after_check)

    return graph.compile()


async def run_turn(context: ToolContext, dialog: DialogState, history: list[Turn], message: str) -> TurnResult:
    """Answer one message of the buyer; phones and emails never reach the model."""
    context.llm = context.llm.fresh()
    masked_history = [turn.model_copy(update={"text": mask(turn.text)}) for turn in history[-HISTORY_TURNS:]]
    final: TurnState = await build_graph(context).ainvoke(
        {"message": mask(message), "history": masked_history, "dialog": dialog}
    )

    return TurnResult(
        reply=final["reply"],
        dialog=final["dialog"],
        intent=final["understanding"].intent,
        actions=final.get("actions", []),
        steps=final.get("observations", []),
        verified=final.get("verified", False),
        problems=final.get("problems", []),
        attempts=final.get("attempts", 0),
        prompt_tokens=context.llm.prompt_tokens,
        completion_tokens=context.llm.completion_tokens,
    )


def _offered_tools(intent: Intent, dialog: DialogState) -> tuple[str, ...]:
    """The tools of the intent; about flats already shown there is nothing to search. Offered a search, the model
    searched for «сравните первую и вторую», and the new results took the place of the flats to compare."""
    tools = _INTENT_TOOLS.get(intent, ())
    if intent in ("details", "compare") and dialog.shown_flat_ids:
        return tuple(tool for tool in tools if tool != "search_flats")
    return tools


def _flats_to_compute(understanding: Understanding, observations: list[Observation]) -> list[int]:
    """The flats the turn's search found, while the buyer asked for their mortgage too and it is not computed yet."""
    if not understanding.mortgage_too or any(observation.tool == "mortgage_calc" for observation in observations):
        return []
    found = [o.flat_ids for o in observations if o.tool == "search_flats" and o.flat_ids and not o.failed]
    return found[-1][:MAX_FLATS] if found else []


def _history_messages(state: TurnState) -> list[dict[str, str]]:
    return [{"role": turn.role, "content": turn.text} for turn in state["history"]] + [
        {"role": "user", "content": state["message"]}
    ]


def _turn_flat_ids(state: TurnState) -> list[int]:
    """The flats the tools of this turn returned: the reply may name any of them."""
    return list(dict.fromkeys(flat_id for observation in state["observations"] for flat_id in observation.flat_ids))


def _with_notes(reply: str, observations: list[Observation]) -> str:
    """Add what every answer must have and a small model now and then leaves out: a mortgage calculation is marked as
    preliminary, an answer from the knowledge base links to its source."""
    notes = []
    if any(o.tool == "mortgage_calc" and not o.failed for o in observations) and "предварительн" not in reply.lower():
        notes.append(DISCLAIMER)
    sources = {
        chunk["source"]: chunk["title"]
        for observation in observations
        if observation.tool == "search_knowledge"
        for chunk in observation.data.get("chunks", [])
    }
    if sources and not any(source in reply for source in sources):
        notes.append("Источник: " + "; ".join(f"{title} — {source}" for source, title in list(sources.items())[:2]))
    return "\n\n".join([reply, *notes])


def _tool_list(names: tuple[str, ...]) -> str:
    lines = []
    for tool in (TOOLS[name] for name in names):
        fields = ", ".join(
            # «(необязательно)» rather than «name?»: a small model copied the question mark into the argument's name.
            f"{name}{'' if field.is_required() else ' (необязательно)'}"
            + (f" — {field.description}" if field.description else "")
            for name, field in tool.args.model_fields.items()
        )
        lines.append(f"- {tool.name}: {tool.description}; аргументы: {fields or 'нет'}")
    return "\n".join(lines)


def _dialog_summary(dialog: DialogState, shown: str, complexes: dict[str, int]) -> str:
    """The dialog for the planner. The complex goes by name, as the tools take it: given its ID, the model passed
    `"complex": 3` to search_knowledge, the call failed, and the question was searched in every complex."""
    filters = dialog.filters.model_dump(exclude_defaults=True, mode="json")
    complex_name = next((name for name, complex_id in complexes.items() if complex_id == dialog.complex_id), None)
    summary = json.dumps(
        {"условия подбора": filters or "не заданы", "пожелания": dialog.wishes, "ЖК диалога": complex_name},
        ensure_ascii=False,
    )
    return f"{summary}\n{shown}"


def _observations(observations: list[Observation]) -> str:
    return "\n\n".join(
        f"### {observation.tool} {json.dumps(observation.args, ensure_ascii=False)}\n{observation.summary}"
        for observation in observations
    )


def _short(error: ValidationError) -> str:
    return "; ".join(f"{'.'.join(map(str, item['loc']))}: {item['msg']}" for item in error.errors())
