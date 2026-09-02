# Kit de testes

## Testes HTTP

`TestClient` sobe o kernel de verdade e devolve `TestResponse`. Não há servidor
nem rede: o ciclo PSR-15 roda em processo, com o mesmo `RequestScope` que a
produção usa.

```php
TestClient::forControllers([UserController::class])
    ->actingAs(new SimpleIdentity('42'))
    ->getJson('/users/42')
    ->assertOk()
    ->assertJsonPath('id', '42');
```

`actingAs()` semeia só o escopo do cliente, sem tocar em sessão ou estado
global — dois clientes no mesmo teste têm identidades independentes. Há cookie
jar entre requisições, `withCsrfToken()`, `postMultipart()` e
`TestClient::uploadedFile()`.

## Fakes

Um teste não deveria precisar de SMTP, Redis ou disco para afirmar uma decisão
do código. Os fakes trocam a dependência e trazem asserções próprias.

| Fake | Substitui | Asserções |
|---|---|---|
| `FakeQueue` | `Jobs\Interfaces\Client` | `assertPushed`, `assertNotPushed`, `assertPushedTimes`, `assertNothingPushed` |
| `FakeDispatcher` | `EventDispatcherInterface` | `assertDispatched`, `assertNotDispatched`, `assertDispatchedTimes`, `assertNothingDispatched` |
| `FakeMailer` | `Mail\MailerInterface` | `assertSent`, `assertSentTo`, `assertSentTimes`, `assertNothingSent` |
| `FakeCache` | o pool configurado | instala um pool em memória |
| `FakeClock` | `RateLimit\ClockInterface` | relógio fixo e avançável |
| `InMemoryDisk` | `Storage\DiskInterface` | disco em memória |

```php
$queue = new FakeQueue();
QueueManager::getInstance()->setClient($queue);

$this->post('/orders', [...]);

$queue->assertPushed(SendReceipt::class, fn (JobEntity $job) => $job->getArgs()['orderId'] === 7);
```

O filtro importa: sem ele a asserção prova apenas que *algum* job daquele tipo
passou — o que costuma continuar verdadeiro quando os argumentos estão errados.

`FakeMailer` fecha a mensagem a cada `send()` e zera os destinatários. Herdá-los
entre envios é, num mailer de verdade, mandar e-mail para quem não devia
recebê-lo.

`FakeCache::install()` devolve o pool para inspeção; chame `uninstall()` no
`tearDown`. Sem ele o adapter padrão é o de sistema de arquivos, que grava em
`Cache/` e leva o resultado de uma execução para a seguinte.

## Transação por teste

```php
final class OrderTest extends TestCase
{
    use DatabaseTransactions;
}
```

`setUp` abre uma transação e `tearDown` a desfaz — inclusive quando o teste
falha, porque é `tearDown` e não o fim do método. A alternativa usual é truncar
tabelas entre testes: mais lento e, quando alguém esquece uma tabela nova,
produz o pior tipo de falha — um teste que passa sozinho e falha na suíte,
dependendo da ordem.

Desfazer duas vezes não é erro: um teste que commitou de propósito não pode
derrubar o `tearDown`.

O driver vem da mesma fonte que `Connection::getConnection()` usa. Ler o
`database.driver` do framework abriria a porta para a transação nascer com um
dialeto diferente do da conexão real.

## Teste de mutação

```bash
composer test:mutation
```

O Infection altera o código de propósito — inverte uma condição, troca um `+`
por `-`, apaga uma linha — e verifica se algum teste falha. Um mutante que
sobrevive é uma linha que a suíte executa mas não afirma nada sobre: cobertura
diz que passou por ali, mutação diz se o teste notaria a diferença.

O alvo é `src/Routing`, `src/Http` e `src/Validation` — onde uma condição
invertida em silêncio custa mais: uma rota que passa a casar o que não devia, um
header condicional que responde 200 no lugar de 304, uma regra que aprova o que
deveria recusar.

O piso é 70% de MSI coberto. O número real fica acima disso, e oscila alguns
pontos entre execuções porque mutantes que estouram o tempo limite contam como
não detectados — daí a margem no piso em vez de fixá-lo no valor do dia.

A cobertura é gerada uma vez e reaproveitada (`--skip-initial-tests`), com
**pcov**: o xdebug deixaria a suíte inteira várias vezes mais lenta mesmo sem
coletar. Na imagem local o pcov vem desabilitado por padrão e só é ligado por
este comando.

## Cobertura de linhas

```bash
composer test:coverage
```

O comando ativa o PCOV, gera o relatório XML e exige **ao menos 90% de
cobertura de linhas do código mantido**. O verificador lê o `index.xml` que o
PHPUnit acabou de produzir; relatórios antigos no diretório não podem alterar o
resultado.

O piso cobre `Auth`, `Config`, `Debug`, `Events`, `Http`, `Observability`,
`RateLimit`, `Routing`, `Storage`, `Support`, `Template`, `Validation` e
`Vite`. Fachadas e drivers legados que ainda existem para a transição à 3.0
continuam na suíte funcional, mas ficam fora desse indicador até serem
migrados ou removidos explicitamente. Assim, o número protege o código novo
sem fingir que compatibilidade legada já recebeu a mesma arquitetura de teste.
