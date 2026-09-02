<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

final class SystemClock implements ClockInterface
{
    public function now(): int { return time(); }
}
