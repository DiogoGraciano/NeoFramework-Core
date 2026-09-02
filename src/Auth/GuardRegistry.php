<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class GuardRegistry
{
    /** @var array<string, GuardInterface> */
    private array $guards = [];

    public function __construct(GuardInterface ...$guards)
    {
        foreach ($guards as $guard) $this->register($guard);
    }

    public function register(GuardInterface $guard): void
    {
        $this->guards[$guard->name()] = $guard;
    }

    public function authenticate(ServerRequestInterface $request, string $guard = 'session'): ?IdentityInterface
    {
        $context = AuthContext::current();
        if ($context->identity !== null && ($context->guard === null || $context->guard === $guard)) return $context->identity;
        $identity = $this->guard($guard)->authenticate($request);
        $scopes = AuthContext::current()->guard === $guard ? AuthContext::current()->scopes : [];
        AuthContext::set($identity, $guard, $scopes);

        return $identity;
    }

    public function guard(string $name): GuardInterface
    {
        return $this->guards[$name] ?? throw new \LogicException("Guard de autenticação não registrado: {$name}.");
    }
}
