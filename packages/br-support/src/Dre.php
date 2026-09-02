<?php

declare(strict_types=1);

namespace NeoFramework\BrSupport;

final class Dre
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^\d(?:\.\d){0,2}(?:\.\d{1,2})?$/', $value) === 1;
    }

    public static function format(string|int $value): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);
        if ($digits === '') throw new \InvalidArgumentException('Código DRE não pode ser vazio.');

        return implode('.', str_split($digits, 1));
    }
}
