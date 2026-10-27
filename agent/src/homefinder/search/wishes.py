"""Soft wishes: «светлая», «вид на парк», «тихий двор» are matched by meaning, not by SQL.

Each flat gets a vector of its description; the vector of the buyer's wishes orders the flats that passed the hard
filters, the closest first. Laravel stores the vectors in flats.embedding: the service only reads.
"""

import math
import re
from typing import Any

from psycopg import AsyncConnection
from psycopg.rows import dict_row

from homefinder.llm import Llm
from homefinder.search.flats import FlatSearchResult

FEATURE_LABELS = {
    "window_view": "Окна",
    "balcony": "Балкон или лоджия",
    "bathroom_unit": "Санузел",
    "rooms_type": "Комнаты",
    "ceiling_height": "Потолки, м",
}
"""Features of the feed that describe a flat, for flats that came without a description."""


def flat_document(description: str | None, features: dict[str, Any]) -> str:
    """Get the text a flat is embedded by: its description, or its features when the feed gave no description."""
    if description and description.strip():
        return description.strip()

    return ". ".join(f"{label}: {features[key]}" for key, label in FEATURE_LABELS.items() if features.get(key))


async def embed_flats(
    connection: AsyncConnection[Any], llm: Llm, tenant_id: int, flat_ids: list[int]
) -> dict[int, list[float]]:
    """Compute the vectors of the tenant's flats; flats with nothing to embed are left out."""
    async with connection.cursor(row_factory=dict_row) as cursor:
        await cursor.execute(
            "select id, description, coalesce(features, '{}'::jsonb) as features from flats "
            "where tenant_id = %s and id = any(%s) order by id",
            (tenant_id, flat_ids),
        )
        documents = {row["id"]: flat_document(row["description"], row["features"]) for row in await cursor.fetchall()}

    documents = {flat_id: document for flat_id, document in documents.items() if document}
    vectors = await llm.embed(list(documents.values()))

    return dict(zip(documents, vectors, strict=True))


MIN_WISH_SIMILARITY = 0.78
"""A sentence less like the wishes than this, as bge-m3 measures, does not count as meeting them.

Measured on the 578 distinct sentences of the demo descriptions: sentences that meet a wish score 0.78-0.93
(«вид на парк» → «Вид на парк.» 0.93), the best of the rest up to 0.77 («вид на реку» → «Вид на парк.»).
"""


async def explain_wishes(llm: Llm, wishes: list[float], result: FlatSearchResult) -> FlatSearchResult:
    """Quote, for each flat, the sentence of its description closest to the wishes: «Окна на юг, в тихий двор»."""
    sentences = {flat.id: _sentences(flat.description) for flat in result.flats}
    unique = sorted({sentence for flat_sentences in sentences.values() for sentence in flat_sentences})
    vectors = dict(zip(unique, await llm.embed(unique), strict=True))

    flats = []
    for flat in result.flats:
        scored = [(_cosine(wishes, vectors[sentence]), sentence) for sentence in sentences[flat.id]]
        similarity, sentence = max(scored, default=(0.0, ""))
        if similarity >= MIN_WISH_SIMILARITY:
            flat = flat.model_copy(update={"reasons": [*flat.reasons, f"по описанию: «{sentence.rstrip('.')}»"]})
        flats.append(flat)

    return result.model_copy(update={"flats": flats})


def _sentences(description: str | None) -> list[str]:
    return [sentence.strip() for sentence in re.split(r"(?<=[.!?])\s+", description or "") if sentence.strip()]


def _cosine(first: list[float], second: list[float]) -> float:
    dot = sum(a * b for a, b in zip(first, second, strict=True))
    norms = math.sqrt(sum(a * a for a in first)) * math.sqrt(sum(b * b for b in second))
    return dot / norms if norms else 0.0
