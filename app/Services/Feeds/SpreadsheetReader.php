<?php

namespace App\Services\Feeds;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads an availability spreadsheet («шахматка») in XLSX or CSV, one flat per row, without loading it whole.
 *
 * The header row names the columns in Russian, in any order and with the usual synonyms; title rows may
 * come above it. Unlike a feed, a sheet may list reserved and sold flats too, each with its status. Only the
 * first sheet of a workbook is read. A value that is present but malformed rejects its row, as in feeds.
 *
 * Without ID columns a complex and a building are identified by their names, and a flat by its complex,
 * building and number. A sheet merges with a feed of the same developer only through the feed's IDs.
 */
class SpreadsheetReader implements FeedReader
{
    /**
     * Number of non-empty rows at the top of the sheet searched for the header row.
     */
    private const int HEADER_ROWS = 10;

    /**
     * Bytes of a CSV file its encoding and delimiter are told from.
     */
    private const int CSV_SAMPLE_BYTES = 1 << 20;

    /**
     * CSV delimiters to try, the one of Excel in the Russian locale first.
     *
     * @var list<string>
     */
    private const array CSV_DELIMITERS = [';', ',', "\t"];

    private const int MAX_STRING_LENGTH = 255;

    private const int MAX_URL_LENGTH = 2048;

    /**
     * Headers of the columns by field, lowercased, with «ё» as «е», punctuation as spaces and units dropped (see field()).
     *
     * @var array<string, list<string>>
     */
    private const array COLUMNS = [
        'flat_id' => ['id', 'ид', 'id квартиры', 'ид квартиры', 'id помещения', 'код квартиры', 'код помещения', 'артикул'],
        'complex_id' => ['id жк', 'ид жк', 'код жк', 'id комплекса'],
        'complex' => ['жк', 'жилой комплекс', 'комплекс', 'проект', 'название жк'],
        'building_id' => ['id корпуса', 'ид корпуса', 'код корпуса', 'id дома'],
        'building' => ['корпус', 'дом', 'строение', 'литер', 'номер корпуса', 'номер дома'],
        'number' => ['номер', 'номер квартиры', 'номер кв', 'квартира', 'кв', 'номер помещения', 'условный номер'],
        'floor' => ['этаж'],
        'floors' => ['этажность', 'этажей', 'этажей в доме', 'всего этажей', 'этажность дома', 'этажность корпуса'],
        'rooms' => ['комнат', 'комнаты', 'комн', 'комнатность', 'количество комнат', 'кол во комнат', 'число комнат'],
        'area' => ['площадь', 'общая площадь', 'площадь общая', 'площадь квартиры', 's', 's общ', 's общая'],
        'kitchen_area' => ['площадь кухни', 'кухня', 's кухни'],
        'price' => ['цена', 'стоимость', 'цена квартиры', 'стоимость квартиры', 'полная стоимость', 'полная цена'],
        'status' => ['статус', 'статус квартиры', 'состояние', 'наличие'],
        'finishing' => ['отделка', 'тип отделки', 'вид отделки'],
        'deadline' => ['срок сдачи', 'сдача', 'срок ввода', 'ввод в эксплуатацию', 'дата сдачи', 'срок сдачи дома', 'срок сдачи корпуса'],
        'address' => ['адрес', 'адрес корпуса', 'адрес дома', 'строительный адрес'],
        'latitude' => ['широта', 'lat', 'latitude'],
        'longitude' => ['долгота', 'lon', 'lng', 'longitude'],
        'plan_url' => ['ссылка на планировку', 'url планировки', 'планировка url', 'планировка ссылка'],
        'description' => ['описание'],
        'window_view' => ['вид из окон', 'вид из окна'],
        'balcony' => ['балкон', 'балкон лоджия', 'лоджия'],
        'bathroom_unit' => ['санузел', 'санузлы', 'с у', 'тип санузла'],
        'ceiling_height' => ['высота потолков', 'высота потолка', 'потолки'],
    ];

    /**
     * Columns every sheet needs, by the name a missing one is reported with; a sheet needs any one field of a list.
     *
     * @var array<string, list<string>>
     */
    private const array REQUIRED_COLUMNS = [
        'ЖК' => ['complex'],
        'Корпус' => ['building'],
        '№ квартиры или ID квартиры' => ['number', 'flat_id'],
        'Этаж' => ['floor'],
        'Комнат' => ['rooms'],
        'Площадь' => ['area'],
        'Цена или Цена за м²' => ['price', 'price_per_meter'],
    ];

    /**
     * Sales statuses by their lowercased names.
     *
     * @var array<string, FlatStatus>
     */
    private const array STATUSES = [
        'свободна' => FlatStatus::Available,
        'свободно' => FlatStatus::Available,
        'свободен' => FlatStatus::Available,
        'в продаже' => FlatStatus::Available,
        'продается' => FlatStatus::Available,
        'available' => FlatStatus::Available,
        'бронь' => FlatStatus::Reserved,
        'в брони' => FlatStatus::Reserved,
        'забронирована' => FlatStatus::Reserved,
        'забронировано' => FlatStatus::Reserved,
        'резерв' => FlatStatus::Reserved,
        'в резерве' => FlatStatus::Reserved,
        'зарезервирована' => FlatStatus::Reserved,
        'зарезервировано' => FlatStatus::Reserved,
        'оформление' => FlatStatus::Reserved,
        'reserved' => FlatStatus::Reserved,
        'продана' => FlatStatus::Sold,
        'продано' => FlatStatus::Sold,
        'продан' => FlatStatus::Sold,
        'реализована' => FlatStatus::Sold,
        'sold' => FlatStatus::Sold,
        'не в продаже' => FlatStatus::Withdrawn,
        'снята с продажи' => FlatStatus::Withdrawn,
        'снято с продажи' => FlatStatus::Withdrawn,
        'не продается' => FlatStatus::Withdrawn,
    ];

    /**
     * Finishing levels by their names in lowercase letters only, so that «White box» and «white-box» are one.
     *
     * @var array<string, Finishing>
     */
    private const array FINISHINGS = [
        'безотделки' => Finishing::Rough,
        'черновая' => Finishing::Rough,
        'черноваяотделка' => Finishing::Rough,
        'предчистовая' => Finishing::WhiteBox,
        'предчистоваяотделка' => Finishing::WhiteBox,
        'whitebox' => Finishing::WhiteBox,
        'вайтбокс' => Finishing::WhiteBox,
        'чистовая' => Finishing::Finished,
        'чистоваяотделка' => Finishing::Finished,
        'сотделкой' => Finishing::Finished,
        'подключ' => Finishing::Furnished,
        'смебелью' => Finishing::Furnished,
    ];

    /**
     * Columns kept as the flat's features for soft-wish search, named as the features of feeds.
     *
     * @var list<string>
     */
    private const array FEATURES = ['window_view', 'balcony', 'bathroom_unit'];

    private const array ROMAN_QUARTERS = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4];

    /**
     * Read the flats of the sheet, keyed by their row number as a spreadsheet program shows it.
     *
     * @return Generator<int, FeedOffer|InvalidOffer>
     *
     * @throws FeedException when the file is not an XLSX or CSV sheet, is broken or has no header row
     */
    public function read(string $path): Generator
    {
        $reader = $this->open($path);

        try {
            $rows = $this->rows($reader);
            $columns = $this->header($rows);
            $headers = array_map(fn (array $column): string => $column['header'], $columns);

            for ($rows->next(); $rows->valid(); $rows->next()) {
                $cells = $rows->current();
                $values = array_map(fn (array $column): mixed => $cells[$column['index']] ?? null, $columns);

                yield $rows->key() => $this->parse(new SpreadsheetRow($values, $headers));
            }
        } catch (OpenSpoutException $exception) {
            throw new FeedException("The spreadsheet could not be read: {$exception->getMessage()}", previous: $exception);
        } finally {
            $reader->close();
        }
    }

    /**
     * @throws FeedException
     */
    private function open(string $path): ReaderInterface
    {
        $reader = $this->isWorkbook($path) ? new XlsxReader($this->xlsxOptions()) : new CsvReader($this->csvOptions($path));

        try {
            $reader->open($path);
        } catch (OpenSpoutException $exception) {
            throw new FeedException("The spreadsheet could not be opened: {$exception->getMessage()}", previous: $exception);
        }

        return $reader;
    }

    /**
     * Tell an XLSX workbook from a CSV file by the first bytes, as the name of an uploaded file proves nothing.
     *
     * @throws FeedException when the file is empty or is a workbook of a format other than XLSX
     */
    private function isWorkbook(string $path): bool
    {
        $signature = (string) @file_get_contents($path, length: 100);

        if ($signature === '') {
            throw new FeedException('The spreadsheet file is empty or could not be opened.');
        }

        if (str_starts_with($signature, "\xD0\xCF\x11\xE0")) {
            throw new FeedException('Excel 97–2003 workbooks (.xls) are not supported. Save the sheet as .xlsx or .csv.');
        }

        if (! str_starts_with($signature, "PK\x03\x04")) {
            return false;
        }

        // An OpenDocument file starts with an uncompressed «mimetype» entry naming its format.
        if (str_contains($signature, 'opendocument')) {
            throw new FeedException('OpenDocument spreadsheets (.ods) are not supported. Save the sheet as .xlsx or .csv.');
        }

        return true;
    }

    /**
     * Empty rows are kept, so that the reader numbers rows as a spreadsheet program does; they are skipped later.
     */
    private function xlsxOptions(): XlsxOptions
    {
        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return $options;
    }

    /**
     * Excel in the Russian locale saves CSV in Windows-1251 with semicolons; other programs save UTF-8 with commas.
     */
    private function csvOptions(string $path): CsvOptions
    {
        $sample = (string) @file_get_contents($path, length: self::CSV_SAMPLE_BYTES);

        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->ENCODING = $this->csvEncoding($sample);
        $options->FIELD_DELIMITER = $this->csvDelimiter(mb_convert_encoding($sample, 'UTF-8', $options->ENCODING));

        return $options;
    }

    private function csvEncoding(string $sample): string
    {
        // The sample may end in the middle of a character, so it is checked up to its last full line.
        if (strlen($sample) === self::CSV_SAMPLE_BYTES) {
            $sample = substr($sample, 0, (int) strrpos($sample, "\n"));
        }

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1251';
    }

    /**
     * Pick the delimiter that splits the top lines into the most known columns.
     */
    private function csvDelimiter(string $sample): string
    {
        $lines = array_slice(array_filter(
            preg_split('/\R/u', Str::chopStart($sample, "\u{FEFF}")) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ), 0, self::HEADER_ROWS);

        $bestDelimiter = self::CSV_DELIMITERS[0];
        $bestCount = 0;

        foreach (self::CSV_DELIMITERS as $delimiter) {
            foreach ($lines as $line) {
                $count = count($this->columns(str_getcsv($line, $delimiter, '"', '')));

                if ($count > $bestCount) {
                    [$bestDelimiter, $bestCount] = [$delimiter, $count];
                }
            }
        }

        return $bestDelimiter;
    }

    /**
     * Read the non-empty rows of the first sheet as lists of cell values, keyed by their row number.
     *
     * @return Generator<int, list<bool|DateTimeInterface|float|int|string|null>>
     */
    private function rows(ReaderInterface $reader): Generator
    {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $number => $row) {
                $cells = array_map($this->cellValue(...), $row->getCells());

                if (array_filter($cells, fn (mixed $value): bool => $value !== null) !== []) {
                    yield $number => $cells;
                }
            }

            return;
        }
    }

    private function cellValue(Cell $cell): bool|DateTimeInterface|float|int|string|null
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            is_string($value) => Str::trim($value) === '' ? null : Str::trim($value),
            is_bool($value), is_int($value), is_float($value), $value instanceof DateTimeInterface => $value,
            default => null,
        };
    }

    /**
     * Find the header row among the top rows and leave the rows at it.
     *
     * @param  Generator<int, list<bool|DateTimeInterface|float|int|string|null>>  $rows
     * @return array<string, array{index: int, header: string}>
     *
     * @throws FeedException
     */
    private function header(Generator $rows): array
    {
        $closestColumns = [];
        $searchedRows = 0;

        foreach ($rows as $cells) {
            $columns = $this->columns($cells);

            if ($this->missingColumns($columns) === []) {
                return $columns;
            }

            if (count($columns) > count($closestColumns)) {
                $closestColumns = $columns;
            }

            if (++$searchedRows === self::HEADER_ROWS) {
                break;
            }
        }

        $missingColumns = implode('», «', $this->missingColumns($closestColumns));

        throw new FeedException('None of the top '.self::HEADER_ROWS." rows of the sheet is a header row with the required columns: the closest one lacks «{$missingColumns}».");
    }

    /**
     * Find the known columns among the cells; when a column repeats, the first one counts.
     *
     * @param  list<bool|DateTimeInterface|float|int|string|null>  $cells
     * @return array<string, array{index: int, header: string}>
     */
    private function columns(array $cells): array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $field = is_string($cell) ? $this->field($cell) : null;

            if ($field !== null && ! isset($columns[$field])) {
                $columns[$field] = ['index' => $index, 'header' => Str::squish($cell)];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, array{index: int, header: string}>  $columns
     * @return list<string>
     */
    private function missingColumns(array $columns): array
    {
        $missingColumns = [];

        foreach (self::REQUIRED_COLUMNS as $name => $fields) {
            if (array_intersect_key($columns, array_flip($fields)) === []) {
                $missingColumns[] = $name;
            }
        }

        return $missingColumns;
    }

    /**
     * Get the field of the column by its header, such as «price» for «Стоимость, руб.».
     */
    private function field(string $header): ?string
    {
        $header = str_replace(['ё', '²', '№'], ['е', '2', ' номер '], mb_strtolower($header));
        $header = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $header));
        $words = explode(' ', $header);

        if (in_array($words[0], ['цена', 'стоимость'], true)
            && (array_intersect($words, ['м2', 'метр', 'метра']) !== [] || str_contains(" {$header} ", ' кв м '))) {
            return 'price_per_meter';
        }

        $header = (string) preg_replace('/( (руб|рублей|р|rub|м2|кв м|м))+$/u', '', $header);

        foreach (self::COLUMNS as $field => $headers) {
            if (in_array($header, $headers, true)) {
                return $field;
            }
        }

        return null;
    }

    private function parse(SpreadsheetRow $row): FeedOffer|InvalidOffer
    {
        $flatId = null;

        try {
            $complexName = Str::squish($row->requiredShort('complex'));
            $buildingName = Str::squish($row->requiredShort('building'));
            $complexId = $row->short('complex_id') ?? $this->key($complexName);
            $buildingId = $row->short('building_id') ?? $this->key($buildingName);
            $flatId = $this->flatId($row, $complexId, $buildingId);

            return $this->offer($row, $complexId, $complexName, $buildingId, $buildingName, $flatId);
        } catch (InvalidOfferException $exception) {
            return new InvalidOffer($flatId ?? $row->text('flat_id'), $exception->getMessage());
        }
    }

    /**
     * @throws InvalidOfferException
     */
    private function offer(SpreadsheetRow $row, string $complexId, string $complexName, string $buildingId, string $buildingName, string $flatId): FeedOffer
    {
        $area = $row->number('area', 1, 10_000) ?? throw $row->missing('area');
        [$rooms, $isEuro, $isOpenPlan] = $this->rooms($row);

        return new FeedOffer(
            complex: [
                'external_id' => $complexId,
                'name' => $complexName,
            ],
            building: [
                'external_id' => $buildingId,
                'name' => $buildingName,
                'address' => $row->short('address'),
                'latitude' => $row->number('latitude', -90, 90),
                'longitude' => $row->number('longitude', -180, 180),
                'floors' => $row->integer('floors', 1, 200),
                'deadline' => $this->deadline($row),
            ],
            flat: [
                'external_id' => $flatId,
                'number' => $row->short('number'),
                'floor' => $row->integer('floor', -5, 200) ?? throw $row->missing('floor'),
                'rooms' => $rooms,
                'is_euro' => $isEuro,
                'area' => $area,
                'kitchen_area' => $row->number('kitchen_area', 1, 1_000),
                'finishing' => self::FINISHINGS[preg_replace('/\P{L}+/u', '', mb_strtolower((string) $row->text('finishing')))] ?? null,
                'price' => $this->price($row, $area),
                'status' => $this->status($row),
                'plan_url' => $this->planUrl($row),
                'description' => $row->text('description'),
                'features' => $this->features($row, $isOpenPlan),
            ],
        );
    }

    /**
     * Get the flat's ID from its column or, without one, make it of the complex, the building and the flat's number.
     *
     * @throws InvalidOfferException
     */
    private function flatId(SpreadsheetRow $row, string $complexId, string $buildingId): string
    {
        $flatId = $row->short('flat_id');

        if ($flatId !== null) {
            return $flatId;
        }

        if (! $row->has('number')) {
            throw $row->missing('flat_id');
        }

        $flatId = "{$complexId}/{$buildingId}/{$this->key($row->requiredShort('number'))}";

        if (mb_strlen($flatId) > self::MAX_STRING_LENGTH) {
            throw new InvalidOfferException("The flat ID made of «{$row->header('complex')}», «{$row->header('building')}» and «{$row->header('number')}» is longer than ".self::MAX_STRING_LENGTH.' characters.');
        }

        return $flatId;
    }

    /**
     * Count the rooms as the sheet does: a number, «студия», a euro layout such as «2Е» or «евро-3», which
     * counts the kitchen-living room, or a free layout.
     *
     * @return array{int, bool, bool} The number of rooms, 0 for studios and free layouts; whether the layout is a euro one; whether it is a free one.
     *
     * @throws InvalidOfferException
     */
    private function rooms(SpreadsheetRow $row): array
    {
        $value = $row->value('rooms');

        if (is_int($value) || is_float($value)) {
            return [$row->integer('rooms', 0, 20), false, false];
        }

        $text = $row->text('rooms') ?? throw $row->missing('rooms');
        $notation = (string) preg_replace('/[\s.\-]+/u', '', str_replace('ё', 'е', mb_strtolower($text)));

        if (in_array($notation, ['студия', 'ст', 'studio'], true)) {
            return [0, false, false];
        }

        if (str_starts_with($notation, 'своб') || $notation === 'свпл') {
            return [0, false, true];
        }

        if (preg_match('/^(\d{1,2})(к|кк|ккв|комн|комнатная)?$/u', $notation, $matches) && $matches[1] <= 20) {
            return [(int) $matches[1], false, false];
        }

        if ((preg_match('/^(\d{1,2})(е|e|евро)$/u', $notation, $matches) || preg_match('/^евро(\d{1,2})$/u', $notation, $matches))
            && $matches[1] >= 1 && $matches[1] <= 20) {
            return [(int) $matches[1], true, false];
        }

        throw new InvalidOfferException("«{$row->header('rooms')}» is not a number of rooms, «студия», «своб. планировка» or a euro layout such as «2Е»: «{$text}».");
    }

    /**
     * Get the price of the whole flat in rubles, from the price per square meter if the sheet gives only that.
     *
     * @throws InvalidOfferException
     */
    private function price(SpreadsheetRow $row, float $area): int
    {
        $price = $row->number('price', 1, 1_000_000_000_000);

        if ($price === null) {
            $pricePerMeter = $row->number('price_per_meter', 1, 100_000_000)
                ?? throw $row->missing($row->has('price') ? 'price' : 'price_per_meter');
            $price = $pricePerMeter * $area;
        }

        return (int) round($price);
    }

    /**
     * A sheet without a status column lists the flats on sale, as a feed does; with one, every flat needs a status.
     *
     * @throws InvalidOfferException
     */
    private function status(SpreadsheetRow $row): FlatStatus
    {
        if (! $row->has('status')) {
            return FlatStatus::Available;
        }

        $status = $row->text('status') ?? throw $row->missing('status');

        return self::STATUSES[rtrim($this->key($status), '.')]
            ?? throw new InvalidOfferException("«{$row->header('status')}» is not a known status: «{$status}». Use «свободна», «бронь», «продана» or «не в продаже».");
    }

    /**
     * Get the completion date: the last day of a quarter such as «IV кв. 2027» or of a year, or a date as it is.
     * A completed building, «сдан», has no date in the sheet.
     *
     * @throws InvalidOfferException
     */
    private function deadline(SpreadsheetRow $row): ?string
    {
        $value = $row->value('deadline');

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            return $this->quarterEnd($row->integer('deadline', 1900, 2100), 4);
        }

        $text = $row->text('deadline');

        if ($text === null) {
            return null;
        }

        $deadline = (string) preg_replace('/\s*(г|год|года)\.?$/u', '', $this->key($text));

        return match (true) {
            (bool) preg_match('/^(сдан|введен|построен)/u', $deadline) => null,
            (bool) preg_match('/^(\d{4})$/', $deadline, $matches) && $matches[1] >= 1900 && $matches[1] <= 2100 => $this->quarterEnd((int) $matches[1], 4),
            (bool) preg_match('/^(iv|i{1,3}|[1-4])\s*-?й?\s*(кв|квартал)\.?\s*(\d{4})$/u', $deadline, $matches) && $matches[3] >= 1900 && $matches[3] <= 2100 => $this->quarterEnd((int) $matches[3], self::ROMAN_QUARTERS[$matches[1]] ?? (int) $matches[1]),
            (bool) preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $deadline, $matches) && checkdate((int) $matches[2], (int) $matches[1], (int) $matches[3]) => sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]),
            (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $deadline, $matches) && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) => $deadline,
            default => throw new InvalidOfferException("«{$row->header('deadline')}» is not a quarter, a year or a date: «{$text}»."),
        };
    }

    private function quarterEnd(int $year, int $quarter): string
    {
        return CarbonImmutable::create($year, $quarter * 3)->endOfMonth()->toDateString();
    }

    /**
     * @throws InvalidOfferException
     */
    private function planUrl(SpreadsheetRow $row): ?string
    {
        $url = $row->text('plan_url');

        if ($url !== null && (! Str::startsWith(mb_strtolower($url), ['https://', 'http://']) || mb_strlen($url) > self::MAX_URL_LENGTH)) {
            throw new InvalidOfferException("«{$row->header('plan_url')}» is not an http(s) link of up to ".self::MAX_URL_LENGTH.' characters: «'.Str::limit($url, 100).'».');
        }

        return $url;
    }

    /**
     * @return array<string, string|float|bool>
     *
     * @throws InvalidOfferException
     */
    private function features(SpreadsheetRow $row, bool $isOpenPlan): array
    {
        $features = [];

        foreach (self::FEATURES as $feature) {
            $value = $row->short($feature);

            if ($value !== null) {
                $features[$feature] = $value;
            }
        }

        $ceilingHeight = $row->number('ceiling_height', 1, 10);

        if ($ceilingHeight !== null) {
            $features['ceiling_height'] = $ceilingHeight;
        }

        if ($isOpenPlan) {
            $features['open_plan'] = true;
        }

        return $features;
    }

    /**
     * Get the key a name is compared by: lowercase, with «ё» as «е» and single spaces.
     */
    private function key(string $name): string
    {
        return mb_strtolower(Str::squish(str_replace(['ё', 'Ё'], ['е', 'Е'], $name)));
    }
}
