import pytest

from homefinder.agent.privacy import mask


@pytest.mark.parametrize(
    ("text", "masked"),
    [
        ("мой номер +7 916 123-45-67", "мой номер [телефон]"),
        ("звоните 8(916)1234567 после шести", "звоните [телефон] после шести"),
        ("89161234567", "[телефон]"),
        ("+7-916-123-45-67, Анна", "[телефон], Анна"),
        ("пишите на anna.petrova+flat@mail.ru", "пишите на [email]"),
        ("телефон 8 916 123 45 67, почта a@b.ru", "телефон [телефон], почта [email]"),
    ],
)
def test_it_hides_phones_and_emails(text: str, masked: str) -> None:
    assert mask(text) == masked


@pytest.mark.parametrize(
    "text",
    [
        "двушка до 9 500 000 ₽",
        "бюджет 8 млн, взнос 2 000 000",
        "квартира № 875, 8 этаж",
        "сдача в 2027",
        "площадь 78,5",
    ],
)
def test_it_leaves_prices_and_numbers_alone(text: str) -> None:
    assert mask(text) == text
