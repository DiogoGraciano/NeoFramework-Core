<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use InvalidArgumentException;

/**
 * `Cache-Control` como valor, não como string montada à mão.
 *
 * Cada diretiva tem consequência de segurança ou de custo, e escrevê-las
 * concatenadas é onde `private` vira `public` por um typo — publicando no CDN
 * uma resposta que pertencia a um usuário.
 */
final readonly class CacheControl
{
    private function __construct(
        public bool $public,
        public bool $private,
        public bool $noStore,
        public bool $noCache,
        public bool $mustRevalidate,
        public bool $immutable,
        public ?int $maxAge,
        public ?int $sharedMaxAge,
        public ?int $staleWhileRevalidate,
    ) {}

    /** Nada é armazenado em lugar nenhum. O default para resposta autenticada. */
    public static function noStore(): self
    {
        return new self(false, false, true, true, false, false, null, null, null);
    }

    /** Cacheável só pelo browser do usuário — nunca por CDN ou proxy compartilhado. */
    public static function private(int $maxAge = 0): self
    {
        self::assertAge($maxAge);

        return new self(false, true, false, $maxAge === 0, false, false, $maxAge, null, null);
    }

    /** Cacheável por proxies. Só para resposta que não depende de quem pediu. */
    public static function public(int $maxAge, ?int $sharedMaxAge = null, bool $immutable = false): self
    {
        self::assertAge($maxAge);
        if ($sharedMaxAge !== null) self::assertAge($sharedMaxAge);

        return new self(true, false, false, false, false, $immutable, $maxAge, $sharedMaxAge, null);
    }

    /** Sempre revalida antes de servir, mas pode guardar. */
    public static function mustRevalidate(): self
    {
        return new self(false, true, false, true, true, false, 0, null, null);
    }

    public function withStaleWhileRevalidate(int $seconds): self
    {
        self::assertAge($seconds);

        return new self($this->public, $this->private, $this->noStore, $this->noCache, $this->mustRevalidate, $this->immutable, $this->maxAge, $this->sharedMaxAge, $seconds);
    }

    public function toHeader(): string
    {
        $directives = [];

        if ($this->public) $directives[] = 'public';
        if ($this->private) $directives[] = 'private';
        if ($this->noStore) $directives[] = 'no-store';
        if ($this->noCache) $directives[] = 'no-cache';
        if ($this->mustRevalidate) $directives[] = 'must-revalidate';
        if ($this->maxAge !== null) $directives[] = 'max-age=' . $this->maxAge;
        if ($this->sharedMaxAge !== null) $directives[] = 's-maxage=' . $this->sharedMaxAge;
        if ($this->staleWhileRevalidate !== null) $directives[] = 'stale-while-revalidate=' . $this->staleWhileRevalidate;
        if ($this->immutable) $directives[] = 'immutable';

        return implode(', ', $directives);
    }

    private static function assertAge(int $seconds): void
    {
        if ($seconds < 0) throw new InvalidArgumentException('Cache ages cannot be negative.');
    }
}
