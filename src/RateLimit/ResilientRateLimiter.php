<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Aplica a estratégia configurada quando o backend do rate limit cai.
 *
 * Sem isto, um Redis indisponível derruba toda rota limitada com 500 — o
 * limitador passa a ser o ponto único de falha que ele deveria proteger.
 */
final readonly class ResilientRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private RateLimiterInterface $limiter,
        private RateLimitFailureStrategy $strategy = RateLimitFailureStrategy::Open,
        private LoggerInterface $logger = new NullLogger(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function attempt(string $key, int $limit, int $window): RateLimitResult
    {
        try {
            return $this->limiter->attempt($key, $limit, $window);
        } catch (Throwable $e) {
            // A chave carrega IP ou id de usuário: fica fora da mensagem.
            $this->logger->error('Rate limit backend failure, applying the ' . $this->strategy->value . ' strategy.', ['exception' => $e->getMessage()]);

            $resetAt = $this->clock->now() + $window;

            return $this->strategy === RateLimitFailureStrategy::Open
                ? new RateLimitResult(true, $limit, $limit, $resetAt, $window)
                : new RateLimitResult(false, $limit, 0, $resetAt, $window);
        }
    }
}
