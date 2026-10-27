FROM python:3.12-slim-trixie

COPY --from=ghcr.io/astral-sh/uv:0.12.13 /uv /bin/

# The virtualenv lives outside /app, so the project mounted over /app in development does not hide it.
ENV UV_PROJECT_ENVIRONMENT=/opt/venv \
    UV_PYTHON_DOWNLOADS=never \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    PATH="/opt/venv/bin:$PATH"

WORKDIR /app

# Dependencies first: they change less often than the code, so this layer stays cached.
COPY pyproject.toml uv.lock .python-version ./
RUN uv sync --locked --no-dev --no-install-project

COPY . .
RUN uv sync --locked --no-dev

USER nobody

EXPOSE 8000

CMD ["uvicorn", "homefinder.api:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000"]
