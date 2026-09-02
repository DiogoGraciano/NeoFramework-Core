<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

interface ClockInterface
{
    /** Unix timestamp in seconds. */
    public function now(): int;
}
