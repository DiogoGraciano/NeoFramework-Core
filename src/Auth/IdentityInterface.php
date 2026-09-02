<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

/** A identidade autenticada não depende de um modelo ou ORM específico. */
interface IdentityInterface
{
    public function id(): string;
}
