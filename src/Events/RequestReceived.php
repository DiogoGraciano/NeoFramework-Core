<?php
declare(strict_types=1);
namespace NeoFramework\Core\Events;

use Psr\Http\Message\ServerRequestInterface;
/** Início do ciclo. Não carrega corpo nem headers de autorização. */
final readonly class RequestReceived
{
    public function __construct(public ServerRequestInterface $request, public string $requestId) {}
    public function method(): string { return $this->request->getMethod(); }
    public function path(): string { return $this->request->getUri()->getPath(); }
}
