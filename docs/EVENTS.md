# Eventos

Dispatcher PSR-14 síncrono. Sem listener registrado, despachar custa uma
chamada de método — instrumentação desligada não altera comportamento.

## Registrando

`Config/events.php`, por class-string:

```php
use NeoFramework\Core\Events\{ExceptionRaised, JobFailed, ResponseCreated};

return [
    ResponseCreated::class => [
        App\Listeners\RecordLatency::class,
        [App\Listeners\WarnOnSlowRequest::class, 10],  // prioridade: maior roda antes
    ],
    JobFailed::class => [App\Listeners\AlertOnJobFailure::class],
];
```

Um listener inexistente falha **no registro**, não no meio de uma requisição.
`neof event:list` mostra o mapa.

## O catálogo

### HTTP

| Evento | Quando | Carrega |
|---|---|---|
| `RequestReceived` | início do ciclo | request, requestId |
| `RouteMatched` | rota resolvida | request, rota, variáveis |
| `ControllerInvoking` | antes da action, já com guard e bind resolvidos | request, rota |
| `ControllerInvoked` | a action retornou | request, rota, `durationMs` |
| `ResponseCreated` | fim do ciclo | request, response, `durationMs` |
| `CacheAccessed` | leitura de cache | `hit` |
| `QueryExecuted` | consulta terminou | `sql` preparado, `durationMs`, `connection` |
| `ExceptionRaised` | exceção capturada | request, exceção, status |

### Filas

| Evento | Quando | Carrega |
|---|---|---|
| `JobProcessing` | lock adquirido, antes de rodar | job, fila |
| `JobProcessed` | terminou sem lançar | job, fila, `durationMs` |
| `JobFailed` | lançou | job, fila, exceção, `willRetry`, `durationMs` |

`willRetry` separa a falha que ainda tem tentativa da que esgotou o limite. Sem
ela, um alerta ligado a `JobFailed` dispararia em toda falha transitória.

### Migrações e autenticação

| Evento | Quando | Carrega |
|---|---|---|
| `MigrationApplied` | por migração aplicada | tag, nº de statements, `dryRun`, `resumed` |
| `UserAuthenticated` | guard estabeleceu identidade | identidade, guard |
| `UserLoggedOut` | sessão ou token invalidado | identidade (ou null), guard |
| `LoginFailed` | credencial não conferiu | identificador tentado, IP |
| `LoginThrottled` | tentativa recusada antes de verificar | identificador, IP, `dimension`, `retryAfter` |

## Compilação

`Config/events.php` é lido e revalidado a cada requisição — `class_exists` e
`method_exists` por listener, sobre um arquivo que não muda entre deploys.
`neof event:cache` grava o mapa já validado e ordenado em `Cache/events.php`;
`neof event:clear` remove.

Um listener registrado como closure **reprova** a compilação em vez de ser
descartado: um listener ausente é indistinguível de um que não fez nada. Registre
por class-string.

`neof event:list` lê pelo mesmo caminho do runtime e informa a origem no rodapé.
Listar o arquivo-fonte enquanto a aplicação serve o mapa compilado mostraria
listeners que não rodam — exatamente quando o cache está velho.

`LoginFailed` e `LoginThrottled` carregam o identificador tentado — é o que torna o
evento útil para auditoria, porque sem ele não há como distinguir alguém que errou a
própria senha de um ataque dirigido a uma conta. Nenhum dos dois carrega a senha.
`dimension` é `identifier` ou `ip`, e diz se o que estourou foi o ataque a uma conta
ou uma varredura vinda de um host.

Os eventos de auth carregam a identidade e o nome do guard — **nunca a
credencial**. Um evento é entregue a listeners arbitrários, e o token que
autenticou não tem por que chegar em nenhum deles.

No logout, a identidade é lida **antes** de invalidar a sessão: depois disso não
há mais de onde tirá-la, e uma auditoria precisa saber quem saiu.

## Fora do ciclo HTTP

`Events::dispatch()` lê o dispatcher do escopo corrente. Isso vale para HTTP,
para a CLI e para os workers:

- **HTTP** — `HttpKernel` abre um escopo por requisição;
- **CLI** — `bin/neof` abre um escopo para o processo, então qualquer comando
  pode despachar;
- **Filas** — `JobProcessor` abre um escopo **por job**.

### O escopo por job

`queue:work` é um processo que roda indefinidamente. Antes de o `JobScope`
existir, cada job herdava o que o anterior tivesse deixado no escopo estático —
a mesma classe de vazamento que o `RequestScope` fechou para requisições. E sem
escopo nenhum, três coisas eram silenciosamente inertes no worker:

- `Events::dispatch()` não chegava a listener nenhum;
- `Logger` não tinha contexto (nem job id, nem fila);
- `AuthContext::set()` gravava no vazio.

O escopo do job também carrega `jobId`, `job` e `queue` no contexto de log, e um
job despachado de dentro de uma requisição restaura o escopo dela ao terminar.

## Métricas prontas

`HttpMetricsListener` e `QueueMetricsListener` traduzem esses eventos em
métricas sem que o kernel ou o `JobProcessor` saibam que existem. Ver
`docs/OBSERVABILITY.md`.
