<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

use Transliterator;

/** Utilitários puros de texto, sem acesso a configuração, request ou estado global. */
final class Str
{
    private function __construct() {}

    public static function decodeUnicodeUrl(string $value): string
    {
        $decoded = urldecode($value);

        return preg_replace_callback(
            '/%u([0-9a-f]{4})/i',
            static fn (array $match): string => mb_chr(hexdec($match[1]), 'UTF-8') ?: $match[0],
            $decoded,
        ) ?? $decoded;
    }

    public static function onlyNumbers(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    public static function isBase64(string $value): bool
    {
        return base64_decode($value, true) !== false;
    }

    public static function slug(string $value): string
    {
        $transliterator = class_exists(Transliterator::class)
            ? Transliterator::create('Any-Latin; Latin-ASCII; Lower()')
            : null;
        $value = $transliterator?->transliterate($value) ?? strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}
