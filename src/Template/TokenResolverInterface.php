<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

interface TokenResolverInterface
{
    /** @param array<mixed> $token */
    public function resolveToken(array $token): string;
}
