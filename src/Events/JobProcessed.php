<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Jobs\Entity\JobEntity;

/** O job terminou sem lançar. */
final readonly class JobProcessed
{
    public function __construct(public JobEntity $job, public string $queue, public float $durationMs)
    {
    }

    /** @return class-string */
    public function jobClass(): string
    {
        return $this->job->getClass();
    }
}
