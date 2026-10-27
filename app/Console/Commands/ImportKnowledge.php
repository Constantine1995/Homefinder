<?php

namespace App\Console\Commands;

use App\Jobs\EmbedKnowledge;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeException;
use App\Services\Knowledge\KnowledgeImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kb:import {path : A Markdown file or a directory of them} {--tenant= : ID of the developer}')]
#[Description("Load Markdown documents into a developer's knowledge base and queue computing their vectors")]
class ImportKnowledge extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KnowledgeImporter $importer): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));

        if ($tenant === null) {
            $this->components->error('Give the ID of an existing developer with --tenant.');

            return self::FAILURE;
        }

        try {
            $counts = $tenant->run(fn (): array => $importer->import($this->argument('path')));
        } catch (KnowledgeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $tenant->run(fn () => EmbedKnowledge::dispatch());

        $this->table(['Documents', 'Count'], [
            ['Added', $counts['added']],
            ['Updated', $counts['updated']],
            ['Unchanged', $counts['unchanged']],
        ]);

        return self::SUCCESS;
    }
}
