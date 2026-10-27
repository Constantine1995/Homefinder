<?php

namespace App\Services\Feeds;

use App\Enums\FeedImportStatus;
use App\Enums\FeedType;
use App\Jobs\EmbedFlats;
use App\Jobs\LocateComplexes;
use App\Jobs\NotifySavedSearches;
use App\Models\FeedImport;
use App\Models\FeedSource;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\File as StoredFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports a feed source into the catalog: downloads the feed or takes an uploaded file, applies it
 * in one transaction and keeps the report of every import, failed ones included.
 *
 * Only one import of a source runs at a time, whether the scheduler or a person started it.
 */
class FeedImporter
{
    /**
     * Longest time an import holds its source; the lock of a killed import runs out after it.
     */
    private const int LOCK_SECONDS = 900;

    private const int DOWNLOAD_TIMEOUT_SECONDS = 120;

    /**
     * Folder of the local disk the uploaded files are kept in, one subfolder per source.
     */
    private const string UPLOADS_FOLDER = 'feed-uploads';

    public function __construct(
        private YandexFeedReader $yandexReader,
        private SpreadsheetReader $spreadsheetReader,
        #[Config('feeds.kept_uploads')] private int $keptUploads,
    ) {}

    /**
     * Import the feed at the source's URL on behalf of the source's tenant.
     *
     * A broken or unreachable feed fails the import and leaves the catalog as it was.
     *
     * @return FeedImport|null The import with its report, or null if another import of the source is running.
     *
     * @throws Throwable when the import fails for a reason other than the feed; the import is recorded as failed first
     */
    public function import(FeedSource $source): ?FeedImport
    {
        return $this->exclusively($source, fn (): FeedImport => $this->importFeed($source));
    }

    /**
     * Import a file uploaded for the source, such as an availability spreadsheet, instead of downloading the source's URL.
     *
     * A copy of the file is kept with the import, so that the rows of its report can be looked up in it.
     *
     * @param  string  $fileName  Name the file was uploaded with.
     * @return FeedImport|null The import with its report, or null if another import of the source is running.
     *
     * @throws Throwable when the import fails for a reason other than the file; the import is recorded as failed first
     */
    public function importFile(FeedSource $source, string $path, string $fileName): ?FeedImport
    {
        return $this->exclusively($source, fn (): FeedImport => $this->importFeed($source, $path, Str::limit($fileName, 255, '')));
    }

    /**
     * Run the import on behalf of the source's tenant unless another import of the source is running.
     *
     * @param  Closure(): FeedImport  $import
     */
    private function exclusively(FeedSource $source, Closure $import): ?FeedImport
    {
        $lock = Cache::lock("feed-import:{$source->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $source->loadMissing('tenant')->tenant->run($import);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  string|null  $uploadPath  The uploaded file to import, or null to download the feed.
     */
    private function importFeed(FeedSource $source, ?string $uploadPath = null, ?string $fileName = null): FeedImport
    {
        $this->failInterruptedImports($source);

        $import = $source->imports()->create(['status' => FeedImportStatus::Running, 'file_name' => $fileName]);
        $downloadPath = tempnam(sys_get_temp_dir(), 'feed-');

        try {
            $reader = $this->readerFor($source);
            $path = $uploadPath === null ? $this->download($source, $downloadPath) : $this->keep($import, $uploadPath);

            $report = DB::transaction(fn (): array => (new CatalogSync($source, $import))->sync($reader->read($path)));

            $import->fill([...$report, 'status' => FeedImportStatus::Completed, 'finished_at' => now()])->save();
            $source->forceFill(['last_status' => $import->status, 'last_imported_at' => $import->finished_at])->save();

            LocateComplexes::dispatch();
            EmbedFlats::dispatch();
            NotifySavedSearches::dispatch($source->tenant);
        } catch (FeedException $exception) {
            $this->fail($source, $import, $exception);
        } catch (Throwable $exception) {
            $this->fail($source, $import, $exception);

            throw $exception;
        } finally {
            File::delete($downloadPath);

            if ($uploadPath !== null) {
                $this->deleteOldUploads($source);
            }
        }

        return $import;
    }

    /**
     * Imports left running by a killed worker are over: the running import holds the source's lock.
     */
    private function failInterruptedImports(FeedSource $source): void
    {
        $source->imports()->where('status', FeedImportStatus::Running)->update([
            'status' => FeedImportStatus::Failed,
            'error_message' => 'The import was interrupted before it finished.',
        ]);
    }

    /**
     * @throws FeedException
     */
    private function readerFor(FeedSource $source): FeedReader
    {
        return match ($source->type) {
            FeedType::Yandex => $this->yandexReader,
            FeedType::Csv => $this->spreadsheetReader,
            default => throw new FeedException("Importing {$source->type->value} feeds is not supported yet."),
        };
    }

    /**
     * Download the feed into the file rather than into memory: a feed may weigh hundreds of megabytes.
     *
     * @return string The path of the downloaded feed.
     *
     * @throws FeedException
     */
    private function download(FeedSource $source, string $path): string
    {
        if ($source->url === null) {
            throw new FeedException('The feed source has no URL.');
        }

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->sink($path)->get($source->url);
        } catch (ConnectionException $exception) {
            throw new FeedException("The feed could not be downloaded: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new FeedException("The feed server responded with HTTP {$response->status()}.");
        }

        return $path;
    }

    /**
     * Keep a copy of the uploaded file with the import; the import reads the copy, so the kept file is the one imported.
     *
     * @return string The path of the copy.
     */
    private function keep(FeedImport $import, string $path): string
    {
        $extension = Str::lower(pathinfo((string) $import->file_name, PATHINFO_EXTENSION));
        $storedName = preg_match('/^[a-z0-9]{1,8}$/', $extension) ? "{$import->id}.{$extension}" : (string) $import->id;

        $storedPath = Storage::disk('local')->putFileAs(self::UPLOADS_FOLDER."/{$import->feed_source_id}", new StoredFile($path), $storedName)
            ?: throw new RuntimeException("The uploaded file could not be stored for import {$import->id}.");

        $import->fill(['file_path' => $storedPath])->save();

        return Storage::disk('local')->path($storedPath);
    }

    /**
     * Delete the files of all but the latest uploads of the source; their import reports stay.
     */
    private function deleteOldUploads(FeedSource $source): void
    {
        $oldImports = $source->imports()
            ->whereNotNull('file_path')
            ->latest('id')
            ->offset($this->keptUploads)
            ->get(['id', 'file_path']);

        if ($oldImports->isNotEmpty()) {
            Storage::disk('local')->delete($oldImports->pluck('file_path')->all());
            FeedImport::whereKey($oldImports->modelKeys())->update(['file_path' => null]);
        }
    }

    /**
     * Record the failure; the time of the last import keeps pointing at the last completed one.
     */
    private function fail(FeedSource $source, FeedImport $import, Throwable $exception): void
    {
        $import->fill([
            'status' => FeedImportStatus::Failed,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();

        $source->forceFill(['last_status' => $import->status])->save();
    }
}
