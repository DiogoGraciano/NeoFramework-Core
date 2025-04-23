<?php

namespace NeoFramework\Core\Interfaces;

use NeoFramework\Core\JobDispatcher;

interface Job
{
    public function handle();

    public function dispatch():JobDispatcher;
}
