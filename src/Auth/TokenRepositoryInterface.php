<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

interface TokenRepositoryInterface
{
    public function findByHash(string $hash): ?AccessToken;

    public function save(AccessToken $token): void;

    public function revokeByHash(string $hash, \DateTimeImmutable $at): void;
}
