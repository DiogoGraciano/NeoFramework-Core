<?php

declare(strict_types=1);

namespace NeoFramework\BrSupport;

final class Cnpj
{
    public static function isValid(string|int $value): bool
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) return false;

        return self::checkDigit($digits, 12, 5) && self::checkDigit($digits, 13, 6);
    }

    public static function format(string|int $value): string
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 14) throw new \InvalidArgumentException('CNPJ deve ter 14 dígitos.');

        return sprintf('%s.%s.%s/%s-%s', substr($digits, 0, 2), substr($digits, 2, 3), substr($digits, 5, 3), substr($digits, 8, 4), substr($digits, 12, 2));
    }

    private static function checkDigit(string $digits, int $length, int $weight): bool
    {
        $sum = 0;
        for ($index = 0; $index < $length; ++$index) {
            $sum += (int) $digits[$index] * $weight;
            $weight = $weight === 2 ? 9 : $weight - 1;
        }

        $expected = $sum % 11 < 2 ? 0 : 11 - $sum % 11;

        return (int) $digits[$length] === $expected;
    }

    private static function digits(string|int $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }
}
