<?php

namespace App\Services\Search;

use App\Models\KbChunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stores the vectors of the knowledge base chunks, the semantic half of the agent's search_knowledge.
 *
 * Chunks never change: a changed document gets new chunks without vectors (see KnowledgeImporter).
 *
 * @extends Embedder<KbChunk>
 */
class KnowledgeEmbedder extends Embedder
{
    protected function pending(): Builder
    {
        return KbChunk::query()->whereNull('embedding')->select(['id', 'tenant_id']);
    }

    protected function vectors(int $tenantId, array $ids): array
    {
        return $this->agent->embedKnowledge($tenantId, $ids);
    }

    protected function lockName(): string
    {
        return 'knowledge-embed';
    }
}
