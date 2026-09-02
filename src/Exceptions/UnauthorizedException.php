<?php
declare(strict_types=1);

namespace NeoFramework\Core\Exceptions;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct(401, $message, ['WWW-Authenticate' => 'Bearer']);
    }
}
