<?php

declare(strict_types=1);

/** Worker RoadRunner da aplicação-sonda. */
require __DIR__ . '/../../vendor/autoload.php';

use NeoFramework\Core\Application;
use NeoFramework\Core\Kernel;
use NeoFramework\Core\Support\ProjectRoot;
use NeoFramework\RoadRunner\RoadRunnerWorker;
use NeoFramework\RoadRunner\SpiralPsr7WorkerAdapter;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

ProjectRoot::set(__DIR__);
Kernel::loadEnv();

$psrFactory = new GuzzleHttp\Psr7\HttpFactory();
$worker = new PSR7Worker(Worker::create(), $psrFactory, $psrFactory, $psrFactory);

(new RoadRunnerWorker())->run(
    (new Application())->boot(),
    new SpiralPsr7WorkerAdapter($worker),
    (int) ($_SERVER['MAX_REQUESTS'] ?? 0),
);
