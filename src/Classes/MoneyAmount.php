<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use Square\Types\Money;

class MoneyAmount
{
    public static function fromCents($amount): float
    {
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        return ((int)$amount) / 100;
    }

    public static function toMoney($amount, ?string $currency = null): Money
    {
        return new Money([
            'amount' => (int)round(((float)$amount) * 100),
            'currency' => $currency ?: self::currency(),
        ]);
    }

    public static function toSquareMoney($amount, ?string $currency = null): Money
    {
        return self::toMoney($amount, $currency);
    }

    public static function currency(): string
    {
        if (function_exists('currency')) {
            $code = currency()->getUserCurrency();
            if ($code) {
                return strtoupper($code);
            }
        }

        return 'USD';
    }
}
