<?php

namespace App\Console\Commands;

use App\Enums\FeedImportStatus;
use App\Enums\FeedType;
use App\Models\FeedSource;
use App\Services\Feeds\FeedImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('feeds:import
    {source : ID of the feed source}
    {--file= : Spreadsheet or feed file to import instead of downloading the source URL}')]
#[Description('Import a feed source right away, from its URL or from a file, and print the import report')]
class ImportFeedSource extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FeedImporter $importer): int
    {
        $sourceId = filter_var($this->argument('source'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $source = $sourceId === false ? null : FeedSource::find($sourceId);

        if ($source === null) {
            $this->components->error("There is no feed source {$this->argument('source')}.");

            return self::INVALID;
        }

        $file = $this->option('file');

        if ($file !== null && ! is_file($file)) {
            $this->components->error("There is no file {$file}.");

            return self::INVALID;
        }

        $import = $file === null ? $importer->import($source) : $importer->importFile($source, $file, basename($file));

        if ($import === null) {
            $this->components->warn('Another import of this feed source is running.');

            return self::FAILURE;
        }

        if ($import->status === FeedImportStatus::Failed) {
            $this->components->error("The import failed: {$import->error_message}");

            return self::FAILURE;
        }

        $this->components->info("Feed source {$source->id} imported.");

        $this->table(['Flats', 'Count'], [
            ['Added', $import->added_count],
            ['Updated', $import->updated_count],
            ['Withdrawn', $import->removed_count],
            [$source->type === FeedType::Csv ? 'Rejected rows' : 'Rejected offers', $import->failed_count],
        ]);

        if ($import->row_errors !== []) {
            $this->table($source->type === FeedType::Csv ? ['Row', 'Flat ID', 'Reason'] : ['Offer', 'Internal ID', 'Reason'], array_map(
                fn (array $error): array => [$error['row'], $error['external_id'], $error['message']],
                $import->row_errors,
            ));
        }

        return self::SUCCESS;
    }
}
