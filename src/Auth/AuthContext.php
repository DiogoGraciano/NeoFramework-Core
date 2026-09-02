<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use NeoFramework\Core\Http\RequestScopeContext;

/** Estado de autenticação limitado à requisição corrente. */
final class AuthContext
{
    private const KEY = 'neoframework.auth';

    public function __construct(
        public readonly ?IdentityInterface $identity = null,
        public readonly ?string $guard = null,
        /** @var list<string> */ public readonly array $scopes = [],
    ) {}

    public static function current(): self
    {
        $scope = RequestScopeContext::current();
        $context = $scope?->get(self::KEY);

        return $context instanceof self ? $context : new self();
    }

    /** @param list<string> $scopes */
    public static function set(?IdentityInterface $identity, ?string $guard = null, array $scopes = []): self
    {
        $context = new self($identity, $guard, $scopes);
        RequestScopeContext::current()?->set(self::KEY, $context);

        return $context;
    }

    public function check(): bool
    {
        return $this->identity !== null;
    }
}
