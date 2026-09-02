<?php

declare(strict_types=1);

/**
 * Worker FrankenPHP da aplicação-sonda.
 *
 * O `boot()` acontece uma vez; cada requisição só passa por `handle()`. É esse
 * o ponto que os testes de runtime verificam: o que o bootstrap deixa vivo entre
 * requisições e o que o escopo tem que descartar.
 */
require __DIR__ . '/../../../vendor/autoload.php';

use NeoFramework\Core\Application;
use NeoFramework\Core\Kernel;
use NeoFramework\Core\Runtime\FrankenPhpWorker;
use NeoFramework\Core\Support\ProjectRoot;

ProjectRoot::set(dirname(__DIR__));
Kernel::loadEnv();

(new FrankenPhpWorker((new Application())->boot()))->run((int) ($_SERVER['MAX_REQUESTS'] ?? 0));
