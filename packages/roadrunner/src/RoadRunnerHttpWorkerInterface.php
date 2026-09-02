<?php
declare(strict_types=1);

namespace NeoFramework\RoadRunner;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Limite do adapter, isolado do SDK para manter o loop testável. */
interface RoadRunnerHttpWorkerInterface
{
    public function waitRequest(): ?ServerRequestInterface;
    public function respond(ResponseInterface $response): void;
    public function report(\Throwable $error): void;
    public function stop(): void;
}
