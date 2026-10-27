<?php

use App\Services\Knowledge\MarkdownChunker;

it('reads the front matter apart from the body', function () {
    $parsed = (new MarkdownChunker)->parse("---\ntitle: ЖК «Отрадный квартал»\ncomplex: Отрадный квартал\n---\n\n# Отрадный\n\nТекст.");

    expect($parsed)->toBe([
        'meta' => ['title' => 'ЖК «Отрадный квартал»', 'complex' => 'Отрадный квартал'],
        'body' => "# Отрадный\n\nТекст.",
    ]);
});

it('takes a document without front matter as all body', function () {
    expect((new MarkdownChunker)->parse("# Паркинг\n\nТекст.\n"))->toBe(['meta' => [], 'body' => "# Паркинг\n\nТекст."]);
});

it('cuts a document into a chunk per section, each under the path of its headings', function () {
    $body = "# ЖК «Отрадный квартал»\n\nВступление.\n\n## Паркинг\n\nПодземный паркинг.\nНа 310 мест.\n\nКладовые есть.\n\n"
        ."### Цены\n\nОт 2,4 млн ₽.\n\n## Школы\n\nШкола № 962.";

    expect((new MarkdownChunker)->chunk($body))->toBe([
        ['section' => 'ЖК «Отрадный квартал»', 'text' => 'Вступление.'],
        ['section' => 'ЖК «Отрадный квартал» › Паркинг', 'text' => "Подземный паркинг. На 310 мест.\n\nКладовые есть."],
        ['section' => 'ЖК «Отрадный квартал» › Паркинг › Цены', 'text' => 'От 2,4 млн ₽.'],
        ['section' => 'ЖК «Отрадный квартал» › Школы', 'text' => 'Школа № 962.'],
    ]);
});

it('splits a long section by paragraphs and a long paragraph by sentences', function () {
    $paragraph = str_repeat('а', 600).'.';
    $sentences = implode(' ', array_fill(0, 3, str_repeat('б', 400).'.'));

    $chunks = (new MarkdownChunker)->chunk("# Раздел\n\n{$paragraph}\n\n{$paragraph}\n\n{$sentences}");

    expect(array_map(fn (array $chunk): int => mb_strlen($chunk['text']), $chunks))->toBe([601, 601, 804, 401]);
    expect(array_unique(array_column($chunks, 'section')))->toBe(['Раздел']);
});

it('leaves out sections without text', function () {
    expect((new MarkdownChunker)->chunk("# Документ\n\n## Пусто\n\n## Раздел\n\nТекст."))
        ->toBe([['section' => 'Документ › Раздел', 'text' => 'Текст.']]);
});
