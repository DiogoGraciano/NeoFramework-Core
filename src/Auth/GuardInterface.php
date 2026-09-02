<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use Psr\Http\Message\ServerRequestInterface;

interface GuardInterface
{
    public function name(): string;

    public function authenticate(ServerRequestInterface $request): ?IdentityInterface;
}
