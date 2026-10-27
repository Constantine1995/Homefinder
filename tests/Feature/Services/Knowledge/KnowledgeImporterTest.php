<?php

use App\Models\Complex;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeException;
use App\Services\Knowledge\KnowledgeImporter;
use App\Services\Knowledge\MarkdownChunker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Write a Markdown document into a fresh directory and get its path.
 */
function knowledgeFile(string $content, string $name = 'parking.md'): string
{
    $directory = storage_path('framework/testing/knowledge-'.uniqid());
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/{$name}", $content);

    return "{$directory}/{$name}";
}

const PARKING_DOCUMENT = "---\ntitle: ЖК «Отрадный квартал»\nsource: https://demo.example/otradny\ncomplex: Отрадный квартал\n---\n\n"
    ."# ЖК «Отрадный квартал»\n\n## Паркинг\n\nПодземный паркинг на 310 машино-мест.\n\n## Школы\n\nШкола № 962 в пяти минутах.";

it('imports a document about a complex as chunks of its sections without vectors', function () {
    $tenant = Tenant::factory()->create();
    $complex = Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);

    $outcome = $tenant->run(fn () => app(KnowledgeImporter::class)->importFile(knowledgeFile(PARKING_DOCUMENT)));

    expect($outcome)->toBe('added');
    $document = KbDocument::withoutGlobalScopes()->sole();
    expect($document)
        ->tenant_id->toBe($tenant->id)
        ->complex_id->toBe($complex->id)
        ->title->toBe('ЖК «Отрадный квартал»')
        ->source->toBe('https://demo.example/otradny')
        ->index_version->toBe(MarkdownChunker::VERSION);
    expect(KbChunk::withoutGlobalScopes()->orderBy('position')->get(['tenant_id', 'position', 'section', 'text'])->toArray())->toBe([
        ['tenant_id' => $tenant->id, 'position' => 0, 'section' => 'ЖК «Отрадный квартал» › Паркинг', 'text' => 'Подземный паркинг на 310 машино-мест.'],
        ['tenant_id' => $tenant->id, 'position' => 1, 'section' => 'ЖК «Отрадный квартал» › Школы', 'text' => 'Школа № 962 в пяти минутах.'],
    ]);
    expect(KbChunk::withoutGlobalScopes()->whereNull('embedding')->count())->toBe(2);
});

it('leaves an unchanged document and its vectors alone, and cuts a changed one again', function () {
    $tenant = Tenant::factory()->create();
    Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    $path = knowledgeFile(PARKING_DOCUMENT);
    $import = fn (): string => $tenant->run(fn () => app(KnowledgeImporter::class)->importFile($path));
    $import();
    KbChunk::withoutGlobalScopes()->update(['embedding' => json_encode(array_fill(0, 1024, 0.5))]);

    $unchanged = $import();
    File::put($path, str_replace('310', '320', PARKING_DOCUMENT));
    $updated = $import();

    expect([$unchanged, $updated])->toBe(['unchanged', 'updated']);
    expect(KbDocument::withoutGlobalScopes()->count())->toBe(1);
    expect(KbChunk::withoutGlobalScopes()->orderBy('position')->pluck('text')->first())->toBe('Подземный паркинг на 320 машино-мест.');
    expect(KbChunk::withoutGlobalScopes()->whereNotNull('embedding')->count())->toBe(0);
});

it('cuts a document again when the chunking changed', function () {
    $tenant = Tenant::factory()->create();
    Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    $path = knowledgeFile(PARKING_DOCUMENT);
    $tenant->run(fn () => app(KnowledgeImporter::class)->importFile($path));
    KbDocument::withoutGlobalScopes()->update(['index_version' => MarkdownChunker::VERSION - 1]);

    expect($tenant->run(fn () => app(KnowledgeImporter::class)->importFile($path)))->toBe('updated');
});

it('imports the Markdown files of a directory, titled by their first heading and named by the file without front matter', function () {
    $tenant = Tenant::factory()->create();
    $directory = dirname(knowledgeFile("# Ипотека\n\nСтавка 6%.", 'mortgage.md'));
    File::put("{$directory}/notes.txt", 'не документ');

    $counts = $tenant->run(fn () => app(KnowledgeImporter::class)->import($directory));

    expect($counts)->toBe(['added' => 1, 'updated' => 0, 'unchanged' => 0]);
    expect(KbDocument::withoutGlobalScopes()->sole())
        ->title->toBe('Ипотека')
        ->source->toBe('mortgage.md')
        ->complex_id->toBeNull();
});

it('keeps the documents of one developer apart from another one with the same source', function () {
    [$tenant, $otherTenant] = Tenant::factory()->count(2)->create();
    Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    Complex::factory()->for($otherTenant)->create(['name' => 'Отрадный квартал']);
    $path = knowledgeFile(PARKING_DOCUMENT);

    $tenant->run(fn () => app(KnowledgeImporter::class)->importFile($path));
    $outcome = $otherTenant->run(fn () => app(KnowledgeImporter::class)->importFile($path));

    expect($outcome)->toBe('added');
    expect(KbDocument::withoutGlobalScopes()->pluck('tenant_id')->sort()->values()->all())->toBe([$tenant->id, $otherTenant->id]);
});

it('refuses a document about a complex the developer does not have', function () {
    $tenant = Tenant::factory()->create();
    Complex::factory()->create(['name' => 'Отрадный квартал']);

    expect(fn () => $tenant->run(fn () => app(KnowledgeImporter::class)->importFile(knowledgeFile(PARKING_DOCUMENT))))
        ->toThrow(KnowledgeException::class, 'The developer has no complex named «Отрадный квартал».');
    expect(KbDocument::withoutGlobalScopes()->count())->toBe(0);
});

it('finds chunks by any form of a word', function () {
    $tenant = Tenant::factory()->create();
    Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    $tenant->run(fn () => app(KnowledgeImporter::class)->importFile(knowledgeFile(PARKING_DOCUMENT)));

    expect(DB::table('kb_chunks')->whereRaw("search @@ plainto_tsquery('russian', ?)", ['паркинги'])->pluck('section')->all())
        ->toBe(['ЖК «Отрадный квартал» › Паркинг']);
});
