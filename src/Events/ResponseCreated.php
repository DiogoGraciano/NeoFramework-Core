<?php
declare(strict_types=1);
namespace NeoFramework\Core\Events;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
/** Fim do ciclo, com a duração para métricas de latência. */
final readonly class ResponseCreated
{
    public function __construct(public ServerRequestInterface $request, public ResponseInterface $response, public float $durationMs) {}
    public function status(): int { return $this->response->getStatusCode(); }
}
