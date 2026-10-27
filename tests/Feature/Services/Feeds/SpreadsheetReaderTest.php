<?php

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Services\Feeds\FeedException;
use App\Services\Feeds\FeedOffer;
use App\Services\Feeds\InvalidOffer;
use App\Services\Feeds\SpreadsheetReader;
use Illuminate\Support\Str;

/**
 * Read the spreadsheet with the reader and collect its offers, keyed by their row number.
 *
 * @return array<int, FeedOffer|InvalidOffer>
 */
function readSpreadsheet(string $path): array
{
    return iterator_to_array(app(SpreadsheetReader::class)->read($path));
}

it('translates a row into the attributes of its complex, building and flat', function () {
    $path = spreadsheetFile([sheetRow([
        'ID квартиры' => 'OK-1-12',
        'ID ЖК' => '990101',
        'ID корпуса' => '9901011',
        'Адрес' => 'Москва, Сигнальный проезд, 19к1',
        'Широта' => 55.85421,
        'Долгота' => 37.59312,
        'Этажность' => 17,
        'Срок сдачи' => 'IV кв. 2026',
        'Площадь кухни' => 10.2,
        'Отделка' => 'Чистовая',
        'Ссылка на планировку' => 'https://developer.test/plans/1-38.svg',
        'Описание' => 'Однокомнатная квартира с окнами во двор.',
        'Вид из окон' => 'во двор',
        'Балкон' => 'лоджия',
        'Санузел' => 'совмещённый',
        'Высота потолков, м' => 2.8,
    ])]);

    $offers = readSpreadsheet($path);

    expect($offers)->toHaveKeys([2])->toHaveCount(1)
        ->and($offers[2]->complex)->toBe([
            'external_id' => '990101',
            'name' => 'Отрадный квартал',
        ])
        ->and($offers[2]->building)->toBe([
            'external_id' => '9901011',
            'name' => 'Корпус 1',
            'address' => 'Москва, Сигнальный проезд, 19к1',
            'latitude' => 55.85421,
            'longitude' => 37.59312,
            'floors' => 17,
            'deadline' => '2026-12-31',
        ])
        ->and($offers[2]->flat)->toBe([
            'external_id' => 'OK-1-12',
            'number' => '12',
            'floor' => 5,
            'rooms' => 1,
            'is_euro' => false,
            'area' => 38.5,
            'kitchen_area' => 10.2,
            'finishing' => Finishing::Finished,
            'price' => 12_500_000,
            'status' => FlatStatus::Available,
            'plan_url' => 'https://developer.test/plans/1-38.svg',
            'description' => 'Однокомнатная квартира с окнами во двор.',
            'features' => [
                'window_view' => 'во двор',
                'balcony' => 'лоджия',
                'bathroom_unit' => 'совмещённый',
                'ceiling_height' => 2.8,
            ],
        ]);
});

it('identifies the complex, building and flat by their names and number when the sheet has no IDs', function () {
    $path = spreadsheetFile([sheetRow(['ЖК' => ' Тёплые  холмы', 'Корпус' => 'Корпус 2', '№ кв.' => '15А'])]);

    $offer = readSpreadsheet($path)[2];

    expect($offer->complex)->toBe(['external_id' => 'теплые холмы', 'name' => 'Тёплые холмы'])
        ->and($offer->building['external_id'])->toBe('корпус 2')
        ->and($offer->flat['external_id'])->toBe('теплые холмы/корпус 2/15а');
});

it('finds the header below the title rows and keys the flats by the row numbers of the sheet', function () {
    $path = spreadsheetFile(
        [sheetRow(['№ кв.' => 12]), [], sheetRow(['№ кв.' => 13])],
        topRows: [['Прайс-лист ЖК «Отрадный квартал» на 20.10.2026'], []],
    );

    $offers = readSpreadsheet($path);

    expect(array_map(fn (FeedOffer $offer): ?string => $offer->flat['number'], $offers))->toBe([4 => '12', 6 => '13']);
});

it('reads the columns by their usual synonyms', function () {
    $path = spreadsheetFile([[
        'Жилой комплекс' => 'Отрадный квартал',
        'Дом' => '1',
        '№' => '12',
        'Этаж' => '5',
        'Кол-во комнат' => '1',
        'Общая площадь, кв.м' => '38,5',
        'Стоимость, руб.' => '12 500 000 ₽',
        'Состояние' => 'Свободна',
    ]]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat)
        ->number->toBe('12')
        ->floor->toBe(5)
        ->rooms->toBe(1)
        ->area->toBe(38.5)
        ->price->toBe(12_500_000)
        ->status->toBe(FlatStatus::Available);
});

it('multiplies the price per square meter by the area when the sheet has no price of the flat', function () {
    $path = spreadsheetFile([sheetRow(['Цена, ₽' => null, 'Цена за м², руб.' => 324_675, 'Площадь, м²' => 38.5])]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat['price'])->toBe(12_499_988);
});

it('reads the value that a formula of the workbook computed', function () {
    $path = spreadsheetFile([sheetRow(['Цена, ₽' => '=F2*324675'])]);
    $workbook = new ZipArchive;
    $workbook->open($path);
    $sheet = $workbook->getFromName('xl/worksheets/sheet1.xml');
    $workbook->addFromString('xl/worksheets/sheet1.xml', str_replace('</f></c>', '</f><v>12499987.5</v></c>', $sheet));
    $workbook->close();

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat['price'])->toBe(12_499_988);
});

it('reads a CSV that Excel saved in the Russian locale, in Windows-1251 with semicolons', function () {
    $path = csvFile(mb_convert_encoding(
        "ЖК;Корпус;№ кв.;Этаж;Комнат;Площадь, м²;Цена, ₽;Статус\r\n"
        ."Тёплые холмы;Корпус 1;12;5;2Е;45,2;9 800 000;Бронь\r\n",
        'Windows-1251',
        'UTF-8',
    ));

    $offer = readSpreadsheet($path)[2];

    expect($offer->complex['name'])->toBe('Тёплые холмы')
        ->and($offer->flat)
        ->rooms->toBe(2)
        ->is_euro->toBeTrue()
        ->area->toBe(45.2)
        ->price->toBe(9_800_000)
        ->status->toBe(FlatStatus::Reserved);
});

it('reads a CSV in UTF-8 with commas', function (string $byteOrderMark) {
    $path = csvFile($byteOrderMark
        ."ЖК,Корпус,№ кв.,Этаж,Комнат,\"Площадь, м²\",Цена\n"
        ."Тёплые холмы,Корпус 1,12,5,1,38.5,9800000\n");

    $offer = readSpreadsheet($path)[2];

    expect($offer->complex['name'])->toBe('Тёплые холмы')
        ->and($offer->flat['area'])->toBe(38.5)
        ->and($offer->flat['price'])->toBe(9_800_000);
})->with([
    'with a byte order mark' => "\u{FEFF}",
    'without a byte order mark' => '',
]);

it('counts the rooms as the sheet writes them', function (mixed $rooms, int $expectedRooms, bool $isEuro, array $features) {
    $path = spreadsheetFile([sheetRow(['Комнат' => $rooms])]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat)
        ->rooms->toBe($expectedRooms)
        ->is_euro->toBe($isEuro)
        ->features->toBe($features);
})->with([
    'number' => [2, 2, false, []],
    'number as text' => ['3', 3, false, []],
    'with «к»' => ['2-к', 2, false, []],
    'studio' => ['Студия', 0, false, []],
    'zero rooms' => [0, 0, false, []],
    'euro with «Е»' => ['2Е', 2, true, []],
    'euro with a latin «e»' => ['3e', 3, true, []],
    'euro written out' => ['евро-3', 3, true, []],
    'free layout' => ['Своб. планировка', 0, false, ['open_plan' => true]],
]);

it('reads the sales status', function (string $status, FlatStatus $expectedStatus) {
    $path = spreadsheetFile([sheetRow(['Статус' => $status])]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat['status'])->toBe($expectedStatus);
})->with([
    'free' => ['Свободна', FlatStatus::Available],
    'reserved' => ['Бронь', FlatStatus::Reserved],
    'held in reserve' => ['в резерве', FlatStatus::Reserved],
    'sold' => ['ПРОДАНА', FlatStatus::Sold],
    'sold, with a dot' => ['продано.', FlatStatus::Sold],
    'held back by the developer' => ['Не в продаже', FlatStatus::Withdrawn],
]);

it('reads every flat of a sheet without a status column as available', function () {
    $path = spreadsheetFile([sheetRow(['Статус' => null])]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat['status'])->toBe(FlatStatus::Available);
});

it('reads the completion date', function (mixed $deadline, ?string $expectedDeadline) {
    $path = spreadsheetFile([sheetRow(['Срок сдачи' => $deadline])]);

    $building = readSpreadsheet($path)[2]->building;

    expect($building['deadline'])->toBe($expectedDeadline);
})->with([
    'roman quarter' => ['II кв. 2027', '2027-06-30'],
    'quarter with «г.»' => ['2 кв 2028 г.', '2028-06-30'],
    'quarter written out' => ['3 квартал 2027 года', '2027-09-30'],
    'year as text' => ['2027', '2027-12-31'],
    'year as a number' => [2028, '2028-12-31'],
    'date as text' => ['30.06.2027', '2027-06-30'],
    'date cell' => [new DateTimeImmutable('2027-06-30'), '2027-06-30'],
    'completed' => ['Сдан', null],
    'empty' => ['', null],
]);

it('reads the finishing', function (string $finishing, ?Finishing $expectedFinishing) {
    $path = spreadsheetFile([sheetRow(['Отделка' => $finishing])]);

    $flat = readSpreadsheet($path)[2]->flat;

    expect($flat['finishing'])->toBe($expectedFinishing);
})->with([
    'rough' => ['Без отделки', Finishing::Rough],
    'white box' => ['White box', Finishing::WhiteBox],
    'finished' => ['Чистовая отделка', Finishing::Finished],
    'furnished' => ['Под ключ', Finishing::Furnished],
    'unknown' => ['Дизайнерская', null],
]);

it('rejects a row that lacks something a flat needs or has a malformed value', function (array $values, ?string $externalId, string $message) {
    $path = spreadsheetFile([sheetRow($values)]);

    $offers = readSpreadsheet($path);

    expect($offers[2])->toBeInstanceOf(InvalidOffer::class)
        ->externalId->toBe($externalId)
        ->reason->toBe($message);
})->with([
    'no complex' => [['ЖК' => ''], null, 'The row has no «ЖК».'],
    'no building' => [['Корпус' => ''], null, 'The row has no «Корпус».'],
    'no number' => [['№ кв.' => ''], null, 'The row has no «№ кв.».'],
    'neither ID nor number' => [['№ кв.' => null, 'ID' => ''], null, 'The row has no «ID».'],
    'no floor' => [['Этаж' => ''], 'отрадный квартал/корпус 1/12', 'The row has no «Этаж».'],
    'floor not a number' => [['Этаж' => 'пятый'], 'отрадный квартал/корпус 1/12', '«Этаж» is not a number: «пятый».'],
    'floor out of range' => [['Этаж' => 250], 'отрадный квартал/корпус 1/12', '«Этаж» must be from -5 to 200, but is «250».'],
    'fractional floor' => [['Этаж' => 5.5], 'отрадный квартал/корпус 1/12', '«Этаж» is not a whole number: «5.5».'],
    'no area' => [['Площадь, м²' => ''], 'отрадный квартал/корпус 1/12', 'The row has no «Площадь, м²».'],
    'unknown rooms' => [['Комнат' => 'много'], 'отрадный квартал/корпус 1/12', '«Комнат» is not a number of rooms, «студия», «своб. планировка» or a euro layout such as «2Е»: «много».'],
    'too many rooms' => [['Комнат' => '25'], 'отрадный квартал/корпус 1/12', '«Комнат» is not a number of rooms, «студия», «своб. планировка» or a euro layout such as «2Е»: «25».'],
    'no price' => [['Цена, ₽' => ''], 'отрадный квартал/корпус 1/12', 'The row has no «Цена, ₽».'],
    'price not a number' => [['Цена, ₽' => '12,5 млн'], 'отрадный квартал/корпус 1/12', '«Цена, ₽» is not a number: «12,5 млн».'],
    'no status' => [['Статус' => ''], 'отрадный квартал/корпус 1/12', 'The row has no «Статус».'],
    'unknown status' => [['Статус' => 'думает'], 'отрадный квартал/корпус 1/12', '«Статус» is not a known status: «думает». Use «свободна», «бронь», «продана» or «не в продаже».'],
    'unknown deadline' => [['Срок сдачи' => 'скоро'], 'отрадный квартал/корпус 1/12', '«Срок сдачи» is not a quarter, a year or a date: «скоро».'],
    'plan that is not a link' => [['Ссылка на планировку' => 'javascript:alert(1)'], 'отрадный квартал/корпус 1/12', '«Ссылка на планировку» is not an http(s) link of up to 2048 characters: «javascript:alert(1)».'],
    'building name too long' => [['Корпус' => str_repeat('к', 256)], null, '«Корпус» is longer than 255 characters.'],
    'flat ID too long' => [['ID' => str_repeat('1', 256)], str_repeat('1', 256), '«ID» is longer than 255 characters.'],
    'flat ID made too long' => [['ЖК' => str_repeat('ж', 200), 'Корпус' => str_repeat('к', 100)], null, 'The flat ID made of «ЖК», «Корпус» and «№ кв.» is longer than 255 characters.'],
]);

it('fails on a sheet without a header row among its top rows', function () {
    $path = spreadsheetFile([['ЖК' => 'Отрадный квартал', 'Корпус' => 'Корпус 1', 'Этаж' => 5, 'Площадь' => 38.5]]);

    expect(fn () => readSpreadsheet($path))->toThrow(
        FeedException::class,
        'None of the top 10 rows of the sheet is a header row with the required columns: the closest one lacks «№ квартиры или ID квартиры», «Комнат», «Цена или Цена за м²».',
    );
});

it('fails on a file that is not an XLSX or CSV sheet', function (string $contents, string $message) {
    $path = csvFile($contents);

    expect(fn () => readSpreadsheet($path))->toThrow(FeedException::class, $message);
})->with([
    'empty file' => ['', 'The spreadsheet file is empty or could not be opened.'],
    'Excel 97–2003 workbook' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 100), 'Excel 97–2003 workbooks (.xls) are not supported. Save the sheet as .xlsx or .csv.'],
    'OpenDocument spreadsheet' => ["PK\x03\x04".str_repeat("\0", 26).'mimetypeapplication/vnd.oasis.opendocument.spreadsheet', 'OpenDocument spreadsheets (.ods) are not supported. Save the sheet as .xlsx or .csv.'],
    'broken workbook' => ["PK\x03\x04".Str::random(200), 'The spreadsheet could not be opened:'],
]);
