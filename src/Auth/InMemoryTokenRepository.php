<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

/** Implementação útil para testes; produção deve registrar persistência própria. */
final class InMemoryTokenRepository implements TokenRepositoryInterface
{
    /** @var array<string, AccessToken> */
    private array $tokens = [];

    public function findByHash(string $hash): ?AccessToken
    {
        foreach ($this->tokens as $storedHash => $token) {
            if (hash_equals($storedHash, $hash)) {
                return $token;
            }
        }

        return null;
    }

    public function save(AccessToken $token): void
    {
        $this->tokens[$token->hash] = $token;
    }

    public function revokeByHash(string $hash, \DateTimeImmutable $at): void
    {
        $token = $this->findByHash($hash);
        if ($token === null) return;
        $this->tokens[$token->hash] = new AccessToken($token->hash, $token->identityId, $token->expiresAt, $token->scopes, $at);
    }
}
