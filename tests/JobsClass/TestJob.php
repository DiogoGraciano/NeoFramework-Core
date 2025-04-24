<?php

namespace Tests\JobsClass;

use NeoFramework\Core\Abstract\Job;

class TestJob extends Job
{
    public function __construct(private $arg)
    {
    }

    public function handle()
    {
        return "Handled: $this->arg";
    }
}
