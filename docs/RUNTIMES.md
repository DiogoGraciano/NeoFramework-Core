# Runtimes persistentes

O `Application` oferece o mesmo ciclo para FPM e workers: inicialize uma vez
com `boot()` e entregue cada request a `handle()`. O `HttpKernel` descarta o
`RequestScope` em `finally`; depois da resposta, `Application` chama `reset()`
em todos os serviços que você registrar como `ResettableInterface`.

```php
$application = new Application(
    resettables: ['client' => $statefulClient], // implementa ResettableInterface
);
$application->boot();
```

Declare esses serviços em `Config/runtime.php` e execute `php neof doctor
--runtime` no deploy. O comando verifica permissões de log/cache, versão do PHP
e recusa uma classe stateful que não implemente `ResettableInterface`:

```php
return ['stateful_services' => [App\Client\StatefulApiClient::class]];
```

## FrankenPHP

Crie `public/index.php` na aplicação:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use NeoFramework\Core\Application;
use NeoFramework\Core\Kernel;
use NeoFramework\Core\Runtime\FrankenPhpWorker;
use NeoFramework\Core\Support\ProjectRoot;

ProjectRoot::set(dirname(__DIR__));
Kernel::loadEnv();

(new FrankenPhpWorker((new Application())->boot()))->run((int) ($_SERVER['MAX_REQUESTS'] ?? 0));
```

O `Caddyfile` aponta o worker para esse arquivo e serve a mesma raiz:

```caddyfile
{
	frankenphp {
		worker {
			file /app/public/index.php
		}
	}
}

:8080 {
	root /app/public
	php_server
}
```

`MAX_REQUESTS` permite reciclar processos caso uma integração legada retenha
memória. O adapter coleta ciclos ao fim de cada callback e captura exceções por
request, pois o handler global do PHP só seria chamado quando o script worker
terminasse. Ele também abre e fecha a sessão a cada callback: mantê-la aberta
reteria dados e o lock do usuário anterior.

## RoadRunner

O adapter vive em `packages/roadrunner`, publicado como
`diogodg/neoframework-roadrunner`, para que o SDK do RoadRunner não entre como
dependência do Core.

```php
$psrFactory = new GuzzleHttp\Psr7\HttpFactory();
$worker = new PSR7Worker(Worker::create(), $psrFactory, $psrFactory, $psrFactory);

(new RoadRunnerWorker())->run(
    (new Application())->boot(),
    new SpiralPsr7WorkerAdapter($worker),
    (int) ($_SERVER['MAX_REQUESTS'] ?? 0),
);
```

### Sessão

O RoadRunner não recompõe `$_COOKIE` nem coleta o `Set-Cookie` que
`session_start()` emite por `header()` — ele entrega um request PSR-7 e monta a
resposta a partir do objeto PSR-7. `SessionBridge` faz a ponte nos dois sentidos
e, o mais importante, **zera o id ao fim de cada request**: sem isso o worker
manteria o id do cliente anterior e a requisição seguinte, de outra pessoa,
abriria a sessão dele.

O id que chega do cliente só é aceito quando tem o formato que o PHP gera. Um id
arbitrário seria vetor de fixação de sessão.

## O que a suíte verifica

`tests/runtime/` é uma aplicação-sonda servida por PHP-FPM, FrankenPHP,
RoadRunner e Swoole no `docker-compose`. Cada caminho fica com **um filho ou
worker**: requisições consecutivas precisam cair no mesmo processo, senão um
vazamento entre elas passaria por sorte de balanceamento.

`NeoFrameworkPersistentRuntimeTest` (grupo `runtime`) mede, contra os quatro:

| Critério da §18 | Como é medido |
|---|---|
| request A não observa dados de B | 1000 requisições com valores distintos, cada resposta conferida |
| identidade não vaza | grava identidade no escopo, a requisição seguinte tem que ver `null` |
| sessões isoladas | dois clientes, contadores independentes; um sem cookie começa do zero |
| exceção não envenena o worker | 25 ciclos de 500 seguidos de requisição normal |
| memória estabiliza após warmup | 300 requisições de warmup, 700 de carga, crescimento < 10% |

A comparação de memória é entre dois pontos **depois** do warmup, não contra o
início: o crescimento das primeiras centenas é bootstrap preenchendo cache e
opcache, e reprovar por causa dele seria ruído.

```bash
docker compose up -d php postgres redis minio fpm nginx frankenphp roadrunner swoole
docker compose exec php vendor/bin/phpunit --group runtime
```

No CI é um job separado, porque exige os quatro servidores e PostgreSQL, Redis,
MinIO e MySQL reais de pé.

## Regras para a aplicação

Não guarde dados de request em propriedades estáticas, globais ou `$_ENV`.
Registre serviços stateful no `Application` e implemente `reset()` para soltar
transações, streams, buffers ou identidade em memória. O Core mantém cache,
configuração, mapa de rotas e logger como estado de bootstrap — não como estado
de request.

### Extensões e integrações incompatíveis

Os adapters distribuídos foram testados com PHP 8.4, `ext-json`, `ext-mbstring`,
`ext-intl` (com dados de locale) e `ext-sockets` para RoadRunner. O adapter de
imagem acrescenta `ext-gd` e `ext-exif`, mas é independente do worker. Cada
serviço que mantém estado precisa entrar em `Config/runtime.php` e implementar
`ResettableInterface`.

Não use em worker bibliotecas que assumem que `$_SESSION`, `$_COOKIE`, headers
globais, buffers de saída ou uma conexão/transação estática são descartados ao
fim do script. No RoadRunner, use `SessionBridge`; no FrankenPHP, deixe o
adapter abrir e fechar a sessão. Clientes HTTP com cookie jar, ORMs com unidade
de trabalho e SDKs que acumulam streams devem ser resetáveis ou criados por
request. `php neof doctor --runtime` encontra bindings stateful declarados que
não implementam esse contrato, mas não consegue adivinhar estado escondido em
uma biblioteca de terceiros.

## Swoole

O compose inclui um servidor Swoole e o CI mede a mesma sonda que roda em
PHP-FPM, FrankenPHP e RoadRunner. `RequestScopeContext` mantém o escopo por id
de corrotina quando a extensão está presente, em vez de usar um único slot
estático para todas as requisições.

O worker distribuído é deliberadamente **sequencial** (`worker_num=1` e
`enable_coroutine=false`). A razão é a sessão PHP e integrações legadas que usam
`$_SESSION`, `$_COOKIE` e headers globais: elas são estado do processo e não
podem ser intercaladas por corrotinas. Ele abre, grava e limpa a sessão em cada
callback, como os outros adapters.

Isto dá suporte seguro ao modo worker usado pelo framework e é o que a suíte
valida. Para habilitar corrotinas concorrentes em uma aplicação, além do escopo
por coroutine é obrigatório substituir qualquer integração baseada em
superglobais por equivalente isolado por coroutine; não basta trocar uma flag
do servidor. Sem essa auditoria, não habilite `enable_coroutine`.
