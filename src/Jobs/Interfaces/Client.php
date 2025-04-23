<?php

namespace NeoFramework\Core\Jobs\Interfaces;

use NeoFramework\Core\Jobs\Entity\JobEntity;

Interface Client
{
    public function enqueue(JobEntity $job,string $queue = "default"):bool;
}
