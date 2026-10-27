import pytest
from psycopg.errors import ReadOnlySqlTransaction
from pydantic import PostgresDsn, SecretStr

from homefinder.config import Settings
from homefinder.db import create_pool
from tests.conftest import TEST_DATABASE_URL

pytestmark = pytest.mark.anyio


async def test_the_service_cannot_write_to_the_database() -> None:
    settings = Settings(database_url=PostgresDsn(TEST_DATABASE_URL), token=SecretStr("x" * 16))

    async with create_pool(settings) as pool, pool.connection() as connection:
        with pytest.raises(ReadOnlySqlTransaction):
            await connection.execute("insert into tenants (name) values ('Чужак')")


def test_it_refuses_to_start_with_a_weak_token() -> None:
    with pytest.raises(ValueError, match="AGENT_TOKEN"):
        Settings(token=SecretStr("short"))
