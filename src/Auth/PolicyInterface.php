<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

interface PolicyInterface
{
    public function allows(IdentityInterface $identity, mixed $subject = null): bool;
}
