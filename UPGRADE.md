# Atualizando para o NeoFramework Core 2.0

A versão 2.0 corrige falhas de segurança que tornavam inoperantes proteções que
o framework anunciava ter. As mudanças abaixo quebram compatibilidade.

Comece por **1** e **2**: são as que exigem mudança em código de aplicação.

---

## 1. A proteção de CSRF passou a funcionar

Quatro defeitos sobrepostos faziam com que a validação **nunca fosse executada**
e, se fosse, rejeitasse justamente as requisições com o token correto. Depois de
atualizar, requisições `POST`/`PUT`/`PATCH`/`DELETE` sem token válido passam a
receber **403**.

### O que fazer

**a)** Todo formulário que altera estado precisa enviar o token. Se o seu layout
usa `{neof_csrf_token}`, nada muda. Caso contrário, inclua:

```html
<input type="hidden" name="CSRF_TOKEN" value="...">
```

**b)** Requisições AJAX devem mandar o cabeçalho `X-CSRF-TOKEN`. Antes o
framework procurava `$_SERVER['X-CSRF-TOKEN']`, chave que o PHP nunca cria, então
o cabeçalho era ignorado — agora ele é lido de verdade.

**c)** A constante do controller mudou de nome **e de sentido**:

```php
// Antes — o nome sugeria "validar", mas true desligava a checagem
const validCsrfToken = true;

// Agora
const skipCsrfValidation = false;  // padrão: valida
const skipCsrfValidation = true;   // isenta o controller inteiro
```

**d)** Uma rota que aceita `GET` e `POST` deixou de ficar isenta. A decisão passou
a ser por requisição: métodos seguros (`GET`, `HEAD`, `OPTIONS`) nunca exigem
token; os demais exigem. Para isentar um endpoint específico (um webhook, por
exemplo):

```php
#[Route('webhook', ['POST'], validCsrf: false)]
```

**e)** Após login/logout, chame `Session::regenerateId()` e
`Session::regenerateCsrfToken()`.

## 2. A entrada não é mais escapada automaticamente

`Request::get()`, `post()`, `cookie()`, `getArray()`, `postArray()` e
`cookieArray()` retornavam o valor com `htmlspecialchars` aplicado. O padrão
passou a ser **não escapar**.

**Por quê:** escapar na entrada corrompe o que vai para o banco (um nome
"O'Brien" virava `O&#039;Brien` no registro) e não protege a saída, que é onde o
XSS acontece. O escape correto é responsabilidade da camada de template.

```php
$request->post('nome');        // valor original
$request->post('nome', true);  // escapado, se você realmente quiser
```

Revise os pontos em que você imprime valores direto no HTML sem passar pelo
template.

## 3. `Request::all()` não inclui mais cookies

A ordem de mescla anterior fazia cookies **sobrescreverem** POST e GET. Como
qualquer subdomínio pode plantar um cookie, isso permitia sobrescrever campos de
formulário (`role`, `user_id`, `price`).

A precedência agora é: query string < corpo < JSON < arquivos. Para ler cookies,
use `cookieArray()`.

## 4. `Response::send()` não encerra mais a execução

O `exit` foi removido. O Router controla o fluxo.

Se o seu código dependia do `exit` para interromper o processamento, use a
exceção nova:

```php
use NeoFramework\Core\Exceptions\HttpResponseException;

throw new HttpResponseException($response->setCode(403));
```

`setCode()` também deixou de chamar `http_response_code()` como efeito colateral;
o status é emitido no `send()`.

## 5. Redirects validam o destino

```php
$response->go('/pagina');                       // ok
$response->go('https://outro.com');             // InvalidArgumentException
$response->go('//outro.com');                   // InvalidArgumentException
$response->goToSite('https://parceiro.com');    // ok
$response->goToSite('javascript:alert(1)');     // InvalidArgumentException
```

## 6. Host da requisição precisa ser declarado

`Url::getUrlBase()` usava `HTTP_HOST` sem validação, então um cabeçalho `Host`
forjado contaminava todo link gerado — inclusive e-mails de recuperação de senha.

Defina no `.env`:

```ini
APP_URL=https://app.exemplo.com
```

ou, se a aplicação responde por vários domínios:

```ini
TRUSTED_HOSTS=app.exemplo.com,admin.exemplo.com
```

Sem nenhum dos dois, o framework cai para `SERVER_NAME`.

Se a aplicação roda atrás de proxy ou load balancer, declare também:

```ini
TRUSTED_PROXIES=10.0.0.1,10.0.0.2
```

Sem isso, `X-Forwarded-Proto` e `X-Forwarded-For` são ignorados — é o que impede
um cliente de forjar o próprio IP em logs e rate limit (`Functions::getUserIp()`).

## 7. CORS recusa a combinação insegura

`allow_credentials: true` com `allowed_origins: ['*']` agora lança exceção na
inicialização. Antes, o middleware refletia a Origin do requisitante junto de
`Access-Control-Allow-Credentials: true`, permitindo que qualquer site lesse
respostas autenticadas.

Se você usa `CORS_CREDENTIALS=true`, liste as origens explicitamente:

```ini
CORS_ORIGINS=https://app.exemplo.com,https://admin.exemplo.com
```

Requisições sem cabeçalho `Origin` deixaram de receber cabeçalhos CORS.

## 8. CSP padrão não permite mais script inline

A política anterior incluía `'unsafe-inline'` e `'unsafe-eval'`, o que a tornava
incapaz de barrar script injetado — o motivo de existir uma CSP.

Se a sua aplicação usa `<script>` inline ou `onclick=`, você tem três caminhos:

1. mover o script para arquivo externo (recomendado);
2. adotar nonce/hash;
3. sobrescrever a política via `.env`, assumindo o risco:

```ini
CONTENT_SECURITY_POLICY="default-src 'self'; script-src 'self' 'unsafe-inline'"
```

`Strict-Transport-Security` passou a ser emitido apenas sobre HTTPS.

## 9. Uploads: SVG e executáveis recusados

- `image/svg+xml` saiu da lista de imagens aceitas: um SVG servido do próprio
  domínio executa JavaScript.
- Extensões executáveis (`php`, `phtml`, `phar`, `htaccess`, `html`, `js`, …) são
  recusadas **inclusive** com `FileStorageType::ANY`.
- O nome de gravação passou a ser aleatório, preservando apenas a extensão
  validada. O nome enviado pelo cliente não é mais reaproveitado.
- `saveFromRequest()` exige `is_uploaded_file()`.

Se você guardava o caminho retornado no banco, nada muda — o retorno continua
sendo o caminho final. Se você dependia do nome original, passe a guardá-lo em
coluna separada.

## 10. `env()` devolve `mixed`

```php
env('CORS_ENABLED')  // antes: string "true"; agora: bool true
```

Os literais `true`, `false`, `null` e `empty` (com ou sem parênteses) são
convertidos. Comparações como `env('X') == "true"` devem virar
`filter_var(env('X'), FILTER_VALIDATE_BOOLEAN)` ou simplesmente `env('X')`.

## 11. Cache: Redis e Memcached passam a funcionar

`strtolower(!env("CACHE_ADAPTER"))` transformava o valor em booleano e o seletor
caía sempre em `filesystem`, por mais que a configuração dissesse outra coisa.

**Verifique a sua configuração**: se você acreditava estar usando Redis, na
prática estava usando o disco. Agora o adaptador declarado é o que vale, e o
conteúdo do cache antigo não migra.

A URL do Redis também estava malformada (a senha era interpretada como nome de
usuário) — autenticação com senha só funciona a partir desta versão.

## 12. Middlewares `after` passam a ter efeito

`Attributes\Middleware::handleAfter()` descartava o retorno de `after()`. Se você
escreveu um middleware cujo `after()` modifica e devolve a resposta, ele estava
sendo ignorado e **agora passa a valer**.

O atributo também aceita nome de classe, que é a forma natural de escrevê-lo:

```php
#[Middleware(Auth::class)]        // agora funciona
#[Middleware(new Auth())]         // continua funcionando
```

## 13. Cache de rotas (opcional)

Dois comandos novos:

```bash
./vendor/bin/neof route:cache   # gera Cache/routes.php
./vendor/bin/neof route:clear   # remove
```

O mapa só é lido quando `ENVIRONMENT=prod`; em desenvolvimento ele é reconstruído
a cada requisição, para que rotas novas valham na hora. Gere o cache no deploy.

O container do PHP-DI também passou a ser compilado quando `ENVIRONMENT=prod`,
gravando em `Cache/container`. Garanta que o diretório `Cache/` seja gravável.

## 14. Outras mudanças

- `Message::setError()` / `setMessage()` / `setSuccess()` **acumulam** em vez de
  sobrescrever. Para o comportamento antigo, use `Message::replaceError()`.
- `Message` deixou de estender `Layout`.
- `Logger` não registra mais o `FirePHPHandler`, que enviava cada registro em
  cabeçalhos `X-Wf-*` da resposta HTTP — em produção isso entregava stack traces
  ao cliente.
- `Validator::make()` lança `InvalidArgumentException` ao receber uma regra que
  não seja `Respect\Validation\Validator`; antes disparava um `Error`.
- `Functions::generateId()` devolve string hexadecimal aleatória em vez de um
  inteiro derivado de `microtime()`.
- O disco S3 do `FileStorage` exige as dependências opcionais
  `aws/aws-sdk-php` e `league/flysystem-aws-s3-v3`, que nunca estiveram
  declaradas.
- Sessão: cookie ganhou `secure` e `SameSite=Lax`; `destroy()` limpa `$_SESSION`
  e expira o cookie.
- Route rewrite (`Config/route_rewrite.config.php`) funciona — a busca era feita
  nos valores do mapa e a leitura pela chave, então nunca casava.
- Controllers na raiz de `App/Controllers` passam a ser encontrados.
- `Bundler`: subdiretórios de `Resources/Css` e `Resources/Js` passam a ser
  percorridos, e os diretórios de saída são criados com permissão correta
  (`mkdir` usava `755` decimal, que vale `0o1363`).
