# Autenticação e autorização

O Core não conhece modelos nem ORM. A aplicação implementa `UserProviderInterface`
para transformar um identificador persistido em `IdentityInterface` e registra essa
implementação no container. O identificador deve ser estável e serializável como string.

```php
// Config/container.php
return [
    UserProviderInterface::class => new AppUserProvider(),
    TokenRepositoryInterface::class => new DatabaseTokenRepository(),
];
```

Use `SessionGuard` para autenticação web. `login()` rotaciona o ID da sessão e o
token CSRF; `logout()` invalida a sessão inteira. Para APIs, `BearerTokenGuard`
emite tokens opacos de 256 bits, guarda somente o SHA-256, aceita expiração, escopos,
revogação e refresh. A persistência é uma implementação de `TokenRepositoryInterface`;
`InMemoryTokenRepository` é exclusivamente útil para testes.

```php
#[Authenticated]
public function profile(#[CurrentUser] IdentityInterface $user): Response {}

#[Authenticated('bearer', scopes: ['users.write'])]
#[Authorize('users.update', subject: 'id')]
public function update(string $id, UpdateUser $input): Response {}
```

Registre abilities no `PolicyRegistry`. Uma policy pode implementar `PolicyInterface`
ou ser uma closure `(IdentityInterface $identity, mixed $subject): bool`; por isso ela
continua testável sem HTTP.

```php
$policies->register('users.update', fn (IdentityInterface $user, string $id) => $user->id() === $id);
```

`#[Authenticated]` retorna 401 quando não existe identidade; `#[Authorize]` retorna
403 quando a ability não permite a ação. Para testes HTTP, use
`$client->actingAs(new SimpleIdentity('42'))`. Esse helper semeia somente o
`RequestScope` do cliente, sem alterar sessão ou estado global.

`neof auth:token <identity> --expires 60 --scope reports.read` emite o token uma
única vez; só use com um `TokenRepositoryInterface` persistente configurado em
`Config/container.php`. `neof policy:list --json` expõe as abilities registradas.

## Bloqueio de força bruta

`#[RateLimit]` conta **requisições**; `LoginThrottle` conta **falhas de credencial**.
São camadas diferentes, e a segunda é a que protege o login:

```php
#[Route('/login', ['POST'])]
#[RateLimit(policy: 'login')]
public function store(LoginRequest $input, LoginThrottle $throttle, SessionGuard $guard): Response
{
    $identity = $throttle->attempt($input->email, $this->request, function () use ($input): ?IdentityInterface {
        $user = $this->users->findByEmail($input->email);

        return $user !== null && $this->hasher->verify($input->password, $user->passwordHash()) ? $user : null;
    });

    if ($identity === null) return $this->json(['error' => 'Credenciais inválidas.'], 401);

    $guard->login($identity);

    return $this->redirect('/');
}
```

A verificação vai dentro de um callable de propósito. Checar o bloqueio, verificar a
senha, contar a falha e zerar no sucesso é uma sequência que não pode ser cumprida pela
metade — expor os passos soltos deixaria a aplicação esquecer o `recordFailure`, que é
justamente o que dá segurança ao resto.

O bloqueio conta em **duas dimensões**, porque uma só não cobre os dois ataques:

| Dimensão | Cobre |
|---|---|
| identificador | atacante distribuído por muitos IPs varrendo **uma** conta |
| IP | um host varrendo **muitas** contas |

Um login que dá certo zera as duas. Sem isso, um escritório atrás de um NAT derrubaria
o próprio acesso. Enquanto bloqueado, `$verify` **não é chamado**: não se chega ao
`password_verify`, que é o custo de CPU que o ataque quer provocar.

O identificador é normalizado (minúsculas, sem espaços nas pontas) e reduzido a hash
antes de virar chave — `Foo@Bar.com` e `foo@bar.com` são a mesma conta, e o e-mail cru
não tem por que ficar legível numa chave do Redis.

Ligado por padrão, em `Config/auth.php`:

```php
return ['login_throttle' => ['limit' => 5, 'window' => 900]];
```

Estourar lança `TooManyRequestsException` com `Retry-After`. `LoginFailed` e
`LoginThrottled` são despachados para auditoria — o segundo carrega `dimension`,
que distingue ataque dirigido de varredura. Nenhum dos dois carrega a senha.

Manter também `#[RateLimit(policy: 'login')]` na rota é útil: barra o volume antes
de chegar ao controller. A política `login` já vem declarada.

Rotas autenticadas por sessão mantêm CSRF ligado. Rotas com bearer token devem usar
`#[Route(..., validCsrf: false)]` somente quando não aceitarem autenticação por cookie.
