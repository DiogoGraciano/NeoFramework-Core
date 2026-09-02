<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Auth\IdentityInterface;

/**
 * Uma identidade foi estabelecida por um guard.
 *
 * Carrega a identidade e o nome do guard — nunca a credencial. Um evento é
 * entregue a listeners arbitrários, e o token que autenticou não tem por que
 * chegar em nenhum deles.
 */
final readonly class UserAuthenticated
{
    public function __construct(public IdentityInterface $identity, public string $guard)
    {
    }
}
