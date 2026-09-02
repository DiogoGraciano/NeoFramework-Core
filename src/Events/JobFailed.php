<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Jobs\Entity\JobEntity;
use Throwable;

/**
 * O job lançou.
 *
 * `willRetry` distingue a falha que ainda tem tentativa da que esgotou o limite —
 * sem ela, um alerta ligado neste evento dispararia em toda falha transitória.
 */
final readonly class JobFailed
{
    public function __construct(
        public JobEntity $job,
        public string $queue,
        public Throwable $exception,
        public bool $willRetry,
        public float $durationMs,
    ) {
    }

    /** @return class-string */
    public function jobClass(): string
    {
        return $this->job->getClass();
    }
}
