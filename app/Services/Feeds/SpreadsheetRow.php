<?php

namespace App\Services\Feeds;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * A row of an availability spreadsheet: the values of its cells by field, together with the headers
 * of the sheet's columns, so that a rejected row names the column the way the sheet does.
 *
 * Cells of XLSX keep their types, while every cell of CSV is text: numbers are read from both.
 */
readonly class SpreadsheetRow
{
    /**
     * Longest text a string column of the catalog holds.
     */
    private const int MAX_STRING_LENGTH = 255;

    /**
     * @param  array<string, bool|DateTimeInterface|float|int|string|null>  $values  Cell values by field; empty cells are null.
     * @param  array<string, string>  $headers  Headers of the sheet's columns by field.
     */
    public function __construct(
        private array $values,
        private array $headers,
    ) {}

    /**
     * Determine whether the sheet has a column for the field.
     */
    public function has(string $field): bool
    {
        return isset($this->headers[$field]);
    }

    public function header(string $field): string
    {
        return $this->headers[$field] ?? $field;
    }

    public function value(string $field): bool|DateTimeInterface|float|int|string|null
    {
        return $this->values[$field] ?? null;
    }

    /**
     * Get the cell as trimmed text, with whole numbers written without a fraction; an empty cell gives null.
     */
    public function text(string $field): ?string
    {
        $value = $this->value($field);

        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('d.m.Y'),
            is_bool($value) => $value ? 'да' : 'нет',
            is_float($value) && floor($value) === $value => number_format($value, 0, '.', ''),
            default => (string) $value,
        };

        $text = Str::trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Get the cell as text that fits a string column.
     *
     * @throws InvalidOfferException
     */
    public function short(string $field): ?string
    {
        $text = $this->text($field);

        if ($text !== null && mb_strlen($text) > self::MAX_STRING_LENGTH) {
            throw new InvalidOfferException("«{$this->header($field)}» is longer than ".self::MAX_STRING_LENGTH.' characters.');
        }

        return $text;
    }

    /**
     * @throws InvalidOfferException
     */
    public function requiredShort(string $field): string
    {
        return $this->short($field) ?? throw $this->missing($field);
    }

    /**
     * Get the number in the cell; text may have a decimal comma, spaces between digits and a unit such as «₽» or «м²».
     *
     * @throws InvalidOfferException when the cell holds something other than a number within the range
     */
    public function number(string $field, int $min, int $max): ?float
    {
        $value = $this->value($field);
        $text = $this->text($field);

        if ($text === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } else {
            $digits = str_replace([' ', "\u{A0}", "\u{202F}", ','], ['', '', '', '.'], $text);
            $digits = preg_replace('/(₽|руб\.?|р\.?|rub|м²|м2|кв\.?м\.?|м)$/iu', '', $digits);

            if (! is_numeric($digits)) {
                throw new InvalidOfferException("«{$this->header($field)}» is not a number: «{$text}».");
            }

            $number = (float) $digits;
        }

        if ($number < $min || $number > $max) {
            throw new InvalidOfferException("«{$this->header($field)}» must be from {$min} to {$max}, but is «{$text}».");
        }

        return $number;
    }

    /**
     * @throws InvalidOfferException
     */
    public function integer(string $field, int $min, int $max): ?int
    {
        $number = $this->number($field, $min, $max);

        if ($number !== null && $number !== floor($number)) {
            throw new InvalidOfferException("«{$this->header($field)}» is not a whole number: «{$this->text($field)}».");
        }

        return $number === null ? null : (int) $number;
    }

    public function missing(string $field): InvalidOfferException
    {
        return new InvalidOfferException("The row has no «{$this->header($field)}».");
    }
}
