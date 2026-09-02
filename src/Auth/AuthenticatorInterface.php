<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

interface AuthenticatorInterface
{
    public function login(IdentityInterface $identity): void;

    public function logout(): void;
}
