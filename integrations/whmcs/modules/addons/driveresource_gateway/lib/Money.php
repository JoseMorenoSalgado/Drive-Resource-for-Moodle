<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;

/**
 * Integer-money helpers used by wallet invoice validation.
 */
final class Money
{
    public const MICRO_SCALE = 1000000;

    /**
     * Parse a non-negative decimal currency amount into integer micro-units.
     *
     * @param string $value Decimal amount.
     * @return int
     */
    public static function decimalToMicrounits(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(\\d+)(?:\\.(\\d{1,6}))?$/', $value, $matches)) {
            throw new RuntimeException('Invalid decimal currency amount.');
        }

        $whole = (int) $matches[1];
        $fraction = str_pad((string) ($matches[2] ?? ''), 6, '0');

        $maxWhole = intdiv(PHP_INT_MAX, self::MICRO_SCALE);
        $maxFraction = PHP_INT_MAX % self::MICRO_SCALE;
        $fractionMicrounits = (int) $fraction;
        if (
            $whole > $maxWhole
            || ($whole === $maxWhole && $fractionMicrounits > $maxFraction)
        ) {
            throw new RuntimeException('Currency amount exceeds supported integer range.');
        }

        return ($whole * self::MICRO_SCALE) + $fractionMicrounits;
    }

    /**
     * Format integer micro-units for a WHMCS invoice amount.
     *
     * @param int $amountMicrounits Amount in micro-units.
     * @param int $decimals Currency decimal precision.
     * @return string
     */
    public static function microunitsToDecimal(int $amountMicrounits, int $decimals = 2): string
    {
        if ($amountMicrounits < 0 || $decimals < 0 || $decimals > 6) {
            throw new RuntimeException('Invalid currency formatting request.');
        }

        $whole = intdiv($amountMicrounits, self::MICRO_SCALE);
        if ($decimals === 0) {
            return (string) $whole;
        }

        $fraction = $amountMicrounits % self::MICRO_SCALE;
        $fractionSix = str_pad((string) $fraction, 6, '0', STR_PAD_LEFT);

        return $whole . '.' . substr($fractionSix, 0, $decimals);
    }
}
