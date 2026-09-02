<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

use InvalidArgumentException;

final class Id
{
    private function __construct()
    {
    }

    public static function randomHex(int $bytes = 16): string
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('The random identifier length must be positive.');
        }

        return bin2hex(random_bytes($bytes));
    }
}
