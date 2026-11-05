<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money arithmetic on integer minor units (paisa; 1 BDT = 100).
 *
 * Database columns stay DECIMAL(12,2) and the API keeps sending BDT as a
 * number, but every calculation in between (line totals, tax, discounts,
 * proration, payroll) is done on integers so rounding is deterministic and
 * never drifts the way float arithmetic does. Deliberately has no BCMath
 * dependency: shared hosting does not always ship it.
 *
 * Inputs are expected to carry at most 2 decimal places (they come from
 * DECIMAL(12,2) columns or from validated request fields), which makes the
 * single float multiplication in toMinor() exact after rounding.
 */
final class Money
{
    /** Rates are percentages with up to 2 decimals (e.g. 7.5), handled as basis points. */
    private const BP = 10000;

    public static function toMinor(float|int|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function toFloat(int $minor): float
    {
        return $minor / 100;
    }

    /** Fixed 2-dp string, safe to store in a DECIMAL column. */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** $rate percent of $minor, rounded half up. */
    public static function percent(int $minor, float|string $rate): int
    {
        $bp = self::rateToBasisPoints($rate);

        return self::divideRounded($minor * $bp, self::BP);
    }

    /**
     * Tax already contained in a tax-inclusive gross amount:
     * gross * rate / (100 + rate).
     */
    public static function inclusiveTax(int $grossMinor, float|string $rate): int
    {
        $bp = self::rateToBasisPoints($rate);

        return self::divideRounded($grossMinor * $bp, self::BP + $bp);
    }

    /**
     * Splits $totalMinor across $weights proportionally using the largest
     * remainder method, so the parts always add up to exactly $totalMinor.
     *
     * @param  array<int|string, int>  $weights  non-negative weights
     * @return array<int|string, int> same keys as $weights
     */
    public static function allocate(int $totalMinor, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $sum = array_sum($weights);
        if ($sum <= 0) {
            // No usable weights: spread evenly.
            $weights = array_map(fn () => 1, $weights);
            $sum = count($weights);
        }

        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $exact = $totalMinor * $weight;
            $parts[$key] = intdiv($exact, $sum);
            $remainders[$key] = $exact % $sum;
            $allocated += $parts[$key];
        }

        $left = $totalMinor - $allocated;
        arsort($remainders); // stable enough: ties keep insertion order in PHP 8
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $parts[$key]++;
            $left--;
        }

        return $parts;
    }

    private static function rateToBasisPoints(float|string $rate): int
    {
        $bp = (int) round(((float) $rate) * 100);
        if ($bp < 0) {
            throw new InvalidArgumentException('Rate cannot be negative.');
        }

        return $bp;
    }

    /** Integer division rounding half up (for non-negative numerators). */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            return -self::divideRounded(-$numerator, $denominator);
        }

        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
