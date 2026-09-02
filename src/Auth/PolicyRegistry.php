<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

final class PolicyRegistry
{
    /** @var array<string, PolicyInterface|callable(IdentityInterface,mixed):bool> */
    private array $policies = [];

    public function register(string $ability, PolicyInterface|callable $policy): void
    {
        $this->policies[$ability] = $policy;
    }

    public function allows(string $ability, IdentityInterface $identity, mixed $subject = null): bool
    {
        $policy = $this->policies[$ability] ?? null;
        if ($policy === null) return false;

        return $policy instanceof PolicyInterface ? $policy->allows($identity, $subject) : $policy($identity, $subject);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        $abilities = array_keys($this->policies);
        sort($abilities, SORT_STRING);

        return $abilities;
    }
}
