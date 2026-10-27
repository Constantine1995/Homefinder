<?php

namespace App\Services\Knowledge;

use App\Models\Complex;
use App\Models\KbDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Loads Markdown documents into the current tenant's knowledge base and cuts them into chunks.
 *
 * A document is known by its source: importing it again replaces its chunks only when its text or the chunking
 * changed. New chunks come without vectors; KnowledgeEmbedder computes them.
 */
class KnowledgeImporter
{
    public function __construct(private MarkdownChunker $chunker) {}

    /**
     * Import every Markdown file of a directory, or a single file.
     *
     * @return array{added: int, updated: int, unchanged: int}
     *
     * @throws KnowledgeException
     */
    public function import(string $path): array
    {
        $files = File::isDirectory($path)
            ? collect(File::files($path))->filter(fn ($file): bool => $file->getExtension() === 'md')->map->getPathname()->sort()->values()->all()
            : [$path];

        $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($files as $file) {
            $counts[$this->importFile($file)]++;
        }

        return $counts;
    }

    /**
     * @return 'added'|'updated'|'unchanged'
     *
     * @throws KnowledgeException
     */
    public function importFile(string $path): string
    {
        if (! File::isReadable($path)) {
            throw new KnowledgeException("The document {$path} cannot be read.");
        }

        $content = File::get($path);
        ['meta' => $meta, 'body' => $body] = $this->chunker->parse($content);
        $document = KbDocument::query()->firstOrNew(['source' => $meta['source'] ?? basename($path)]);
        $hash = hash('sha256', $content);

        if ($document->exists && $document->content_hash === $hash && $document->index_version === MarkdownChunker::VERSION) {
            return 'unchanged';
        }

        $outcome = $document->exists ? 'updated' : 'added';

        DB::transaction(function () use ($document, $meta, $body, $content, $hash): void {
            $document->fill([
                'complex_id' => $this->complexId($meta['complex'] ?? null),
                'title' => $meta['title'] ?? $this->firstHeading($body) ?? $document->source,
                'content' => $content,
                'content_hash' => $hash,
                'index_version' => MarkdownChunker::VERSION,
            ])->save();

            $document->chunks()->delete();

            foreach ($this->chunker->chunk($body) as $position => $chunk) {
                $document->chunks()->create([...$chunk, 'position' => $position]);
            }
        });

        return $outcome;
    }

    private function complexId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        return Complex::query()->where('name', $name)->value('id')
            ?? throw new KnowledgeException("The developer has no complex named «{$name}».");
    }

    private function firstHeading(string $body): ?string
    {
        return preg_match('/^#\s+(.+)$/m', $body, $matches) ? trim($matches[1]) : null;
    }
}
