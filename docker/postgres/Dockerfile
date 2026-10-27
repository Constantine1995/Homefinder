FROM postgres:16-trixie

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        "postgresql-$PG_MAJOR-postgis-3" \
        "postgresql-$PG_MAJOR-postgis-3-scripts" \
        "postgresql-$PG_MAJOR-pgvector" \
    && rm -rf /var/lib/apt/lists/*

COPY initdb/ /docker-entrypoint-initdb.d/
