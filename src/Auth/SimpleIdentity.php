<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

/** Identidade mínima para testes, CLIs e aplicações que não expõem um modelo. */
final readonly class SimpleIdentity implements IdentityInterface
{
    public function __construct(private string $identifier) {}

    public function id(): string
    {
        return $this->identifier;
    }
}
