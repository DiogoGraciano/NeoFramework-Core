<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\UserAuthenticated;
use NeoFramework\Core\Events\UserLoggedOut;
use Psr\Http\Message\ServerRequestInterface;

final readonly class BearerTokenGuard implements GuardInterface
{
    public function __construct(
        private TokenRepositoryInterface $tokens,
        private UserProviderInterface $users,
        private string $guardName = 'bearer',
    ) {}

    public function name(): string
    {
        return $this->guardName;
    }

    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
    {
        if (preg_match('/^Bearer\\s+(.+)$/i', trim($request->getHeaderLine('Authorization')), $matches) !== 1) return null;
        $token = $this->tokens->findByHash(hash('sha256', $matches[1]));
        if ($token === null || !$token->isValid(new \DateTimeImmutable())) return null;
        $identity = $this->users->findById($token->identityId);
        if ($identity !== null) {
            AuthContext::set($identity, $this->guardName, $token->scopes);
            // O evento leva a identidade e o guard, jamais o token: ele é
            // entregue a listeners arbitrários, e a credencial não tem por que
            // chegar em nenhum deles.
            Events::dispatch(new UserAuthenticated($identity, $this->guardName));
        }

        return $identity;
    }

    /** @return array{token:string, expiresAt:\DateTimeImmutable} */
    public function issue(IdentityInterface $identity, \DateTimeImmutable $expiresAt, array $scopes = []): array
    {
        $plainText = bin2hex(random_bytes(32));
        $this->tokens->save(new AccessToken(hash('sha256', $plainText), $identity->id(), $expiresAt, array_values($scopes)));

        return ['token' => $plainText, 'expiresAt' => $expiresAt];
    }

    public function revoke(string $plainText): void
    {
        $token = $this->tokens->findByHash(hash('sha256', $plainText));
        $this->tokens->revokeByHash(hash('sha256', $plainText), new \DateTimeImmutable());

        $identity = $token === null ? null : $this->users->findById($token->identityId);
        Events::dispatch(new UserLoggedOut($identity, $this->guardName));
    }

    /** @return array{token:string, expiresAt:\DateTimeImmutable}|null */
    public function refresh(string $plainText, \DateTimeImmutable $expiresAt): ?array
    {
        $token = $this->tokens->findByHash(hash('sha256', $plainText));
        if ($token === null || !$token->isValid(new \DateTimeImmutable())) return null;
        $identity = $this->users->findById($token->identityId);
        if ($identity === null) return null;
        $this->revoke($plainText);

        return $this->issue($identity, $expiresAt, $token->scopes);
    }
}
