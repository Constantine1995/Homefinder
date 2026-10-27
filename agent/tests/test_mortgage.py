from datetime import date
from decimal import Decimal
from typing import Any

import pytest
from psycopg import AsyncConnection

from homefinder.mortgage import annuity, mortgage_calc
from tests.conftest import Catalog

pytestmark = pytest.mark.anyio

TODAY = date(2026, 10, 22)


@pytest.mark.parametrize(
    ("loan", "rate", "years", "payment"),
    [
        pytest.param(10_000_000, "6", 30, 59_955, id="6% for 30 years"),
        pytest.param(8_000_000, "17.5", 20, 120_395, id="17.5% for 20 years"),
        pytest.param(1_200_000, "0", 10, 10_000, id="an interest-free installment plan"),
    ],
)
def test_it_computes_the_annuity_payment(loan: int, rate: str, years: int, payment: int) -> None:
    assert annuity(loan, Decimal(rate), years) == payment


async def two_programs(catalog: Catalog) -> int:
    tenant_id = await catalog.tenant()
    await catalog.mortgage_program(tenant_id, "Стандартная ипотека", rate=17.5, max_amount=30_000_000)
    await catalog.mortgage_program(tenant_id, "Семейная ипотека", rate=6, max_amount=12_000_000)
    await catalog.mortgage_program(tenant_id, "Ипотека с субсидией", rate=9.9, valid_to=date(2026, 10, 21))
    other_tenant_id = await catalog.tenant("Другой застройщик")
    await catalog.mortgage_program(other_tenant_id, "Чужая ипотека", rate=1)
    return tenant_id


async def test_it_computes_every_program_still_offered_lowest_payment_first(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await two_programs(catalog)

    result = await mortgage_calc(connection, tenant_id, TODAY, price=10_000_000, down_payment=2_000_000)

    assert [calculation.program for calculation in result.calculations] == ["Семейная ипотека", "Стандартная ипотека"]
    family = result.calculations[0]
    assert (family.loan, family.years, family.monthly_payment) == (8_000_000, 30, 47_964)
    assert family.overpayment == 47_964 * 360 - 8_000_000
    assert family.eligible
    assert family.actual_on == date(2026, 10, 1)
    assert result.disclaimer == "Расчёт предварительный: ставку и одобрение определяет банк."


async def test_it_puts_the_programs_that_do_not_fit_last_and_says_why(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await two_programs(catalog)

    result = await mortgage_calc(connection, tenant_id, TODAY, price=20_000_000, down_payment=4_000_000)

    assert [(calculation.program, calculation.problems) for calculation in result.calculations] == [
        ("Стандартная ипотека", []),
        ("Семейная ипотека", ["кредит до 12 млн ₽: нужен взнос от 8 млн ₽"]),
    ]


@pytest.mark.parametrize("named", ["семейная", "Семейную ипотеку", "СЕМЕЙНОЙ"])
async def test_it_finds_the_program_the_buyer_named(
    connection: AsyncConnection[Any], catalog: Catalog, named: str
) -> None:
    tenant_id = await two_programs(catalog)

    result = await mortgage_calc(connection, tenant_id, TODAY, price=10_000_000, program=named)

    assert [calculation.program for calculation in result.calculations] == ["Семейная ипотека"]


@pytest.mark.parametrize("named", ["военная ипотека", "Чужая ипотека", "ипотека с субсидией", "ипотека"])
async def test_it_reports_a_program_the_developer_does_not_offer(
    connection: AsyncConnection[Any], catalog: Catalog, named: str
) -> None:
    tenant_id = await two_programs(catalog)

    result = await mortgage_calc(connection, tenant_id, TODAY, price=10_000_000, program=named)

    assert result.calculations == []
    assert result.unknown_program == named


async def test_it_takes_the_smallest_down_payment_and_the_longest_term_unless_told(
    connection: AsyncConnection[Any], catalog: Catalog
) -> None:
    tenant_id = await catalog.tenant()
    await catalog.mortgage_program(tenant_id, "Семейная ипотека", min_down_pct=20.5, max_years=25)

    [calculation] = (await mortgage_calc(connection, tenant_id, TODAY, price=9_000_003)).calculations

    assert (calculation.down_payment, calculation.loan, calculation.years) == (1_845_001, 7_155_002, 25)


@pytest.mark.parametrize(
    ("down_payment", "years", "problems"),
    [
        pytest.param(1_000_000, None, ["первоначальный взнос от 2 млн ₽ (20%)"], id="too small a down payment"),
        pytest.param(2_000_000, 35, ["срок до 30 лет"], id="too long a term"),
        pytest.param(10_000_000, None, ["взнос покрывает цену: ипотека не нужна"], id="paid in full"),
    ],
)
async def test_it_says_what_keeps_a_program_from_fitting(
    connection: AsyncConnection[Any], catalog: Catalog, down_payment: int, years: int | None, problems: list[str]
) -> None:
    tenant_id = await catalog.tenant()
    await catalog.mortgage_program(tenant_id, "Семейная ипотека")

    [calculation] = (
        await mortgage_calc(connection, tenant_id, TODAY, price=10_000_000, down_payment=down_payment, years=years)
    ).calculations

    assert calculation.problems == problems
    assert not calculation.eligible
