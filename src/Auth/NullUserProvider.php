<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

/** Provider padrão deliberadamente vazio; a aplicação registra o seu próprio. */
final class NullUserProvider implements UserProviderInterface
{
    public function findById(string $id): ?IdentityInterface
    {
        return null;
    }
}
