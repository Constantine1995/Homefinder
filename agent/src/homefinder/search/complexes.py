from typing import Any

from psycopg import AsyncConnection


async def complexes_by_name(connection: AsyncConnection[Any], tenant_id: int) -> dict[str, int]:
    """Get the tenant's complexes by name, for the model to recognize them in a buyer's message."""
    cursor = await connection.execute(
        "select name, id from complexes where tenant_id = %s order by name, id", (tenant_id,)
    )
    return {name: complex_id for name, complex_id in await cursor.fetchall()}
