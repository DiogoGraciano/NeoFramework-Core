<?php

declare(strict_types=1);

namespace NeoFramework\Core\Routing;

use InvalidArgumentException;
use NeoFramework\Core\RateLimit\ClockInterface;
use NeoFramework\Core\RateLimit\SystemClock;

/**
 * URLs assinadas por HMAC, com expiração opcional.
 *
 * Para link de confirmação de e-mail, download temporário e webhook de retorno:
 * qualquer lugar onde a autorização precisa viajar na própria URL porque não há
 * sessão do outro lado.
 */
final readonly class SignedUrlGenerator
{
    public const SIGNATURE = '_signature';
    public const EXPIRES = '_expires';

    public function __construct(
        private UrlGenerator $urls,
        private string $key,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($key === '') throw new InvalidArgumentException('A assinatura de URLs exige uma chave.');
    }

    /** @param array<string,scalar> $query */
    public function sign(string $name, array $parameters = [], array $query = [], ?int $expiresInSeconds = null): string
    {
        if ($expiresInSeconds !== null) {
            if ($expiresInSeconds < 1) throw new InvalidArgumentException('A validade de uma URL assinada deve ser positiva.');
            $query[self::EXPIRES] = $this->clock->now() + $expiresInSeconds;
        }

        $path = $this->urls->path($name, $parameters, $query);

        return $path . (str_contains($path, '?') ? '&' : '?') . self::SIGNATURE . '=' . $this->signature($path);
    }

    public function signAbsolute(string $name, array $parameters = [], array $query = [], ?int $expiresInSeconds = null): string
    {
        return $this->urls->absolute($this->sign($name, $parameters, $query, $expiresInSeconds));
    }

    /**
     * Aceita o `path?query` como o cliente o enviou.
     *
     * Devolve false para assinatura ausente, alterada ou vencida — a distinção
     * entre esses casos não é dada ao cliente, porque ela ajuda quem está
     * tentando forjar e não ajuda quem tem um link legítimo.
     */
    public function isValid(string $pathWithQuery): bool
    {
        [$path, $queryString] = array_pad(explode('?', $pathWithQuery, 2), 2, '');
        parse_str($queryString, $query);

        $provided = $query[self::SIGNATURE] ?? null;
        if (!is_string($provided) || $provided === '') return false;

        unset($query[self::SIGNATURE]);

        $expires = $query[self::EXPIRES] ?? null;
        if ($expires !== null && (!is_numeric($expires) || (int) $expires < $this->clock->now())) return false;

        $canonical = $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        // hash_equals e não `===`: a comparação precisa levar o mesmo tempo
        // independente de onde as strings divergem.
        return hash_equals($this->signature($canonical), $provided);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key);
    }
}
