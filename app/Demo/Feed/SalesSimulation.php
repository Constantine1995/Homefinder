<?php

namespace App\Demo\Feed;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Sales of the demo developer day by day: flats go on sale, get reserved and sold, prices change.
 *
 * The state of a flat on a day is a pure function of the flat and the day: every flat draws its
 * events from its own seeded random streams, so any day can be replayed and days only add events.
 */
class SalesSimulation
{
    private const string SEED = 'homefinder-demo';

    /**
     * Days the flats already on sale have been selling before day 0, so that some are reserved on day 0.
     */
    private const int WARM_UP_DAYS = 14;

    /**
     * Chance that an average flat on sale gets reserved on a given day.
     */
    private const float RESERVATION_CHANCE = 1 / 110;

    private const int SHORTEST_RESERVATION_DAYS = 3;

    private const int LONGEST_RESERVATION_DAYS = 10;

    /**
     * Chance that a reservation ends with a sale rather than the flat going back on sale.
     */
    private const float SALE_CHANCE = 0.75;

    /**
     * Chance that the developer discounts a flat on a given day.
     */
    private const float DISCOUNT_CHANCE = 1 / 100;

    private const int SMALLEST_DISCOUNT_PERCENT = 2;

    private const int LARGEST_DISCOUNT_PERCENT = 6;

    /**
     * Prices of buildings under construction rise every this many days, as construction advances.
     */
    private const int PRICE_RISE_INTERVAL_DAYS = 30;

    private const float PRICE_RISE = 0.015;

    /**
     * Share of the flats of buildings on sale that the developer holds back and releases later.
     */
    private const float HELD_BACK_SHARE = 0.05;

    private const int LATEST_HELD_BACK_RELEASE_DAY = 40;

    public function __construct(private DemoWorld $world) {}

    /**
     * Get the flat's sales status and price on the given day.
     */
    public function stateOf(DemoFlat $flat, int $day): FlatState
    {
        $random = $this->randomizer($flat, 'release');
        $salesStartDay = $this->world->dayOf($flat->building->salesStart);
        $isOnSaleAlready = $salesStartDay <= 0;

        $isSoldEarlier = $isOnSaleAlready && $random->nextFloat() < $flat->building->soldShare;
        $releaseDay = $isOnSaleAlready && $random->nextFloat() < self::HELD_BACK_SHARE
            ? $random->getInt(1, self::LATEST_HELD_BACK_RELEASE_DAY)
            : $salesStartDay;
        $sellingSince = max($releaseDay, -self::WARM_UP_DAYS);

        $status = match (true) {
            $isSoldEarlier => SaleStatus::Sold,
            $day < $releaseDay => SaleStatus::Upcoming,
            default => $this->statusOn($flat, $sellingSince, $day),
        };

        return new FlatState($status, $this->priceOn($flat, $sellingSince, $day), $releaseDay);
    }

    /**
     * Count how the flats changed between two days, as an importer comparing the two feeds would see it.
     */
    public function changesBetween(int $fromDay, int $toDay): FeedChanges
    {
        $counts = ['released' => 0, 'returned' => 0, 'reserved' => 0, 'sold' => 0, 'cheaper' => 0, 'pricier' => 0, 'listed' => 0];

        foreach ($this->world->flats() as $flat) {
            $after = $this->stateOf($flat, $toDay);
            $change = $this->changeBetween($this->stateOf($flat, $fromDay), $after);

            if ($change !== null) {
                $counts[$change]++;
            }

            if ($after->isListed()) {
                $counts['listed']++;
            }
        }

        return new FeedChanges(...$counts);
    }

    /**
     * Name the way a flat changed between two days, if it did.
     */
    private function changeBetween(FlatState $before, FlatState $after): ?string
    {
        if ($before->status !== $after->status) {
            return match ($after->status) {
                SaleStatus::Available => $before->status === SaleStatus::Upcoming ? 'released' : 'returned',
                SaleStatus::Reserved => 'reserved',
                SaleStatus::Sold => 'sold',
                SaleStatus::Upcoming => null,
            };
        }

        if (! $after->isListed() || $after->price === $before->price) {
            return null;
        }

        return $after->price < $before->price ? 'cheaper' : 'pricier';
    }

    /**
     * Replay the flat's reservations from the day it started selling until the given day.
     */
    private function statusOn(DemoFlat $flat, int $sellingSince, int $day): SaleStatus
    {
        $random = $this->randomizer($flat, 'sales');
        $reservationChance = min(0.5, self::RESERVATION_CHANCE * $this->demandFor($flat));
        $availableSince = $sellingSince;

        while (true) {
            $reservedOn = $availableSince + $this->daysUntil($random, $reservationChance);
            $reservationEndsOn = $reservedOn + $random->getInt(self::SHORTEST_RESERVATION_DAYS, self::LONGEST_RESERVATION_DAYS);
            $endsWithSale = $random->nextFloat() < self::SALE_CHANCE;

            if ($day < $reservedOn) {
                return SaleStatus::Available;
            }

            if ($day < $reservationEndsOn) {
                return SaleStatus::Reserved;
            }

            if ($endsWithSale) {
                return SaleStatus::Sold;
            }

            $availableSince = $reservationEndsOn;
        }
    }

    /**
     * Replay the flat's discounts and its building's price rises until the given day.
     */
    private function priceOn(DemoFlat $flat, int $sellingSince, int $day): int
    {
        $random = $this->randomizer($flat, 'price');
        $price = $flat->basePrice();
        $nextDiscountOn = $sellingSince + $this->daysUntil($random, self::DISCOUNT_CHANCE);
        $nextRiseOn = $flat->building->state === 'unfinished' ? self::PRICE_RISE_INTERVAL_DAYS : PHP_INT_MAX;

        while (min($nextDiscountOn, $nextRiseOn) <= $day) {
            if ($nextRiseOn <= $nextDiscountOn) {
                $price = DemoFlat::roundPrice($price * (1 + self::PRICE_RISE));
                $nextRiseOn += self::PRICE_RISE_INTERVAL_DAYS;

                continue;
            }

            $percent = $random->getInt(self::SMALLEST_DISCOUNT_PERCENT, self::LARGEST_DISCOUNT_PERCENT);
            $price = DemoFlat::roundPrice($price * (1 - $percent / 100));
            $nextDiscountOn += $this->daysUntil($random, self::DISCOUNT_CHANCE);
        }

        return $price;
    }

    /**
     * Smaller flats, flats with a view and flats above the third floor sell faster.
     */
    private function demandFor(DemoFlat $flat): float
    {
        $layout = $flat->layout;
        $byLayout = $layout->isStudio() ? 1.2 : 1.4 - 0.2 * $layout->roomCount();
        $byFloor = $flat->floor <= 3 ? 0.8 : 1.0;
        $byView = $flat->position->landmark === null ? 1.0 : 1.2;

        return $flat->complex->demand * $byLayout * $byFloor * $byView;
    }

    /**
     * Draw the number of days until an event that happens on any given day with the given chance.
     */
    private function daysUntil(Randomizer $random, float $chance): int
    {
        return max(1, (int) ceil(log(1 - $random->nextFloat()) / log(1 - $chance)));
    }

    /**
     * Get the flat's own random stream, so the flat's events do not depend on other flats.
     */
    private function randomizer(DemoFlat $flat, string $stream): Randomizer
    {
        $seed = hash('sha256', self::SEED.'|'.$flat->id().'|'.$stream, binary: true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }
}
