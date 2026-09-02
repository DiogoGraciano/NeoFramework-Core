<?php
declare(strict_types=1);

namespace NeoFramework\Core\Exceptions;

final class TooManyRequestsException extends HttpException
{
    /** @param array<string,string> $headers */
    public function __construct(array $headers = [])
    {
        parent::__construct(429, 'Too Many Requests', $headers);
    }
}
