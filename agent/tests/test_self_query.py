from datetime import date
from typing import Any

import pytest
from pydantic import BaseModel, SecretStr

from homefinder.config import Settings
from homefinder.llm import Llm, LlmError
from homefinder.search.filters import Finishing, FlatFilters, PoiType
from homefinder.search.self_query import EXAMPLES, ParsedQuery, parse_query, to_filters

pytestmark = pytest.mark.anyio

TODAY = date(2026, 10, 20)
COMPLEXES = {"Отрадный квартал": 1, "Речная терраса": 2, "Тёплые холмы": 3}
SETTINGS = Settings(token=SecretStr("x" * 16))


def filters_of(**parsed: Any) -> FlatFilters:
    return to_filters(ParsedQuery(**parsed), COMPLEXES, TODAY).filters


@pytest.mark.parametrize(
    ("parsed", "expected"),
    [
        pytest.param({"price_max_mln": 9}, {"price_to": 9_000_000}, id="millions"),
        pytest.param({"price_max_mln": 12.5}, {"price_to": 12_500_000}, id="a fraction of a million"),
        pytest.param({"price_max_mln": 0.85}, {"price_to": 850_000}, id="thousands"),
        pytest.param({"price_max_mln": 9_500_000}, {"price_to": 9_500_000}, id="rubles the model multiplied itself"),
        pytest.param({"price_min_mln": 0, "price_max_mln": -1}, {}, id="no price"),
        pytest.param({"deadline_year": 2027}, {"deadline_to": date(2027, 12, 31)}, id="the end of the year"),
        pytest.param({"deadline_year": 2027, "deadline_quarter": 1}, {"deadline_to": date(2027, 3, 31)}, id="Q1"),
        pytest.param({"deadline_year": 2027, "deadline_quarter": 2}, {"deadline_to": date(2027, 6, 30)}, id="Q2"),
        pytest.param({"deadline_year": 2027, "deadline_quarter": 3}, {"deadline_to": date(2027, 9, 30)}, id="Q3"),
        pytest.param({"ready": True}, {"deadline_to": TODAY}, id="a completed building"),
        pytest.param({"ready": True, "deadline_year": 2027}, {"deadline_to": TODAY}, id="completed beats a year"),
        pytest.param({"deadline_year": 27}, {}, id="a year the model cut short"),
        pytest.param({"rooms": [2, 1, 2]}, {"rooms": [1, 2]}, id="rooms once each"),
        pytest.param({"euro": True, "rooms": [2]}, {"rooms": [2], "is_euro": True}, id="euro"),
        pytest.param({"metro_walk_max_min": 90}, {"metro_walk_to": 60}, id="a walk too long"),
        pytest.param({"metro_walk_max_min": 0}, {"metro_walk_to": 1}, id="a walk too short"),
        pytest.param({"floor_min": 6, "not_last_floor": True}, {"floor_from": 6, "not_last_floor": True}, id="floors"),
        pytest.param({"area_min": 50, "area_max": 0}, {"area_from": 50}, id="no upper area"),
        pytest.param({"nearby": ["school", "park", "school"]}, {"nearby": [PoiType.PARK, PoiType.SCHOOL]}, id="nearby"),
        pytest.param(
            {"finishings": ["with_finishing", "finished"]},
            {"finishings": [Finishing.FINISHED, Finishing.FURNISHED]},
            id="any finishing to move in with",
        ),
        pytest.param(
            {"finishings": ["furnished", "finished"]},
            {"finishings": [Finishing.FINISHED, Finishing.FURNISHED]},
            id="finishings",
        ),
    ],
)
def test_it_turns_what_the_model_extracted_into_filters(parsed: dict[str, Any], expected: dict[str, Any]) -> None:
    assert filters_of(**parsed) == FlatFilters(**expected)


@pytest.mark.parametrize(
    "name",
    [
        "Отрадный квартал",
        "отрадный квартал",
        "ЖК «Отрадный квартал»",
        "Жилой комплекс Отрадный квартал",
        "Отрадный кавртал",
    ],
)
def test_it_recognizes_a_complex_by_its_name(name: str) -> None:
    result = to_filters(ParsedQuery(complexes=[name]), COMPLEXES, TODAY)

    assert result.filters.complex_ids == [1]
    assert result.unknown_complexes == []


def test_it_matches_ye_with_yo() -> None:
    """A short name is too unlike its spelling with «е» to pass for a typo."""
    result = to_filters(ParsedQuery(complexes=["Елки", "Ёлки"]), {"Ёлки": 7}, TODAY)

    assert result.filters.complex_ids == [7]
    assert result.unknown_complexes == []


def test_it_reports_the_complexes_the_developer_does_not_have() -> None:
    result = to_filters(ParsedQuery(complexes=["Солнечный город", "Речная терраса"]), COMPLEXES, TODAY)

    assert result.filters.complex_ids == [2]
    assert result.unknown_complexes == ["Солнечный город"]


def test_it_keeps_the_soft_wishes_apart() -> None:
    assert to_filters(ParsedQuery(wishes="  вид на парк "), COMPLEXES, TODAY).wishes == "вид на парк"
    assert to_filters(ParsedQuery(wishes="  "), COMPLEXES, TODAY).wishes is None


def test_it_searches_the_flats_on_sale() -> None:
    assert filters_of(rooms=[2]).statuses == FlatFilters().statuses


class RecordingLlm(Llm):
    def __init__(self, answer: ParsedQuery) -> None:
        super().__init__(SETTINGS)
        self.answer = answer
        self.messages: list[dict[str, str]] = []

    async def structured[T: BaseModel](
        self, messages: list[dict[str, str]], schema: type[T], max_tokens: int | None = None
    ) -> T:
        self.messages = messages
        return schema.model_validate(self.answer.model_dump())


async def test_it_tells_the_model_the_date_the_complexes_and_examples() -> None:
    llm = RecordingLlm(ParsedQuery(rooms=[2], price_max_mln=9))

    result = await parse_query(llm, "двушка до 9 лямов", COMPLEXES, TODAY)

    assert result.filters == FlatFilters(rooms=[2], price_to=9_000_000)
    system = llm.messages[0]["content"]
    assert "2026-10-20" in system
    assert "«Отрадный квартал», «Речная терраса», «Тёплые холмы»" in system
    assert [message["role"] for message in llm.messages[1:]] == ["user", "assistant"] * len(EXAMPLES) + ["user"]
    assert llm.messages[-1] == {"role": "user", "content": "двушка до 9 лямов"}


async def test_it_reads_the_structured_answer_of_the_model() -> None:
    llm = Llm(SETTINGS, mock_response='{"rooms": [1], "price_max_mln": 6}')

    result = await parse_query(llm, "однушка до 6 млн", COMPLEXES, TODAY)

    assert result.filters == FlatFilters(rooms=[1], price_to=6_000_000)


async def test_it_gives_up_on_an_answer_outside_the_schema() -> None:
    llm = Llm(SETTINGS, mock_response="двушка, до 9 млн")

    with pytest.raises(LlmError, match="ParsedQuery"):
        await parse_query(llm, "двушка", COMPLEXES, TODAY)


def test_it_reads_a_free_answer_as_far_as_it_makes_sense() -> None:
    parsed = ParsedQuery.model_validate_json(
        '{"rooms": [2, 5, -1, "три"], "nearby": ["school", "metro"], "finishings": ["finished", "marble"],'
        ' "deadline_year": 2027, "deadline_quarter": 7, "view": "парк"}'
    )

    assert parsed == ParsedQuery(
        rooms=[2, 4], nearby=["school"], finishings=[Finishing.FINISHED], deadline_year=2027, deadline_quarter=None
    )


def test_it_reads_a_single_value_as_a_list_of_it() -> None:
    parsed = ParsedQuery.model_validate_json(
        '{"complexes": "Речная терраса", "rooms": 2, "nearby": "park", "finishings": null}'
    )

    assert parsed == ParsedQuery(complexes=["Речная терраса"], rooms=[2], nearby=["park"], finishings=[])
