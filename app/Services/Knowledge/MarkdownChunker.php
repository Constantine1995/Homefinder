<?php

namespace App\Services\Knowledge;

/**
 * Cuts a Markdown document into the chunks the agent searches and cites: one per section, a long section
 * into several by paragraphs. Each chunk knows the headings it is under, so «Паркинг» stays about the complex.
 */
class MarkdownChunker
{
    /**
     * Version of the chunking; documents made with an older one are cut again on the next import.
     */
    public const int VERSION = 1;

    /**
     * Longest chunk, in characters: a section of a page, small enough for the reranker and a quote.
     */
    public const int MAX_CHARS = 1000;

    /**
     * Split a document into its front matter (`key: value` lines between `---`) and its Markdown body.
     *
     * @return array{meta: array<string, string>, body: string}
     */
    public function parse(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);

        if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $markdown, $matches)) {
            return ['meta' => [], 'body' => trim($markdown)];
        }

        $meta = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.+)$/', trim($line), $field)) {
                $meta[$field[1]] = trim($field[2]);
            }
        }

        return ['meta' => $meta, 'body' => trim($matches[2])];
    }

    /**
     * Cut the body into chunks, in the order of the document.
     *
     * @return list<array{section: string, text: string}>
     */
    public function chunk(string $body): array
    {
        $chunks = [];
        $headings = [];
        $paragraphs = [];

        $flush = function () use (&$chunks, &$headings, &$paragraphs): void {
            foreach ($this->pack($paragraphs) as $text) {
                $chunks[] = ['section' => implode(' › ', $headings), 'text' => $text];
            }

            $paragraphs = [];
        };

        foreach (preg_split('/\n\s*\n/', $body) ?: [] as $block) {
            $block = trim($block);

            if (preg_match('/^(#{1,6})\s+(.+)$/', $block, $heading) && ! str_contains($block, "\n")) {
                $flush();
                $headings = array_slice($headings, 0, strlen($heading[1]) - 1);
                $headings[strlen($heading[1]) - 1] = trim($heading[2]);
                $headings = array_values($headings);

                continue;
            }

            if ($block !== '') {
                $paragraphs[] = preg_replace('/\s+/', ' ', $block);
            }
        }

        $flush();

        return $chunks;
    }

    /**
     * Join the paragraphs of a section into chunks no longer than MAX_CHARS; a longer paragraph is cut by sentences.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function pack(array $paragraphs): array
    {
        $pieces = [];

        foreach ($paragraphs as $paragraph) {
            array_push($pieces, ...(mb_strlen($paragraph) > self::MAX_CHARS
                ? preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph]
                : [$paragraph]));
        }

        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $joined = $current === '' ? $piece : "{$current}\n\n{$piece}";

            if ($current !== '' && mb_strlen($joined) > self::MAX_CHARS) {
                $chunks[] = $current;
                $current = $piece;
            } else {
                $current = $joined;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
