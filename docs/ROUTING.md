# Roteamento

## Prefixos

```php
#[RoutePrefix('/api/v1', name: 'api.v1.')]
final class OrderController extends Controller
{
    #[Route('/orders/{id:\d+}', ['GET'], name: 'orders.show')]
    public function show(int $id): Response {}
}
```

O prefixo de nome é concatenado cru, sem separador implícito — quem escreve
decide se é `.`, `:` ou nada. Uma action sem nome não ganha um só por existir o
prefixo.

## Gerando URLs

```php
route('api.v1.orders.show', ['id' => 7]);        // /api/v1/orders/7
route_url('api.v1.orders.show', ['id' => 7]);    // https://exemplo.test/api/v1/orders/7
```

`route_url()` exige `app.url` configurado. Sem ela não há como saber o host, e
adivinhar a partir do request produziria links que vazam o host de um proxy.

### O valor precisa casar a rota

```php
route('api.v1.orders.show', ['id' => 'abc']);
// InvalidArgumentException: O valor de 'id' não satisfaz a restrição da rota
```

Antes isso devolvia `/api/v1/orders/abc` — um link que responde 404 sem avisar
nada a quem o gerou. O erro pertence a quem monta a URL, não a quem clica nela.

## URLs assinadas

Para confirmação de e-mail, download temporário e webhook de retorno: onde a
autorização viaja na própria URL porque não há sessão do outro lado.

```php
$link = signed_route('confirmar', ['token' => $t], expiresInSeconds: 3600);

if (!UrlGeneratorFactory::signer()->isValid($request->getRequestTarget())) {
    throw new ForbiddenException();
}
```

A assinatura cobre o path **e** a query, então trocar `?acao=ver` por
`?acao=excluir` invalida o link. A comparação usa `hash_equals()`. A chave é
`crypto.authentication_key` — não a de criptografia: são propósitos distintos, e
reusar a mesma chave para assinar e cifrar enfraquece as duas.

`isValid()` devolve `false` para assinatura ausente, alterada ou vencida, sem
distinguir os casos: essa distinção ajuda quem tenta forjar e não ajuda quem tem
um link legítimo.

## `OPTIONS` automático

O matcher responde `OPTIONS` sozinho, com `204` e o header `Allow`, sem executar
controller. Um preflight de CORS chega como `OPTIONS` numa rota que só declara
`POST`; responder 405 ali quebraria a chamada real que viria depois, e obrigar
cada controller a declarar `OPTIONS` seria ruído em toda action.

## Rotas inalcançáveis

`neof route:cache` recusa uma rota que nunca será alcançada:

```
Rotas inalcançáveis:
  GET /users/{slug:[a-z]+} (users.by-slug) nunca é alcançada:
  GET /users/{id} (users.show) casa tudo que ela casaria.
```

Uma rota que nunca roda não gera erro em runtime — ela apenas não acontece. Esta
é a única chance de alguém saber.

O critério é conservador de propósito: só reporta quando a cobertura é
demonstrável segmento a segmento. **Sobreposição parcial não é reportada**, porque

```php
#[Route('/users/{id:\d+}')]      // números
#[Route('/users/{slug:[a-z]+}')] // letras
```

é um desenho legítimo e comum: as duas são alcançáveis, cada uma pelo seu
conjunto de valores. Um detector que gritasse ali seria desligado na primeira
semana.

Rota estática nunca é reportada: o matcher a consulta antes de qualquer dinâmica,
então ela é sempre alcançável. Rotas com parâmetro opcional são puladas — o
opcional muda quantos segmentos a rota casa, e comparar contagens deixaria de ser
sólido.

Nomes e paths exatamente duplicados já eram recusados na compilação, em qualquer
boot.

## Host e subdomínio

```php
#[RouteHost('{tenant}.example.com')]
final class PainelController extends Controller
{
    #[Route('/painel', ['GET'], name: 'painel')]
    public function painel(string $tenant): Response {}
}
```

As variáveis do host chegam à action junto com as do path, e valem a mesma
regra: se a action não declara o parâmetro, a rota é recusada na compilação.

A constraint padrão é `[^.]+`, não `[^/]+`. Num host a fronteira é o ponto, e um
`{tenant}` que casasse ponto faria `{tenant}.example.com` aceitar
`a.b.example.com` — um subdomínio de outro nível alcançando uma rota que se
acredita restrita.

Detalhes do casamento:

- a porta não faz parte do padrão: `example.com` casa `example.com:8080`;
- o casamento é case-insensitive, porque host não distingue caixa;
- uma rota **sem** `#[RouteHost]` continua casando qualquer host;
- uma requisição sem `Host` conhecido **não** alcança rota restrita — aceitar
  faria a restrição sumir sempre que o header não chegasse;
- o mesmo path pode existir em hosts diferentes, e a rota restrita vence a que
  atende qualquer host;
- `#[RouteHost]` no método vence o da classe.

> **`#[RouteHost]` não é, sozinho, uma fronteira de segurança.** O host vem do
> header `Host`, escrito pelo cliente. Configure `http.trusted_hosts` ou faça o
> servidor da frente recusar hosts desconhecidos; sem isso um atacante escolhe
> qual rota alcançar.

`route_url()` monta o host da própria rota — gerar `https://example.com/painel`
para uma rota que só responde em `{tenant}.example.com` produziria um link que
dá 404 sem avisar ninguém.

## Prioridade e defaults

```php
#[Route('/artigos/{id:\d+}', ['GET'], name: 'artigos.id', priority: 10)]
#[Route('/relatorio/{formato?}', ['GET'], defaults: ['formato' => 'pdf'])]
```

Maior prioridade é testada antes. Existe para quando duas rotas dinâmicas se
sobrepõem de propósito e a ordem de declaração — que depende da ordem dos
métodos na classe — não é base confiável. Empate mantém a ordem de declaração.

`defaults` preenche uma variável ausente na URL. O valor presente na URL sempre
vence: um placeholder opcional preenchido pelo cliente não pode ser
sobrescrito pelo default.

## Grupos e API programática

Os atributos cobrem bem o caso comum e param de cobrir quando o mesmo conjunto
de rotas precisa existir sob prefixos, hosts ou middlewares diferentes — com
atributo isso vira um controller duplicado por variação.

`Config/routes.php` retorna um callable que recebe o registrar:

```php
return function (RouteRegistrar $routes): void {
    $routes->group(['prefix' => '/api/v1', 'name' => 'api.v1.', 'middleware' => [Auth::class]], function (RouteRegistrar $r): void {
        $r->get('/users', [UserController::class, 'index'], name: 'users.index');
        $r->post('/users', [UserController::class, 'store'], name: 'users.store');
    });
};
```

`prefix` e `name` acumulam com o grupo externo, `middleware` concatena; `host`,
`priority`, `defaults` e `csrf` sobrescrevem — um grupo interno precisa poder
sair do host do externo. O contexto é restaurado mesmo quando o callback lança,
para que o grupo seguinte não herde o prefixo do que falhou.

Uma action inexistente é recusada no registro, não ao servir a rota. As rotas
programáticas entram na **mesma** coleção das de atributo, então a checagem de
nome e de rota duplicada vale entre os dois estilos: compilar em separado
deixaria uma rota de `Config/routes.php` sombrear outra em silêncio.

## Fallback

```php
#[RouteFallback(['GET'])]
public function naoAchou(): Response
{
    return $this->html($this->view('erros/404'), 404);
}
```

Substitui o 404 genérico por uma resposta da aplicação. Só entra em cena depois
que todas as rotas falharam, então não sombreia nada.

Não afeta 405: um método errado numa rota que existe continua sendo 405 —
transformá-lo em 404 esconderia do cliente que o recurso existe. Dois fallbacks
para o mesmo método são recusados na compilação, porque um dos dois nunca
rodaria e descobrir qual exigiria ler a ordem de descoberta dos controllers.
