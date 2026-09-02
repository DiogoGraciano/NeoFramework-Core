<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

final readonly class AccessToken
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $hash,
        public string $identityId,
        public \DateTimeImmutable $expiresAt,
        public array $scopes = [],
        public ?\DateTimeImmutable $revokedAt = null,
    ) {}

    public function isValid(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}
