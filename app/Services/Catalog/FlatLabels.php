<?php

namespace App\Services\Catalog;

use App\Models\Building;
use App\Models\Flat;
use Illuminate\Support\Number;

/**
 * A flat in the words every channel shows it to a buyer with: the layout as sales offices write it, the price,
 * the completion, and what the card's buttons «Записаться» and «Похожие» ask the agent for.
 */
class FlatLabels
{
    /**
     * How much dearer a similar flat may be, in percent.
     */
    public const int SIMILAR_PRICE_MARGIN = 10;

    /**
     * Write the layout as sales offices do: «Студия», «2-комн.», or «2Е» for a euro layout.
     */
    public static function layout(Flat $flat): string
    {
        return match (true) {
            (bool) ($flat->features['open_plan'] ?? false) => 'Своб. планировка',
            $flat->rooms === 0 => 'Студия',
            $flat->is_euro => "{$flat->rooms}Е",
            default => "{$flat->rooms}-комн.",
        };
    }

    public static function price(int $rubles): string
    {
        return number_format($rubles, thousands_separator: ' ').' ₽';
    }

    public static function area(Flat $flat): string
    {
        return Number::format((float) $flat->area, maxPrecision: 1, locale: 'ru').' м²';
    }

    /**
     * Get the floor with the building's height when the feed gives it: «Этаж 5 из 17».
     */
    public static function floor(Flat $flat, Building $building): string
    {
        return $building->floors === null ? "Этаж {$flat->floor}" : "Этаж {$flat->floor} из {$building->floors}";
    }

    /**
     * Get the building's completion: «сдача IV кв. 2027»; null when the feed does not give it.
     */
    public static function deadline(Building $building): ?string
    {
        if ($building->deadline === null) {
            return null;
        }

        $quarter = ['I', 'II', 'III', 'IV'][intdiv($building->deadline->month - 1, 3)];

        return "сдача {$quarter} кв. {$building->deadline->year}";
    }

    /**
     * Get the flat as the buyer would name it in a message: «кв. 12 в ЖК «Отрадный квартал»».
     */
    public static function name(Flat $flat): string
    {
        $number = $flat->number === null ? 'квартира' : "кв. {$flat->number}";

        return "{$number} в ЖК «{$flat->building->complex->name}»";
    }

    /**
     * Get the buyer's message the «Записаться» button of the flat's card sends to the agent.
     */
    public static function bookingMessage(Flat $flat): string
    {
        return 'Хочу записаться на просмотр: '.self::name($flat);
    }

    /**
     * Get the buyer's message the «Похожие» button of the flat's card sends to the agent.
     */
    public static function similarMessage(Flat $flat): string
    {
        return 'Покажите похожие на '.self::name($flat).': '.self::similarRequest($flat);
    }

    /**
     * Get what the buyer asks for with «Похожие»: the same layout for up to a tenth more, in the buyer's words the
     * agent understands — «евродвушка до 9,3 млн ₽»; the sales office's «2Е» is not one of them.
     */
    public static function similarRequest(Flat $flat): string
    {
        $layout = match (true) {
            (bool) ($flat->features['open_plan'] ?? false) => 'свободная планировка',
            $flat->rooms === 0 => 'студия',
            $flat->is_euro => [1 => 'евро-однушка', 2 => 'евродвушка', 3 => 'евротрёшка'][$flat->rooms] ?? "{$flat->rooms}-комнатная евро",
            default => [1 => 'однушка', 2 => 'двушка', 3 => 'трёшка'][$flat->rooms] ?? "{$flat->rooms}-комнатная",
        };
        // Rounded up to 100 000 ₽ in whole numbers: 6 000 000 + 10% is 6,6 million, not 6,7 as floats would have it.
        $tenthsOfMillion = intdiv($flat->price * (100 + self::SIMILAR_PRICE_MARGIN) + 9_999_999, 10_000_000);
        $millions = $tenthsOfMillion / 10;

        return "{$layout} до ".Number::format($millions, maxPrecision: 1, locale: 'ru').' млн ₽';
    }
}
