# Rate limiting

Declare o limite na classe do controller ou na action; a action substitui o
valor da classe.

```php
use NeoFramework\Core\Attributes\RateLimit;
use NeoFramework\Core\RateLimit\RateLimitKey;

#[RateLimit(limit: 60, window: 60, key: RateLimitKey::UserOrIp)]
public function index(): Response {}
```

`window` é dado em segundos. As respostas permitidas incluem `RateLimit-Limit`,
`RateLimit-Remaining` e `RateLimit-Reset` (timestamp Unix). A primeira resposta
que excede o limite é `429 Too Many Requests`, em Problem Details para clientes
que aceitam JSON, e inclui também `Retry-After`.

As chaves disponíveis são `RateLimitKey::Ip`, `::User` e `::UserOrIp`. A chave
de usuário usa a identidade já presente no `RequestScope`; quando ela ainda não
foi resolvida, `UserOrIp` recua para IP e `User` usa o bucket `anonymous`.

## Políticas nomeadas

Limites repetidos em várias actions pertencem a `Config/rate_limit.php`:

```php
return [
    'policies' => [
        'api' => ['limit' => 60, 'window' => 60, 'key' => 'user_or_ip'],
    ],
];
```

A política `login` (5 por 900s, por IP) já vem declarada por padrão — declarar de
novo apenas sobrescreve os campos informados.

```php
#[RateLimit(policy: 'login')]
public function store(LoginRequest $input): Response {}
```

Uma política malformada derruba o bootstrap com o nome dela na mensagem, e um
atributo que cita política inexistente faz `neof route:cache` sair com código 1
— o atributo nunca chega a produção sem efeito.

> Limitar a **rota** de login não protege o login. O contador de rota conta
> requisições, então cobra o orçamento de quem acertou a senha, e não distingue a
> conta atacada: um atacante distribuído por muitos IPs varre uma conta sem estourar
> contador nenhum. Para isso existe `LoginThrottle`, que conta falhas em duas
> dimensões — ver [AUTH.md](AUTH.md). As duas camadas se somam.

## Store e estratégia

```php
return [
    'store'      => 'redis',          // cache | redis
    'strategy'   => 'sliding_window', // fixed_window | sliding_window
    'on_failure' => 'open',           // open | closed
    'redis'      => ['host' => env('REDIS_HOST') ?: '', 'port' => 6379, 'password' => '', 'prefix' => 'neoframework:rl:', 'timeout' => 0.5],
];
```

`store=cache` é o baseline e conta com read-modify-write sobre o cache
configurado: **dois processos podem ler o mesmo contador e ultrapassar o
limite**. Ele serve para um único processo, desenvolvimento e testes.

`store=redis` incrementa e expira dentro de um único script Lua. O Redis executa
scripts de forma serializada, então o contador é exato mesmo com vários workers
concorrendo. A expiração é gravada só na primeira requisição da janela: renová-la
a cada hit faria a janela nunca fechar.

`strategy=fixed_window` é o contador simples: barato, mas deixa passar até duas
cotas na virada da janela — o cliente gasta a cota nos últimos segundos e a cota
inteira nos primeiros da janela seguinte. `sliding_window` corrige isso pesando o
contador da janela anterior pela fração dela que ainda cabe nos últimos `window`
segundos, sem guardar um timestamp por requisição.

## Quando o backend cai

`on_failure` decide o comportamento quando o store lança:

- `open` deixa a requisição passar. Prioriza disponibilidade; use quando o limite
  protege capacidade, não segurança.
- `closed` responde 429. Use em login, recuperação de senha e qualquer rota em
  que passar sem contar é pior que recusar.

Nos dois casos a falha é registrada em nível `error`. A chave — que carrega IP ou
id de usuário — fica fora do log.

A conexão do store é aberta na primeira contagem, não no build do container: um
Redis fora do ar aplica a estratégia configurada em vez de impedir o bootstrap.

## Substituindo os contratos

`RateLimiterInterface`, `RateLimitStoreInterface` e `ClockInterface` são
independentes. Um adapter externo só precisa implementar o store; um algoritmo
diferente (token bucket, por exemplo) implementa o limiter e continua ganhando
fail-open/fail-closed ao ser embrulhado em `ResilientRateLimiter`. Nos testes,
`NeoFramework\Core\Testing\FakeClock` e `InMemoryRateLimitStore` tornam a
contagem determinística.
