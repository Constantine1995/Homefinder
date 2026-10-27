"""The `mortgage_calc` tool: monthly payments by the developer's mortgage programs, computed by code.

The model never does the arithmetic: it passes the price, the down payment and, if the buyer named one, the
program, and quotes what comes back. Every payment is preliminary — the rate and the approval are the bank's.
"""

from datetime import date, datetime
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

from psycopg import AsyncConnection
from psycopg.rows import dict_row
from pydantic import BaseModel

from homefinder.search.explain import money

DISCLAIMER = "Расчёт предварительный: ставку и одобрение определяет банк."


class MortgageProgram(BaseModel):
    id: int
    name: str
    bank: str | None
    rate: Decimal
    min_down_pct: Decimal
    max_amount: int | None
    max_years: int
    conditions: str | None
    valid_to: date | None
    updated_at: datetime | None


class MortgageCalculation(BaseModel):
    program_id: int
    program: str
    bank: str | None
    rate: float
    years: int
    price: int
    down_payment: int
    loan: int
    monthly_payment: int
    overpayment: int
    """What the buyer pays the bank above the loan over the whole term."""
    eligible: bool
    problems: list[str]
    """Why the program does not fit as asked: «первоначальный взнос от 2,4 млн ₽ (20%)»."""
    conditions: str | None
    actual_on: date | None
    """When the developer last updated the program: the terms are as of this day."""


class MortgageResult(BaseModel):
    calculations: list[MortgageCalculation]
    """The programs that fit first, the lowest payment first."""
    unknown_program: str | None = None
    """The program the buyer named that the developer does not have."""
    disclaimer: str = DISCLAIMER


async def mortgage_programs(connection: AsyncConnection[Any], tenant_id: int, today: date) -> list[MortgageProgram]:
    """Get the tenant's programs still offered today."""
    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(
            "select id, name, bank, rate, min_down_pct, max_amount, max_years, conditions, valid_to, updated_at "
            "from mortgage_programs where tenant_id = %s and (valid_to is null or valid_to >= %s) order by id",
            (tenant_id, today),
        )
        return [MortgageProgram.model_validate(row) for row in await cursor.fetchall()]


async def mortgage_calc(
    connection: AsyncConnection[Any],
    tenant_id: int,
    today: date,
    price: int,
    down_payment: int | None = None,
    years: int | None = None,
    program: str | None = None,
) -> MortgageResult:
    """Compute the payment by the named program, or by every program when the buyer named none.

    Without a down payment, the smallest one the program allows is taken; without a term, the longest.
    """
    programs = await mortgage_programs(connection, tenant_id, today)
    if program is not None:
        chosen = [candidate for candidate in programs if _matches(program, candidate)]
        if not chosen:
            return MortgageResult(calculations=[], unknown_program=program)
        programs = chosen

    calculations = [calculate(candidate, price, down_payment, years) for candidate in programs]
    calculations.sort(key=lambda calculation: (not calculation.eligible, calculation.monthly_payment))

    return MortgageResult(calculations=calculations)


def calculate(program: MortgageProgram, price: int, down_payment: int | None, years: int | None) -> MortgageCalculation:
    """Compute the annuity payment of one program and say what keeps it from fitting."""
    min_down = _rubles(Decimal(price) * program.min_down_pct / 100)
    down = min_down if down_payment is None else down_payment
    term = program.max_years if years is None else years
    loan = max(price - down, 0)

    problems: list[str] = []
    if down < min_down:
        problems.append(f"первоначальный взнос от {money(min_down)} ({_percent(program.min_down_pct)})")
    if program.max_amount is not None and loan > program.max_amount:
        problems.append(f"кредит до {money(program.max_amount)}: нужен взнос от {money(price - program.max_amount)}")
    if term > program.max_years:
        problems.append(f"срок до {program.max_years} лет")
    if loan == 0:
        problems.append("взнос покрывает цену: ипотека не нужна")

    monthly = annuity(loan, program.rate, term) if loan > 0 and term > 0 else 0

    return MortgageCalculation(
        program_id=program.id,
        program=program.name,
        bank=program.bank,
        rate=float(program.rate),
        years=term,
        price=price,
        down_payment=down,
        loan=loan,
        monthly_payment=monthly,
        overpayment=monthly * term * 12 - loan if monthly else 0,
        eligible=not problems,
        problems=problems,
        conditions=program.conditions,
        actual_on=program.updated_at.date() if program.updated_at else None,
    )


def annuity(loan: int, rate: Decimal, years: int) -> int:
    """The equal monthly payment that repays the loan with interest over the term, in whole rubles."""
    months = years * 12
    monthly_rate = rate / 100 / 12
    if monthly_rate == 0:
        return _rubles(Decimal(loan) / months)
    payment = Decimal(loan) * monthly_rate / (1 - (1 + monthly_rate) ** -months)
    return _rubles(payment)


def _matches(named: str, program: MortgageProgram) -> bool:
    """Take «семейная», «семейную ипотеку» or «IT» for the program whose name has that word."""
    words = [word[:5] for word in named.casefold().replace("ё", "е").split() if word[:5] != "ипоте"]
    name = program.name.casefold().replace("ё", "е")
    return bool(words) and all(word in name for word in words)


def _rubles(value: Decimal) -> int:
    return int(value.quantize(Decimal(1), rounding=ROUND_HALF_UP))


def _percent(value: Decimal) -> str:
    return f"{value.normalize():f}%".replace(".", ",")
