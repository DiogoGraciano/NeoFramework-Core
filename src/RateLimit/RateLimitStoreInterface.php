<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

interface RateLimitStoreInterface
{
    /** Atomically increments a counter that expires at the given Unix timestamp. */
    public function increment(string $key, int $expiresAt): int;

    /** Reads a counter without creating it. A missing or expired counter is zero. */
    public function read(string $key): int;

    /** Drops a counter. Forgetting a key that was never written is not an error. */
    public function forget(string $key): void;
}
