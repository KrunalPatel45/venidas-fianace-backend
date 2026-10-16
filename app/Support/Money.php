<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function toPaise(string|int $amount): int
    {
        $value = (string) $amount;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException(
                "Invalid monetary amount: {$value}"
            );
        }

        [$rupees, $paise] = array_pad(
            explode('.', $value, 2),
            2,
            '0'
        );

        $rupeeValue = (int) $rupees;

        if ($rupeeValue > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new InvalidArgumentException(
                'Monetary amount is too large.'
            );
        }

        return ($rupeeValue * 100)
            + (int) str_pad($paise, 2, '0');
    }

    public static function fromPaise(int $paise): string
    {
        if ($paise < 0) {
            throw new InvalidArgumentException(
                'Negative amount is not supported.'
            );
        }

        return intdiv($paise, 100)
            . '.'
            . str_pad(
                (string) ($paise % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    /**
     * Rate units:
     * 1% = 1000
     * 2% = 2000
     *
     * Formula:
     * principalPaise * rateUnits / 100000
     *
     * Because 1000 represents 1.000 percentage points.
     */
    public static function monthlyInterest(
        int $principalPaise,
        int $rateUnits
    ): int {
        if ($principalPaise < 0 || $rateUnits < 0) {
            throw new InvalidArgumentException(
                'Negative principal or rate is invalid.'
            );
        }

        if (
            $rateUnits > 0
            && $principalPaise >
                intdiv(PHP_INT_MAX - 50000, $rateUnits)
        ) {
            throw new InvalidArgumentException(
                'Interest calculation overflow.'
            );
        }

        return intdiv(
            ($principalPaise * $rateUnits) + 50000,
            100000
        );
    }
}
