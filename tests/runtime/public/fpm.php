<?php

declare(strict_types=1);

/** Front controller FPM da aplicação-sonda. */
require __DIR__ . '/../../../vendor/autoload.php';

use NeoFramework\Core\Kernel;
use NeoFramework\Core\Support\ProjectRoot;

ProjectRoot::set(dirname(__DIR__));
Kernel::init();
