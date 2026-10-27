import pytest

from homefinder.agent.state import Observation
from homefinder.agent.verifier import verify

FLAT = {
    "id": 659,
    "number": "75",
    "price": 8_762_000,
    "price_per_meter": 251_782,
    "area": 34.8,
    "kitchen_area": 15.1,
    "status": "available",
}
SEARCH = Observation(
    tool="search_flats",
    args={"request": "евродвушка до 9 млн"},
    summary="Найдено квартир: 73.",
    data={"total": 73, "flats": [FLAT, {**FLAT, "id": 700, "number": "12", "status": "sold", "price": 7_900_000}]},
)
MORTGAGE = Observation(
    tool="mortgage_calc",
    args={"flat_ids": [659]},
    summary="...",
    data={"calculations": [{"program": "Семейная ипотека", "rate": 6.0, "monthly_payment": 42_026, "loan": 7_009_600}]},
)
KNOWLEDGE = Observation(
    tool="search_knowledge",
    args={"question": "паркинг"},
    summary="[ЖК «Отрадный квартал» › Паркинг] Машино-место — от 2,4 млн ₽, кладовые от 3 до 7 м².",
)


@pytest.mark.parametrize(
    "reply",
    [
        "Нашёл евродвушку кв. 75: 34,8 м², кухня 15,1 м², 8 762 000 ₽.",
        "Квартира № 75 стоит 8,76 млн ₽ — около 9 млн, в вашем бюджете.",
        "По семейной ипотеке под 6% платёж составит 42 026 ₽ в месяц, кредит 7 009 600 ₽.",
        "Машино-место в Отрадном — от 2,4 млн ₽, кладовые от 3 м².",
        "Вы искали до 9 млн — вот что есть. Сдача в 2027 году, до метро 7 минут, 9 этаж из 12.",
        "Кв. 75 за 8 762 000 ₽, сдача III кв. 2027, а корпус 1 — IV кв. 2026.",
    ],
)
def test_it_passes_a_reply_built_on_the_tool_results(reply: str) -> None:
    verdict = verify(reply, [SEARCH, MORTGAGE, KNOWLEDGE], ["Есть евродвушка до 9 млн?"])

    assert verdict.ok, verdict.problems


@pytest.mark.parametrize(
    ("reply", "problem"),
    [
        ("Кв. 75 стоит 8 500 000 ₽.", "сумма «8 500 000 ₽» не из результатов инструментов"),
        ("Есть вариант за 7,2 млн.", "сумма «7,2 млн» не из результатов инструментов"),
        ("Площадь 40 м², отличная кухня.", "площадь «40 м²» не из результатов инструментов"),
        ("Ставка 4,5% по семейной.", "процент «4,5%» не из результатов инструментов"),
        ("Посмотрите кв. 118 — она светлая.", "квартиры № 118 нет в результатах инструментов"),
        ("Есть ещё кв. 12 за 7 900 000 ₽.", "квартира № 12 уже продана или снята с продажи"),
    ],
)
def test_it_catches_what_the_tools_did_not_give(reply: str, problem: str) -> None:
    verdict = verify(reply, [SEARCH, MORTGAGE, KNOWLEDGE], [])

    assert not verdict.ok
    assert problem in verdict.problems


def test_it_takes_percents_only_from_rates_and_texts_not_from_any_small_number() -> None:
    knowledge = Observation(
        tool="search_knowledge",
        args={"question": "что будет при задержке"},
        summary="[Покупка › Ключи] Гарантия на конструктив дома — 5 лет.",
        data={"chunks": [{"text": "Гарантия на конструктив дома — 5 лет.", "score": 0.1}]},
    )
    reply = "При задержке — компенсация 0,1% от стоимости за месяц, 34,8% за год."

    assert verify(reply, [knowledge, SEARCH], []).problems == [
        "процент «0,1%» не из результатов инструментов",
        "процент «34,8%» не из результатов инструментов",
    ]


def test_it_wants_a_monthly_payment_from_the_mortgage_calculator() -> None:
    reply = "Платёж будет около 51 000 ₽ в месяц."

    assert verify(reply, [SEARCH], []).problems == [
        "сумма «51 000 ₽» не из результатов инструментов",
        "платёж «51 000 ₽» не из mortgage_calc",
    ]
    assert verify("Платёж — 42 026 ₽ в месяц.", [MORTGAGE], []).ok


def test_it_allows_the_numbers_and_payments_of_its_earlier_replies() -> None:
    earlier = "По семейной ипотеке под 6% платёж 43 422 ₽ в месяц, взнос 1,81 млн ₽."

    assert verify("Платёж — 43 422 ₽ в месяц под 6%, взнос 1,81 млн ₽.", [], [], [earlier]).ok
    assert verify("Платёж — 43 422 ₽ в месяц.", [], ["платёж 43 422 ₽ в месяц?"]).problems == [
        "платёж «43 422 ₽» не из mortgage_calc"
    ], "a payment the buyer names is not one the tools gave"


def test_it_allows_the_numbers_the_buyer_wrote() -> None:
    assert verify("Понял: бюджет 12 млн, взнос 2 500 000 ₽.", [], ["бюджет 12 млн, взнос 2 500 000 рублей"]).ok


def test_it_leaves_years_minutes_and_floors_alone() -> None:
    assert verify("Сдача во II квартале 2028 года, до метро 7 минут, 14 этаж из 22.", [], []).ok
