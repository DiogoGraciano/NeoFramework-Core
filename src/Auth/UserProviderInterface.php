<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?IdentityInterface;
}
