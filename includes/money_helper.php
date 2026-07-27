<?php

final class MoneyValueException extends InvalidArgumentException
{
}

/**
 * Convert a non-negative DECIMAL(10,2) value to integer sen.
 */
function moneyDecimalToSen(mixed $value): int
{
    if (is_int($value)) {
        if ($value < 0) {
            throw new MoneyValueException(
                'Money value cannot be negative.'
            );
        }

        $value = (string) $value;
    } elseif (!is_string($value)) {
        throw new MoneyValueException(
            'Money value must be a decimal string.'
        );
    }

    $value = trim($value);

    if (
        !preg_match(
            '/\A(\d{1,8})(?:\.(\d{1,2}))?\z/',
            $value,
            $matches
        )
    ) {
        throw new MoneyValueException(
            'Money value has an invalid format.'
        );
    }

    $whole = ltrim($matches[1], '0');

    if ($whole === '') {
        $whole = '0';
    }

    $fraction = str_pad(
        $matches[2] ?? '',
        2,
        '0',
        STR_PAD_RIGHT
    );

    $sen = ((int) $whole * 100) +
        (int) $fraction;

    if ($sen > 9999999999) {
        throw new MoneyValueException(
            'Money value exceeds the supported range.'
        );
    }

    return $sen;
}

/**
 * Convert integer sen to a canonical DECIMAL(10,2) string.
 */
function moneySenToDecimal(int $sen): string
{
    if ($sen < 0 || $sen > 9999999999) {
        throw new MoneyValueException(
            'Money value exceeds the supported range.'
        );
    }

    return intdiv($sen, 100) .
        '.' .
        str_pad(
            (string) ($sen % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
}

/**
 * Normalize a DECIMAL(10,2) value without floating-point conversion.
 */
function moneyNormalizeDecimal(mixed $value): string
{
    return moneySenToDecimal(
        moneyDecimalToSen($value)
    );
}

/**
 * Format integer sen for display without floating-point conversion.
 */
function moneyFormatSen(int $sen): string
{
    if ($sen < 0 || $sen > 9999999999) {
        throw new MoneyValueException(
            'Money value exceeds the supported range.'
        );
    }

    return number_format(
        intdiv($sen, 100),
        0,
        '.',
        ','
    ) .
        '.' .
        str_pad(
            (string) ($sen % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
}

/**
 * Calculate a percentage discount using hundredths of one percent.
 */
function moneyPercentageDiscountSen(
    int $subtotalSen,
    mixed $percentage
): int {
    if ($subtotalSen < 0) {
        throw new MoneyValueException(
            'Subtotal cannot be negative.'
        );
    }

    $basisPoints = moneyDecimalToSen(
        $percentage
    );

    if ($basisPoints > 10000) {
        throw new MoneyValueException(
            'Percentage cannot exceed 100.00.'
        );
    }

    return intdiv(
        ($subtotalSen * $basisPoints) + 5000,
        10000
    );
}