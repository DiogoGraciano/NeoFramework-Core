<?php
declare(strict_types=1);
namespace NeoFramework\Core\Events;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;
/** Exceção capturada pelo ErrorHandler, já com o status que será respondido. */
final readonly class ExceptionRaised
{
    public function __construct(public ServerRequestInterface $request, public Throwable $exception, public int $status) {}
}
