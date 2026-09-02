<?php
declare(strict_types=1);

namespace NeoFramework\RoadRunner;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7Worker;

/** Adapta o PSR7Worker do SDK sem vazar tipos do RoadRunner no Core. */
final readonly class SpiralPsr7WorkerAdapter implements RoadRunnerHttpWorkerInterface
{
    public function __construct(private PSR7Worker $worker) {}

    public function waitRequest(): ?ServerRequestInterface
    {
        return $this->worker->waitRequest();
    }

    public function respond(ResponseInterface $response): void
    {
        $this->worker->respond($response);
    }

    public function report(\Throwable $error): void
    {
        $this->worker->getWorker()->error($error->getMessage());
    }

    public function stop(): void
    {
        $this->worker->getWorker()->stop();
    }
}
