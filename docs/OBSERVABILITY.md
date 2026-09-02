# Observabilidade

Request ID, logs estruturados e eventos já estão descritos em `docs/EVENTS.md`.
Este documento cobre métricas e tracing.

## Ligado por escolha, nunca por acidente

Os bindings padrão são `NullMetricsExporter` e `NullTracer`: descartam tudo e
custam uma chamada de método vazia. Observabilidade desligada não pode alterar o
comportamento da aplicação — nem o resultado, nem o custo perceptível.

Para ligar, substitua em `Config/container.php`:

```php
use NeoFramework\Core\Observability\{MetricsExporterInterface, TracerInterface};

return [
    MetricsExporterInterface::class => new PrometheusExporter(/* … */),
    TracerInterface::class => new OpenTelemetryTracer(/* … */),
];
```

## Métricas

```php
interface MetricsExporterInterface
{
    public function counter(string $name, int $value = 1, array $labels = []): void;
    public function gauge(string $name, float $value, array $labels = []): void;
    public function histogram(string $name, float $value, array $labels = []): void;
}
```

Três tipos, porque é o que Prometheus, StatsD e OpenTelemetry entendem em comum.
Um contrato que só um backend implementa não é contrato.

### Labels são cardinalidade

Cada combinação de valores de label vira uma **série temporal** no backend.

| Pode ser label | Nunca pode |
|---|---|
| método HTTP, status, nome da rota | path resolvido (`/orders/8123`) |
| nome do job, da fila, do driver | id de usuário, e-mail, token |
| classe da exceção | mensagem da exceção |

Um label com valor aberto é como se derruba um backend de métricas com a própria
instrumentação. O `HttpMetricsListener` usa o **padrão** da rota (`/orders/{id}`)
ou o nome dela, nunca o caminho que o cliente pediu, e há teste para isso.

### Ligando ao ciclo HTTP

`HttpMetricsListener` monta sobre os eventos que já existem — o kernel não sabe
que ele existe. Registre em `Config/events.php`:

```php
use NeoFramework\Core\Events\{ExceptionRaised, ResponseCreated};
use NeoFramework\Core\Observability\HttpMetricsListener;

return [
    ResponseCreated::class => [HttpMetricsListener::class],
    ExceptionRaised::class => [HttpMetricsListener::class],
];
```

Emite:

| Métrica | Tipo | Labels |
|---|---|---|
| `http.server.requests` | counter | `method`, `route`, `status` |
| `http.server.duration_ms` | histogram | `method`, `route`, `status` |
| `http.server.exceptions` | counter | `route`, `status`, `type` |

A rota é lida do `RequestScope`, não do request. `ResponseCreated` é despachado
com o request que o kernel recebeu, e o atributo que o `RoutingHandler` anexou
vive numa cópia — PSR-7 é imutável, então ele nunca sobe de volta.

## Tracing

```php
$span = $tracer->startSpan('pedido.confirmar', ['pedido.id' => $id]);

try {
    // …
} catch (Throwable $e) {
    $span->recordException($e);
    throw $e;
} finally {
    $span->end();
}
```

O contrato é mínimo de propósito: propagação de contexto, sampling e export são
do adapter. Embutir um modelo de trace específico aqui amarraria o Core a ele.

### OpenTelemetry

O adapter oficial é um pacote separado para o SDK não virar custo obrigatório:

```bash
composer require diogodg/neoframework-opentelemetry
```

`OpenTelemetryTracer` adapta spans e exceções; `OpenTelemetryMetricsExporter`
reutiliza um instrumento por nome e tipo. Configuração de provider, sampler,
propagação e exportador é responsabilidade da aplicação e do SDK OTel.

## Testes

`InMemoryMetricsExporter` guarda o que foi emitido e permite afirmar sobre nome,
tipo, valor e labels:

```php
$metrics = new InMemoryMetricsExporter();
// …
self::assertSame('orders.show', $metrics->samples(HttpMetricsListener::REQUESTS)[0]['labels']['route']);
```

## Cache e banco

`CacheAccessed` sai de `Cache::getItem()` — o único ponto onde acerto e erro são
distinguíveis, já que quem chama recebe o item e decide sozinho o que fazer com
`isHit()`. `CacheMetricsListener` registra **um** contador com o label `result`:
acerto e erro só significam algo um em relação ao outro, e contadores separados
convidam alguém a coletar só o de acerto e concluir que a taxa é 100%. A chave
nunca vira label.

Para banco, o Core não constrói a conexão — a NeoORM tem o singleton dela, e
nenhuma biblioteca deve instrumentar por baixo de outra sem ser pedida. As
métricas de query são **opt-in**, embrulhando a própria conexão:

```php
// Config/container.php
return [PDO::class => fn () => new InstrumentedPdo($dsn, $user, $senha, connection: 'principal')];
```

`InstrumentedPdo` mede `exec()`, `query()` e cada `execute()` de statement
preparada — instrumentada pelo próprio PDO via `ATTR_STATEMENT_CLASS`, porque
uma `PDOStatement` não pode ser envolvida depois de criada. Medir só o `prepare`
contaria uma consulta que nem tocou o banco e perderia as N execuções do laço.
Consulta que estoura também é medida: costuma ser a mais lenta de todas.

`QueryMetricsListener` usa a **operação** como label — `select`, `insert`,
`update`, `delete`, `other`. O SQL, mesmo preparado, tem cardinalidade alta
demais para série temporal, e com valores interpolados vazaria dado pessoal
para dentro da métrica.

## Debug toolbar

Fora de produção, cada resposta sai com `X-Debug-Token`, e o perfil daquela
requisição fica disponível em `/_debug/{token}`; `/_debug` lista os últimos.

```
GET /_debug/9f2c1a7b40e35d18
{"token":"…","route":"users.index","status":200,"durationMs":12.4,
 "controllerMs":8.1,"cache":{"hit":3,"miss":1},
 "queries":{"select":{"count":4,"durationMs":5.2}}}
```

`durationMs` menos `controllerMs` é o custo de middleware, guard e binding — é
o que separa "a action é lenta" de "chegar até ela é lento".

Três decisões que valem explicar:

**Nunca é montado em produção.** Não por configuração que alguém possa
esquecer: o `HttpKernel` só o adiciona quando `app.environment` não é `prod`. Um
profiler exposto é um mapa da aplicação para quem o alcançar.

**Guarda agregados, nunca conteúdo.** Sem corpo de requisição, sem headers, sem
SQL com valores ligados — as consultas são somadas por operação. Tudo que o
profiler armazena passa a ser legível por quem alcançar o endpoint, inclusive
numa máquina de desenvolvimento com um túnel aberto.

**O coletor é instanciado por requisição** e ligado ao dispatcher pelo kernel,
não por `Config/events.php`. Registrado por class-string ele viraria um serviço
compartilhado, e num worker somaria as consultas de todas as requisições,
atribuindo à última a contagem das anteriores.

O endpoint é servido pelo próprio middleware em vez de por um controller: a
toolbar precisa funcionar justamente quando o roteamento está errado. O token é
validado como hexadecimal antes de virar nome de arquivo — sem isso, um `../`
transformaria o profiler num leitor de arquivos.
