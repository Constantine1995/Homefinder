from typing import Any

from psycopg import AsyncConnection
from psycopg_pool import AsyncConnectionPool

from homefinder.config import Settings


def create_pool(settings: Settings) -> AsyncConnectionPool[AsyncConnection[Any]]:
    """Open a pool of read-only sessions: the service writes through Laravel's API, never to the database.

    HNSW scans go on past the first candidates when filters drop them, so a filtered nearest-neighbour query still
    returns its full limit; the order is relaxed and the queries sort the found rows by distance themselves.
    """
    return AsyncConnectionPool(
        str(settings.database_url),
        kwargs={
            "options": "-c default_transaction_read_only=on -c hnsw.iterative_scan=relaxed_order",
            "autocommit": True,
        },
        max_size=settings.pool_max_size,
        open=False,
    )
