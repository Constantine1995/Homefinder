<?php

namespace App\Services\Search;

use App\Models\Flat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores the vectors the soft wishes of buyers are matched by («светлая», «вид на парк»), computed from
 * the flats' descriptions.
 *
 * A flat loses its vector when its description changes (see Flat), and gets a new one on the next run.
 *
 * @extends Embedder<Flat>
 */
class FlatEmbedder extends Embedder
{
    protected function pending(): Builder
    {
        return Flat::query()->whereNull('embedding')->select(['id', 'tenant_id', 'updated_at']);
    }

    protected function vectors(int $tenantId, array $ids): array
    {
        return $this->agent->embedFlats($tenantId, $ids);
    }

    protected function lockName(): string
    {
        return 'search-embed';
    }

    /**
     * Write the vector unless the flat changed while it was being computed: that vector describes the old
     * description, and the next run computes a new one.
     *
     * @param  Flat  $row
     */
    protected function store(Model $row, array $vector): int
    {
        return Flat::query()
            ->toBase()
            ->where('id', $row->id)
            ->whereNull('embedding')
            ->where('updated_at', $row->getRawOriginal('updated_at'))
            ->update(['embedding' => json_encode($vector)]);
    }
}
