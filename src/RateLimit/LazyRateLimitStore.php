<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use Closure;

/**
 * Adia a construção do store até a primeira contagem.
 *
 * Conectar no Redis durante o build do container transformaria a queda do
 * backend em falha de bootstrap, antes de `ResilientRateLimiter` poder aplicar
 * fail-open ou fail-closed.
 */
final class LazyRateLimitStore implements RateLimitStoreInterface
{
    private ?RateLimitStoreInterface $store = null;

    /** @param Closure():RateLimitStoreInterface $factory */
    public function __construct(private readonly Closure $factory) {}

    public function increment(string $key, int $expiresAt): int
    {
        return $this->resolve()->increment($key, $expiresAt);
    }

    public function read(string $key): int
    {
        return $this->resolve()->read($key);
    }

    public function forget(string $key): void
    {
        $this->resolve()->forget($key);
    }

    private function resolve(): RateLimitStoreInterface
    {
        return $this->store ??= ($this->factory)();
    }
}
