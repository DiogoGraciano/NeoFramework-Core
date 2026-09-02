# NeoFramework RoadRunner

Adapter opcional do NeoFramework Core para o worker HTTP PSR-7 do RoadRunner.
Instale-o somente na aplicação que usa RoadRunner:

```bash
composer require diogodg/neoframework-roadrunner
```

Crie `rr-worker.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use NeoFramework\Core\Application;
use NeoFramework\Core\Kernel;
use NeoFramework\RoadRunner\RoadRunnerWorker;
use NeoFramework\RoadRunner\SpiralPsr7WorkerAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

Kernel::loadEnv();
$factory = new Psr17Factory();
$http = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
$application = (new Application())->boot();

(new RoadRunnerWorker())->run(
    $application,
    new SpiralPsr7WorkerAdapter($http),
    (int) ($_ENV['MAX_REQUESTS'] ?? 0),
);
```

Use uma configuração `.rr.yaml` com `server.command: "php rr-worker.php"` e
`http.address`. O limite `MAX_REQUESTS` faz o adapter pedir o recycle do filho
ao RoadRunner depois do lote, protegendo integrações legadas que ainda retêm
memória.

O pacote não adapta a sessão PHP baseada em superglobais. Para endpoints com
sessão, use um bridge PSR-7/PSR-15 de sessão persistente até esse adapter ser
fornecido; nunca compartilhe `$_SESSION` entre requests de RoadRunner.
