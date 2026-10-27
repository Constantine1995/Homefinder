#!/usr/bin/env bash
# Creates the database used by the test suite (DB_DATABASE in phpunit.xml).
# The PostGIS and pgvector extensions are enabled by the application's migrations.
set -euo pipefail

psql --variable ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --command "CREATE DATABASE \"${POSTGRES_DB}_testing\" OWNER \"${POSTGRES_USER}\""
