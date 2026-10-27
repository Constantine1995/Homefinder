"""Personal data stays out of the model: phones and emails become placeholders before any text reaches it.

Text is masked before it is sent to the model, and traces hold the masked text only. Laravel keeps the original
messages; the agent never needs the numbers themselves.
"""

import re

PHONE_PLACEHOLDER = "[телефон]"
EMAIL_PLACEHOLDER = "[email]"

_EMAIL = re.compile(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+", re.UNICODE)

# Russian numbers as people write them: +7 916 123-45-67, 8(916)1234567, 89161234567, +7-916-123-45-67.
# Money is not a phone: «9 500 000 ₽» has no leading 7/8 and +, and prices are matched first by the caller's units.
_PHONE = re.compile(r"(?<![\w+])(?:\+7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)")


def mask(text: str) -> str:
    """Replace phones and emails with placeholders."""
    return _PHONE.sub(PHONE_PLACEHOLDER, _EMAIL.sub(EMAIL_PLACEHOLDER, text))
