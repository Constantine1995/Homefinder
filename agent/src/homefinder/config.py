from functools import lru_cache

from pydantic import PostgresDsn, SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Service settings, read from AGENT_* environment variables or agent/.env."""

    model_config = SettingsConfigDict(env_prefix="AGENT_", env_file=".env", extra="ignore")

    database_url: PostgresDsn = PostgresDsn("postgresql://homefinder:secret@127.0.0.1:5432/homefinder")
    """The catalog database; the service opens read-only sessions to it."""

    token: SecretStr
    """The shared secret Laravel sends as a bearer token."""

    pool_max_size: int = 10

    llm_model: str = "ollama_chat/qwen2.5:7b"
    """A LiteLLM model name: the provider, then the model."""

    llm_api_base: str | None = "http://localhost:11434"

    llm_timeout: float = 60

    llm_reasoning_effort: str | None = None
    """For models that think before answering, e.g. `none` turns off the thinking of qwen3 in Ollama; left out of
    the calls when not set, as not every provider takes it."""

    llm_keep_alive: str | None = None
    """How long Ollama keeps the model loaded after a call, e.g. `30m`; by default it unloads it after 5 idle
    minutes, and on a laptop loading it again took the first turn past Laravel's timeout. Left out when not set."""

    embedding_model: str = "ollama/bge-m3"
    """The model of the vectors in flats.embedding; changing it means computing them all again."""

    embedding_dimensions: int = 1024
    """The size of flats.embedding, set by Laravel's migration."""
    """Seconds; a 7B model on a laptop CPU takes several to load and answer."""

    reranker_url: str = "http://localhost:8090"
    """llama.cpp server with bge-reranker-v2-m3: its /v1/rerank scores how well each fragment answers a question."""

    reranker_timeout: float = 30

    timezone: str = "Europe/Moscow"
    """The developer's region: «сдача в этом году» and «готовое жильё» are counted from the date there."""

    @field_validator("token")
    @classmethod
    def _token_is_set(cls, token: SecretStr) -> SecretStr:
        if len(token.get_secret_value()) < 16:
            raise ValueError("set AGENT_TOKEN to a random string of 16 characters or more")
        return token


@lru_cache
def get_settings() -> Settings:
    return Settings()
