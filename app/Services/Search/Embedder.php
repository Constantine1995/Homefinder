<?php

namespace App\Services\Search;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Stores the vectors the agent service computes for rows without one: the service only reads the database,
 * and Laravel, which owns the data, writes them.
 *
 * Works for the current tenant, or for all of them without one. Only one run of each kind goes at a time.
 *
 * @template TModel of Model
 */
abstract class Embedder
{
    /**
     * Rows sent to the service at a time: one batch is a few seconds of the embedding model.
     */
    public const int BATCH_SIZE = 50;

    /**
     * Longest time a run holds the lock.
     */
    private const int LOCK_SECONDS = 1800;

    public function __construct(protected AgentClient $agent) {}

    /**
     * @return Builder<TModel> The rows without a vector, with the columns embed() needs.
     */
    abstract protected function pending(): Builder;

    /**
     * Get the vectors of the tenant's rows from the agent service.
     *
     * @param  list<int>  $ids
     * @return array<int, list<float>> Vectors by row ID; rows with nothing to embed are left out.
     *
     * @throws AgentException
     */
    abstract protected function vectors(int $tenantId, array $ids): array;

    abstract protected function lockName(): string;

    /**
     * @return int|null How many rows got a vector, or null if another run is going.
     *
     * @throws AgentException when the service fails; the batches stored before that keep their vectors
     */
    public function embedPending(): ?int
    {
        $lock = Cache::lock($this->lockName(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $embeddedCount = 0;

            $this->pending()->chunkById(self::BATCH_SIZE, function (Collection $rows) use (&$embeddedCount): void {
                foreach ($rows->groupBy('tenant_id') as $tenantId => $tenantRows) {
                    $vectors = $this->vectors((int) $tenantId, $tenantRows->modelKeys());

                    foreach ($tenantRows->only(array_keys($vectors)) as $row) {
                        $embeddedCount += $this->store($row, $vectors[$row->getKey()]);
                    }
                }
            });

            return $embeddedCount;
        } finally {
            $lock->release();
        }
    }

    /**
     * Write the vector unless the row got one meanwhile.
     *
     * @param  TModel  $row
     * @param  list<float>  $vector
     */
    protected function store(Model $row, array $vector): int
    {
        return $row->newQuery()
            ->toBase()
            ->where($row->getKeyName(), $row->getKey())
            ->whereNull('embedding')
            ->update(['embedding' => json_encode($vector)]);
    }
}
