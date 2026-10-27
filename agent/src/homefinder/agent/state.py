"""What the agent remembers: the dialog state Laravel keeps between turns, and the state of one turn in the graph."""

from typing import Any, Literal

from pydantic import BaseModel

from homefinder.search.filters import FlatFilters

Intent = Literal[
    "search", "details", "compare", "mortgage", "knowledge", "booking", "subscribe", "handoff", "smalltalk"
]


class Lead(BaseModel):
    """The lead card collected along the dialog: budget, payment, terms, family, own flat."""

    budget: int | None = None
    payment: Literal["mortgage", "cash", "installment"] | None = None
    rooms: list[int] = []
    deadline_year: int | None = None
    family: str | None = None
    sells_own_flat: bool | None = None


class DialogState(BaseModel):
    """The state of a dialog between turns; Laravel stores it with the conversation and sends it back."""

    filters: FlatFilters = FlatFilters()
    wishes: str | None = None
    shown_flat_ids: list[int] = []
    """The flats shown to the buyer, the latest answer's last: «первая», «эта» refer to them."""
    offered_flat_ids: list[int] = []
    """Every flat the dialog's searches showed, the latest last. The buyer may name one again after a new search,
    «а сколько платить за кв. 52?»: the reply may then name it too, while it is still on sale."""
    complex_id: int | None = None
    """The complex the dialog is about, for questions like «а паркинг там есть?»."""
    lead: Lead = Lead()


class Turn(BaseModel):
    role: Literal["user", "assistant"]
    text: str


class Observation(BaseModel):
    """What a tool returned: a summary for the model and the data for the verifier."""

    tool: str
    args: dict[str, Any]
    summary: str
    data: dict[str, Any] = {}
    flat_ids: list[int] = []
    """Flats the result shows; a search's become the dialog's shown flats."""
    failed: bool = False
    """The tool did not run: there is no such tool, the arguments were wrong or it cannot do what was asked."""


class Action(BaseModel):
    """A change the agent proposes and Laravel applies by its own rules: the service never writes.

    `handoff` gives the dialog to the managers; `knowledge_gap` is a question the knowledge base has no answer to:
    a task for a manager to answer and a line in the list of what to add to the base; `save_search` subscribes the
    buyer, who asked for it, to new and cheaper flats by `filters`, named by `reason`.
    """

    type: Literal["handoff", "knowledge_gap", "save_search"]
    reason: str
    filters: dict[str, Any] | None = None
