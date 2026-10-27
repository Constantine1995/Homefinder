<?php

namespace App\Services\Agent;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Calls the search and agent service over the internal network with the shared service token.
 *
 * The tenant is always the one Laravel knows from the employee or the channel, never one a model named.
 */
class AgentClient
{
    public function __construct(
        #[Config('services.agent.url')] private string $url,
        #[Config('services.agent.token')] private ?string $token,
        #[Config('services.agent.timeout')] private int $timeoutSeconds,
    ) {}

    /**
     * Search the tenant's flats by a buyer's message: what the service understood, the flats found and,
     * when none were, what to ease.
     *
     * @return array{query: array<string, mixed>, result: array{total: int, flats: list<array<string, mixed>>}, relaxations: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function queryFlats(int $tenantId, string $text): array
    {
        /** @var array{query: array<string, mixed>, result: array{total: int, flats: list<array<string, mixed>>}, relaxations: list<array<string, mixed>>} */
        return $this->post('/v1/flats/query', ['tenant_id' => $tenantId, 'text' => $text]);
    }

    /**
     * Search the tenant's flats by filters the service gave before, such as an eased search, and the buyer's wishes.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, flats: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function searchFlats(int $tenantId, array $filters, ?string $wishes): array
    {
        /** @var array{total: int, flats: list<array<string, mixed>>} */
        return $this->post('/v1/flats/search', ['tenant_id' => $tenantId, 'filters' => $filters, 'wishes' => $wishes]);
    }

    /**
     * Get the vectors of the flats' descriptions; flats with nothing to describe are left out.
     *
     * @param  list<int>  $flatIds
     * @return array<int, list<float>> Vectors by flat ID.
     *
     * @throws AgentException
     */
    public function embedFlats(int $tenantId, array $flatIds): array
    {
        $response = $this->post('/v1/flats/embeddings', ['tenant_id' => $tenantId, 'flat_ids' => $flatIds]);

        return collect($response['flats'] ?? [])
            ->mapWithKeys(fn (array $flat): array => [(int) $flat['id'] => array_map(floatval(...), $flat['embedding'])])
            ->all();
    }

    /**
     * Get the vectors of knowledge base chunks; chunks the service cannot see are left out.
     *
     * @param  list<int>  $chunkIds
     * @return array<int, list<float>> Vectors by chunk ID.
     *
     * @throws AgentException
     */
    public function embedKnowledge(int $tenantId, array $chunkIds): array
    {
        $response = $this->post('/v1/knowledge/embeddings', ['tenant_id' => $tenantId, 'chunk_ids' => $chunkIds]);

        return collect($response['chunks'] ?? [])
            ->mapWithKeys(fn (array $chunk): array => [(int) $chunk['id'] => array_map(floatval(...), $chunk['embedding'])])
            ->all();
    }

    /**
     * Get the agent's answer to a buyer's message: the reply, the dialog state to keep for the next turn, the actions
     * to apply and the steps for the agent's log.
     *
     * @param  array<string, mixed>|null  $dialog  The state the previous turn returned; null for a new dialog.
     * @param  list<array{role: string, text: string}>  $history
     * @param  bool  $cards  Whether the channel shows the flats found as cards under the reply.
     * @return array{reply: string, dialog: array<string, mixed>, intent: string, actions: list<array{type: string, reason: string, filters?: array<string, mixed>|null}>, steps: list<array<string, mixed>>, verified: bool, problems: list<string>, attempts: int, prompt_tokens: int, completion_tokens: int}
     *
     * @throws AgentException
     */
    public function chat(int $tenantId, ?array $dialog, array $history, string $message, bool $cards = false): array
    {
        $payload = ['tenant_id' => $tenantId, 'history' => $history, 'message' => $message, 'cards' => $cards];

        if ($dialog !== null) {
            $payload['dialog'] = $dialog;
        }

        /** @var array{reply: string, dialog: array<string, mixed>, intent: string, actions: list<array{type: string, reason: string, filters?: array<string, mixed>|null}>, steps: list<array<string, mixed>>, verified: bool, problems: list<string>, attempts: int, prompt_tokens: int, completion_tokens: int} */
        return $this->post('/v1/chat', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = Http::baseUrl($this->url)
                ->withToken((string) $this->token)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            throw new AgentException("The agent service could not be reached: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new AgentException("The agent service responded to {$path} with HTTP {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }
}
