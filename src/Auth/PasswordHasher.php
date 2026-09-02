<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

/** Centraliza a política de hash e torna o rehash após login explícito. */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
