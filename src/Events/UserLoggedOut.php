<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Auth\IdentityInterface;

/** A sessão ou o token foi invalidado. A identidade é nula se já não havia uma. */
final readonly class UserLoggedOut
{
    public function __construct(public ?IdentityInterface $identity, public string $guard)
    {
    }
}
