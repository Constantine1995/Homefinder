<?php

namespace App\Services\Channels\Telegram;

use App\Models\Flat;
use App\Services\Catalog\FlatLabels;

/**
 * A flat as a Telegram message: the plan's picture with a caption of price, layout, floor and completion,
 * and the buttons «Записаться» and «Похожие».
 */
class FlatCard
{
    /**
     * Callback data of the buttons, followed by the flat's ID.
     */
    public const string BOOK = 'book:';

    public const string SIMILAR = 'similar:';

    /**
     * Get the card's caption in Telegram's HTML; the flat must have its building and complex loaded.
     */
    public static function caption(Flat $flat): string
    {
        $building = $flat->building;
        $place = collect([
            'ЖК «'.e($building->complex->name).'»',
            e($building->name),
            $flat->number === null ? null : 'кв. '.e($flat->number),
        ])->filter()->implode(', ');
        $details = collect([
            FlatLabels::floor($flat, $building),
            $flat->finishing?->label(),
            FlatLabels::deadline($building),
        ])->filter()->implode(' · ');

        return '<b>'.FlatLabels::layout($flat).' · '.FlatLabels::area($flat).' · '.FlatLabels::price($flat->price)."</b>\n{$place}\n{$details}";
    }

    /**
     * Get the card's buttons as Telegram's inline keyboard.
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function buttons(Flat $flat): array
    {
        return ['inline_keyboard' => [[
            ['text' => 'Записаться', 'callback_data' => self::BOOK.$flat->id],
            ['text' => 'Похожие', 'callback_data' => self::SIMILAR.$flat->id],
        ]]];
    }
}
