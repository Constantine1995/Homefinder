<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeImporter;
use Illuminate\Database\Seeder;

/**
 * The demo developer's knowledge base: made-up pages about its complexes, buying, mortgage and documents,
 * consistent with the demo feed. Their vectors come with the next `search:embed`.
 */
class DemoKnowledgeSeeder extends Seeder
{
    public const string PATH = 'resources/demo/knowledge';

    /**
     * Run the database seeds.
     */
    public function run(KnowledgeImporter $importer, ?Tenant $tenant = null): void
    {
        $tenant ??= Tenant::query()->orderBy('id')->firstOrFail();

        $tenant->run(fn (): array => $importer->import(base_path(self::PATH)));
    }
}
