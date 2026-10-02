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

        if ($whole > intdiv(PHP_INT_MAX, self::MICRO_SCALE)) {
            throw new RuntimeException('Currency amount exceeds supported integer range.');
        }

        $amount = ($whole * self::MICRO_SCALE) + (int) $fraction;
        if ($amount < 0) {
            throw new RuntimeException('Currency amount exceeds supported integer range.');
        }

        return $amount;
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

        return number_format(
            $amountMicrounits / self::MICRO_SCALE,
            $decimals,
            '.',
            ''
        );
    }
}
