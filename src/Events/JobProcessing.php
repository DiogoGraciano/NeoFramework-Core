<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Jobs\Entity\JobEntity;

/** Antes de o job rodar, já com o lock adquirido. */
final readonly class JobProcessing
{
    public function __construct(public JobEntity $job, public string $queue)
    {
    }

    /** @return class-string */
    public function jobClass(): string
    {
        return $this->job->getClass();
    }
}
