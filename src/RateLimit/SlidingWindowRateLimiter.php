<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

/**
 * Janela deslizante aproximada por dois contadores fixos.
 *
 * A janela fixa deixa passar até 2x o limite na virada: o cliente gasta a cota
 * no fim de uma janela e a cota inteira no início da seguinte. Aqui o contador
 * da janela anterior entra ponderado pela fração dela que ainda está dentro dos
 * últimos `window` segundos, o que remove essa rajada sem guardar um log de
 * timestamps por requisição.
 */
final readonly class SlidingWindowRateLimiter implements RateLimiterInterface
{
    public function __construct(private RateLimitStoreInterface $store, private ClockInterface $clock = new SystemClock()) {}

    public function attempt(string $key, int $limit, int $window): RateLimitResult
    {
        $now = $this->clock->now();
        $currentStart = intdiv($now, $window) * $window;
        $resetAt = $currentStart + $window;
        $elapsed = $now - $currentStart;

        $previous = $this->store->read($key . ':' . $currentStart);
        // A janela anterior precisa sobreviver a janela inteira seguinte para
        // ainda poder ser ponderada aqui.
        $current = $this->store->increment($key . ':' . $resetAt, $resetAt + $window);

        $weight = ($window - $elapsed) / $window;
        $estimated = (int) ceil($previous * $weight) + $current;

        return new RateLimitResult($estimated <= $limit, $limit, max(0, $limit - $estimated), $resetAt, max(1, $resetAt - $now));
    }
}
