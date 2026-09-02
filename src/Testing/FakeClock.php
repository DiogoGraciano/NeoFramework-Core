<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use NeoFramework\Core\RateLimit\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(private int $timestamp = 0) {}
    public function now(): int { return $this->timestamp; }
    public function set(int $timestamp): void { $this->timestamp = $timestamp; }
    public function advance(int $seconds): void { $this->timestamp += $seconds; }
}
