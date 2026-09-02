<?php

declare(strict_types=1);

namespace NeoFramework\BrSupport;

final class Cep
{
    public static function isValid(string|int $value): bool
    {
        return preg_match('/^\d{5}-?\d{3}$/', (string) $value) === 1;
    }

    public static function format(string|int $value): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) !== 8) throw new \InvalidArgumentException('CEP deve ter 8 dígitos.');

        return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
    }
}
