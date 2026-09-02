<?php
declare(strict_types=1);

namespace NeoFramework\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
