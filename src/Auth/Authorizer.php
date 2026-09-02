<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use NeoFramework\Core\Exceptions\ForbiddenException;
use NeoFramework\Core\Exceptions\UnauthorizedException;

final readonly class Authorizer
{
    public function __construct(private PolicyRegistry $policies) {}

    public function authorize(string $ability, ?IdentityInterface $identity, mixed $subject = null): void
    {
        if ($identity === null) throw new UnauthorizedException();
        if (!$this->policies->allows($ability, $identity, $subject)) throw new ForbiddenException();
    }
}
