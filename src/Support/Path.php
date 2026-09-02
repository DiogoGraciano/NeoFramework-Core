<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

use InvalidArgumentException;

final class Path
{
    private function __construct()
    {
    }

    /** Normaliza um caminho relativo e recusa qualquer escape acima da raiz. */
    public static function normalizeRelative(string $path): string
    {
        $segments = preg_split('~[\\\\/]++~', $path) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($normalized === []) {
                    throw new InvalidArgumentException('A relative path cannot escape its root.');
                }

                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return implode(DIRECTORY_SEPARATOR, $normalized);
    }
}
