"""Tests run against the test database of the Laravel suite: Laravel's migrations own the schema.

Every test runs in a transaction that is rolled back, so do not run pytest and Pest at the same time:
Pest recreates the schema when it starts.
"""

from collections.abc import AsyncIterator
from datetime import date
from typing import Any

import pytest
from psycopg import AsyncConnection
from pydantic_settings import BaseSettings, SettingsConfigDict


class TestSettings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AGENT_", env_file=".env", extra="ignore")

    test_database_url: str = "postgresql://homefinder:secret@127.0.0.1:5432/homefinder_testing"


TEST_DATABASE_URL = TestSettings().test_database_url


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


@pytest.fixture
async def connection() -> AsyncIterator[AsyncConnection[Any]]:
    async with await AsyncConnection.connect(TEST_DATABASE_URL) as connection:
        cursor = await connection.execute("select to_regclass('flats') is not null")
        if not (row := await cursor.fetchone()) or not row[0]:
            pytest.exit(
                "The test database has no schema: run `php artisan test` or `php artisan migrate --env=testing`"
            )
        try:
            yield connection
        finally:
            await connection.rollback()


class Catalog:
    """Inserts the rows of a test catalog, like the Laravel factories; values not given get plain defaults."""

    def __init__(self, connection: AsyncConnection[Any]) -> None:
        self.connection = connection

    async def _insert(self, table: str, values: dict[str, Any]) -> int:
        columns = ", ".join(values)
        placeholders = ", ".join(f"%({column})s" for column in values)
        cursor = await self.connection.execute(
            f"insert into {table} ({columns}) values ({placeholders}) returning id",
            values,
        )
        row = await cursor.fetchone()
        assert row is not None
        return int(row[0])

    async def tenant(self, name: str = "Застройщик") -> int:
        return await self._insert("tenants", {"name": name})

    async def complex(self, tenant_id: int, name: str = "ЖК", latitude: float = 55.75, longitude: float = 37.6) -> int:
        return await self._insert(
            "complexes", {"tenant_id": tenant_id, "name": name, "latitude": latitude, "longitude": longitude}
        )

    async def building(
        self,
        tenant_id: int,
        complex_id: int,
        name: str = "Корпус 1",
        floors: int | None = 17,
        deadline: date | None = date(2027, 12, 31),
    ) -> int:
        return await self._insert(
            "buildings",
            {"tenant_id": tenant_id, "complex_id": complex_id, "name": name, "floors": floors, "deadline": deadline},
        )

    async def flat(self, tenant_id: int, building_id: int, **values: Any) -> int:
        return await self._insert(
            "flats",
            {
                "tenant_id": tenant_id,
                "building_id": building_id,
                "floor": 5,
                "rooms": 2,
                "is_euro": False,
                "area": 55,
                "price": 9_000_000,
                "status": "available",
                **values,
            },
        )

    async def mortgage_program(self, tenant_id: int, name: str, **values: Any) -> int:
        return await self._insert(
            "mortgage_programs",
            {
                "tenant_id": tenant_id,
                "name": name,
                "bank": "Банк «Северный»",
                "rate": 6,
                "min_down_pct": 20,
                "max_amount": 12_000_000,
                "max_years": 30,
                "updated_at": "2026-10-01 09:00:00",
                **values,
            },
        )

    async def document(
        self, tenant_id: int, title: str, source: str, complex_id: int | None = None, content: str = ""
    ) -> int:
        return await self._insert(
            "kb_documents",
            {
                "tenant_id": tenant_id,
                "complex_id": complex_id,
                "title": title,
                "source": source,
                "content": content,
                "content_hash": "test",
                "index_version": 1,
            },
        )

    async def chunk(
        self, tenant_id: int, document_id: int, section: str, text: str, embedding: str | None = None
    ) -> int:
        return await self._insert(
            "kb_chunks",
            {
                "tenant_id": tenant_id,
                "kb_document_id": document_id,
                "position": 0,
                "section": section,
                "text": text,
                "embedding": embedding,
            },
        )

    async def poi(self, poi_type: str, name: str | None = None) -> int:
        cursor = await self.connection.execute(
            "insert into pois (osm_type, osm_id, type, name, geom) "
            "values ('node', (select coalesce(max(osm_id), 0) + 1 from pois), %s, %s, "
            "'SRID=4326;POINT(37.6 55.75)'::geography) returning id",
            (poi_type, name),
        )
        row = await cursor.fetchone()
        assert row is not None
        return int(row[0])

    async def near(self, tenant_id: int, complex_id: int, poi_id: int, distance_m: int, walk_min: int) -> None:
        await self.connection.execute(
            "insert into complex_poi (tenant_id, complex_id, poi_id, distance_m, walk_min) values (%s, %s, %s, %s, %s)",
            (tenant_id, complex_id, poi_id, distance_m, walk_min),
        )


@pytest.fixture
def catalog(connection: AsyncConnection[Any]) -> Catalog:
    return Catalog(connection)
