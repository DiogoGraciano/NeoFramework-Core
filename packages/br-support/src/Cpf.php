<?php

declare(strict_types=1);

namespace NeoFramework\BrSupport;

final class Cpf
{
    public static function isValid(string|int $value): bool
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) return false;

        for ($position = 9; $position <= 10; ++$position) {
            $sum = 0;
            for ($index = 0; $index < $position; ++$index) $sum += (int) $digits[$index] * ($position + 1 - $index);
            if ((int) $digits[$position] !== (10 * $sum % 11) % 10) return false;
        }

        return true;
    }

    public static function format(string|int $value): string
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 11) throw new \InvalidArgumentException('CPF deve ter 11 dígitos.');

        return sprintf('%s.%s.%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3), substr($digits, 9, 2));
    }

    private static function digits(string|int $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }
}
