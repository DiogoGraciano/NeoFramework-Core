<?php

namespace Tests\JobsClass;

use Exception;
use NeoFramework\Core\Abstract\Job;

class FailingJob extends Job
{
    public function handle()
    {
        throw new Exception("Test Error");
    }
}
