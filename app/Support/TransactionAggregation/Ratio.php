<?php

namespace App\Support\TransactionAggregation;

final class Ratio
{
    /**
     * A percentage rounded to 2 decimals, or null when the divisor is 0 or unknown.
     */
    public static function percentage(int|float|null $numerator, int|float|null $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0) {
            return null;
        }

        return round($numerator / $denominator * 100, 2);
    }

    /**
     * The relative change from the comparison value in percent, or null when the comparison is 0 or unknown.
     */
    public static function change(int|float|null $current, int|float|null $comparison): ?float
    {
        if ($current === null || $comparison === null || $comparison == 0) {
            return null;
        }

        return round(($current - $comparison) / abs($comparison) * 100, 2);
    }

    /**
     * An average rounded to 2 decimals, or null when there is nothing to divide by.
     */
    public static function average(int|float|null $total, int|float|null $count): ?float
    {
        if ($total === null || $count === null || $count == 0) {
            return null;
        }

        return round($total / $count, 2);
    }
}
