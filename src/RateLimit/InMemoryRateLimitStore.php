<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

/** Store determinístico para testes e para o fallback sem container. */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string,array{count:int,expiresAt:int}> */
    private static array $counters = [];

    public function increment(string $key, int $expiresAt): int
    {
        $current = self::$counters[$key] ?? null;
        // O timestamp já faz parte da chave da janela. Não usar `time()` aqui
        // mantém o store determinístico quando o limiter recebe um relógio de teste.
        if ($current === null) $current = ['count' => 0, 'expiresAt' => $expiresAt];
        $current['count']++;
        self::$counters[$key] = $current;

        return $current['count'];
    }

    public function read(string $key): int
    {
        return self::$counters[$key]['count'] ?? 0;
    }

    public function forget(string $key): void
    {
        unset(self::$counters[$key]);
    }

    public static function reset(): void { self::$counters = []; }
}
