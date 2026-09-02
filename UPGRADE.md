# Atualizando para o NeoFramework Core 2.0

A versão 2.0 corrige falhas de segurança que tornavam inoperantes proteções que
o framework anunciava ter, e substitui o bundler próprio pelo Vite. As mudanças
abaixo quebram compatibilidade.

Comece por **1**, **2**, **14** e **19**: são as que exigem mudança em código de
aplicação ou de deploy. A **14** torna o Node um requisito de build; a **19**
remove o comando `migrate`, então um pipeline que o chame para de funcionar.

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

## 14. O `Bundler` foi substituído pelo Vite

`Bundler::getCssFile()` e `Bundler::getJsFile()` **não existem mais**. A classe
inteira foi removida, e com ela a dependência `matthiasmullie/minify`.

```php
// Antes, no layout
<link rel="stylesheet" href="<?= Url::getUrlBase() ?>assets/css/<?= Bundler::getCssFile('site') ?>">
<script src="<?= Url::getUrlBase() ?>assets/js/<?= Bundler::getJsFile('site') ?>"></script>
```

```html
<!-- Agora, no template HTML: uma linha -->
{neof_vite}
```

**Por quê:** o bundler concatenava e minificava, sem resolução de módulos, sem
tree-shaking, sem code splitting e sem qualquer forma de recarga automática. Pior,
o nome do arquivo gerado trazia um `Functions::generateId()` aleatório, então
**todo build invalidava todos os bundles no cache do navegador**, mesmo quando
nenhum arquivo tinha mudado. O Vite usa hash de conteúdo: o que não mudou mantém
o nome.

### O que fazer

**a)** Instale o Node (20.19+ ou 22.12+) e copie o `package.json` e o
`vite.config.js` do skeleton para a raiz do projeto. Depois, `npm install`.

**b)** Mova os arquivos de `Resources/Css` e `Resources/Js` para
`resources/css` e `resources/js` — minúsculo — e crie os pontos de entrada. O
Vite não varre diretórios: ele parte de um entrypoint e segue os `import`.

```js
// resources/js/app.js
import './algum-modulo.js'
```

```css
/* resources/css/app.css */
@import "tailwindcss";
```

**c)** Troque as chamadas ao `Bundler` por `{neof_vite}` no `<head>` do layout.
O placeholder funciona como o `{neof_csrf_token}`: é preenchido quando o
documento o declara, e ignorado quando não.

**d)** Se você tinha mais de uma chave no `bundler.config.php` (`site`, `admin`),
cada uma vira um entrypoint declarado no layout daquela área:

```php
class Admin extends Layout
{
    protected array $viteEntrypoints = ['resources/css/admin.css', 'resources/js/admin.js'];
}
```

O entrypoint precisa constar em **dois** lugares: `build.rollupOptions.input` no
`vite.config.js` e `entrypoints` em `Config/vite.config.php`.

**e)** `Config/bundler.config.php` pode ser apagado.

**f)** Se você usava o binário standalone do Tailwind na raiz do projeto, ele
não é mais chamado pelo `neof build`. O Tailwind v4 entra pelo plugin
`@tailwindcss/vite`; apague o binário.

## 15. Node passou a ser requisito de build

Um deploy que rodava apenas `composer install` agora precisa também de
`npm ci && ./vendor/bin/neof build` — ou de versionar o diretório `public/build/`.
Imagens de CI e de deploy precisam do Node.

O `neof build` também **passou a sair com status diferente de zero quando o build
falha**. Antes ele imprimia o erro e saía com 0, de modo que um
`neof build && deploy` publicava assets inexistentes com sucesso aparente.
Verifique se algum pipeline dependia desse comportamento.

## 16. Os assets saem em `public/build`, não em `public/assets`

**Por quê:** `public/assets` é o diretório padrão de uploads do `FileStorage` e do
`File::save()`. O Vite limpa o diretório de saída a cada build, então apontá-lo
para lá apagaria arquivos enviados por usuários.

### O que fazer

**a)** No `.gitignore`, troque `public/assets/js/*` e `public/assets/css/*` por
`public/build/*`, e acrescente `node_modules/*` e `hot`.

**b)** No `public/robots.txt`, remova `Disallow: /assets/js` e
`/assets/css`. Não os substitua por `/build`: bloquear CSS e JS atrapalha a
indexação baseada em renderização.

**c)** Opcionalmente, sirva `/build/` com cache longo — os nomes têm hash de
conteúdo, então nunca precisam ser revalidados. O `.htaccess` do skeleton traz o
exemplo.

## 17. Desenvolvimento com HMR: `neof vite:dev` e o arquivo `hot`

```bash
./vendor/bin/neof vite:dev   # sobe o dev server; alias: vd
./vendor/bin/neof build      # compila para produção
```

Enquanto o dev server está no ar existe um arquivo `hot` na **raiz do projeto**,
contendo a origem do servidor. É por ele que o PHP decide entre apontar para o
dev server ou ler o manifest.

Ele fica na raiz, e não em `Cache/`, de propósito: `Cache/` é criado em tempo de
execução pelo PHP-FPM (o `Template` e o container do PHP-DI escrevem lá), e um
diretório criado antes pelo processo do Node, com outro dono, desligaria os dois
**em silêncio**. A raiz já existe e o PHP só precisa de leitura.

O arquivo é removido quando o servidor para. Se ele morrer de forma abrupta
(`kill -9`, queda de energia), o arquivo fica para trás — nesse caso, `rm hot`.
Um `neof build` também o remove. E ele **nunca** tem efeito sob
`ENVIRONMENT=prod`, então um `hot` esquecido não afeta produção.

## 18. A CSP se abre sozinha enquanto o dev server está no ar

O client do Vite carrega de outra origem, injeta `<style>` no DOM a cada
atualização de CSS e mantém um WebSocket. A CSP da seção **8** bloqueia as três
coisas, então o `SecurityHeaders` passou a acrescentar a origem do dev server a
`script-src`, `style-src` (com `'unsafe-inline'`), `connect-src`, `img-src` e
`font-src`.

Isso exige **duas** condições simultâneas: `ENVIRONMENT` diferente de `prod` **e**
um arquivo `hot` com uma origem válida. Nem um ambiente mal configurado nem um
`hot` esquecido afrouxam a política sozinhos, e em produção o header sai byte a
byte igual ao de antes. `script-src` **não** recebe `'unsafe-inline'` — só um
plugin com preâmbulo inline (React Fast Refresh) precisaria disso.

Para desligar por completo: `VITE_CSP_RELAX=false` no `.env`.

## 19. `migrate` deixou de existir; entraram `migration:*` e `db:*`

O sistema de migrações da NeoORM foi reescrito, e o comando único que fazia tudo
não sobreviveu. O `migrate` antigo recriava o banco, convergia o schema, semeava
dados e reescrevia docblocks — quatro coisas, sem nomeá-las, e sem permitir pedir
uma sem as outras.

Agora os comandos dizem o que mexem: **`migration:*` mexe em arquivos do
repositório, `db:*` mexe no banco vivo.**

| Comando | Alias | O que faz |
|---|---|---|
| `migration:generate [nome]` | `mg` | escreve uma migração. **Não abre conexão** |
| `migration:up` | `mu` | aplica as migrações pendentes |
| `migration:status` | `ms` | mostra o que está aplicado, pendente ou inconsistente |
| `db:push` | `dp` | converge o banco direto, sem gerar migração (só dev) |
| `db:pull` | `dl` | lê o banco para um arquivo de snapshot |
| `db:check` | `dc` | detecta drift em três eixos. **É o gate de CI** |
| `db:seed` | `ds` | popula o banco |
| `db:reset` | `dr` | apaga, recria e aplica tudo (só dev) |
| `model:types` | `mt` | gera as classes tipadas dos models. Não abre conexão |

### O que fazer

`php neof migrate` agora imprime o mapa acima e **sai com código 1**. É
deliberado: um script de deploy que ainda o chame precisa PARAR, em vez de seguir
achando que migrou.

| Você fazia | Agora |
|---|---|
| `php neof migrate` no deploy | `php neof migration:up` |
| `php neof migrate` em dev | `php neof db:push` |
| `php neof migrate --recreate` | `php neof db:reset` |
| contava com o seed junto | `php neof db:seed`, depois do schema convergir |
| contava com o PHPDoc junto | `php neof model:types` (ainda automático no `db:push` em dev) |

No CI, `db:check` é o comando que interessa: ele sai 0 apenas quando models,
snapshot e banco concordam, e não escreve nada — dá para rodá-lo em qualquer
ambiente, produção inclusive.

### Códigos de saída

Passaram a ser contrato, não decoração:

- **0** — sucesso.
- **1** — falhou, ou há drift.
- **2** — nada foi feito porque falta uma decisão sua: um rename ambíguo, uma
  remoção a confirmar. Não é "pior que 1" — é outra coisa, e um script de CI pode
  tratar as duas de formas diferentes.

### Novas chaves de `.env`

```env
PATH_MIGRATIONS=./Migrations
MIGRATIONS_TABLE=_neoorm_migrations
DBSCHEMA=public            # só PostgreSQL
MIGRATIONS_STRICT=true
```

Todas têm default — como `PATH_MODEL`, `MODEL_NAMESPACE`, `PATH_SEEDS` e
`SEEDER_NAMESPACE`, que também deixaram de ser obrigatórios. O layout padrão é o
do skeleton (`App/Models`, `App/Models/Generated`, `App/Seeders`, `Migrations`), e
um projeto que o siga não precisa declarar caminho nenhum. Os relativos são
resolvidos a partir da **raiz do projeto**, não do diretório de onde você chamou o
`neof`.

E **commite o diretório `Migrations/`** — os `.sql`, o `journal.json` e os
`meta/*.json` são código-fonte, não artefato de build. O detalhe do porquê está no
`UPGRADE.md` da NeoORM.

## 20. `model:phpdoc` virou `model:types`

`model:phpdoc` reescrevia os `@property` dos models para descrever as colunas que o `__get`
mágico do `Db` expunha. Sem o `Db`, um model não tem propriedade nenhuma, e continuar
anotando produziria docblock que o autocomplete usaria e o código desmentiria.

```bash
php neof model:types            # gera as classes tipadas em PATH_GENERATED
php neof model:types --check    # não escreve; sai 1 se divergir. É o gate de CI
```

Em vez de anotar, ele produz classes de verdade — linhas `final readonly`, payloads de
insert com argumentos nomeados e referências de coluna —, e **commite o diretório gerado**:
ele é código-fonte, não artefato de build. Configure onde ele fica com `PATH_GENERATED` e
`GENERATED_NAMESPACE`; por padrão é `{PATH_MODEL}/Generated`.

`model:phpdoc` continua registrado como lápide: imprime o mapa acima e sai com código 1,
para um script de build que ainda o chame parar em vez de seguir achando que fez o
trabalho.

No `db:push` a regeração continua automática em desenvolvimento; a flag mudou de
`--no-phpdoc` para `--no-types`.

## 21. `UniqueDb` e `ExistsDb` recebem tabela e coluna

A NeoORM 2.0 removeu a camada de consulta antiga: `Abstract\Model` não tem mais `get()`,
e as duas regras de validação dependiam dele. Elas passam a receber a **tabela gerada** e
a **referência de coluna**:

```php
use App\Models\Generated\Tables;

// Antes
new UniqueDb(new User, 'email');
new ExistsDb(new State, 'id');

// Agora
$u = Tables::users();
$s = Tables::state();

new UniqueDb($u, $u->email);
new ExistsDb($s, $s->id);
```

Rode `vendor/bin/neoorm generate:types` uma vez para ter o `Tables`.

Duas coisas melhoram junto: uma coluna inexistente vira erro de análise estática em vez de
exceção em runtime, e a regra faz um `COUNT` em vez de carregar a linha inteira para olhar
um campo. O terceiro parâmetro (`?Database`) existe para injetar a conexão em teste; sem
ele a regra usa `Database::fromConfig()`.

O `ExistsDb` deixou de assumir `id` como padrão: a chave primária é dado do schema, e
passá-la explicitamente é uma linha a mais que elimina a suposição.

## 22. Outras mudanças

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
- `matthiasmullie/minify` saiu das dependências, junto com o `Bundler`.
- `Abstract\Layout` ganhou `templatePath()`, protegido e sobrescrevível, e com
  ele a correção da barra dupla em `App/View/Templates`.
- `Layout::setTemplate()` e `getTemplate()` passaram a preencher `{neof_vite}`
  além de `{neof_csrf_token}`.
- Comando novo: `vite:dev` (alias `vd`). O `build` (alias `bu`) manteve o nome,
  mas mudou de implementação.

## 23. Roteamento absoluto, PSR-7 e PSR-15

O Router antigo (`/controller/metodo/param`) foi removido. Cada `#[Route]` agora declara
o path HTTP completo, opcionalmente combinado com `#[RoutePrefix]` no controller:

```php
#[RoutePrefix('/users')]
final class UserController extends Controller
{
    #[Route('/{id:\\d+}', ['GET'], name: 'users.show')]
    public function show(int $id): Response {}
}
```

- `{id}` aceita um segmento; `{id:\\d+}` adiciona uma restrição; `{slug?}` é opcional.
- `route('users.show', ['id' => 10])` gera `/users/10`.
- método errado agora é 405 com `Allow`; path desconhecido permanece 404.
- `#[Middleware]` é repetível e funciona na classe e no método.
- `Config/route_rewrite.config.php` deixa de existir; declare o path desejado no atributo.

`Request` e `Response` são PSR-7. Operações mutáveis foram trocadas pelas equivalentes
imutáveis e agora é obrigatório guardar o retorno:

```php
// antes
$response->setCode(201)->setHeader('X-Id', '10');

// agora
$response = $response->withStatus(201)->withHeader('X-Id', '10');
```

No Request, `getHeader()` devolve array e `getBody()` devolve `StreamInterface`. Use
`getHeaderLine()`/`headerLine()` e `bodyString()` quando quiser strings. Para construir
a requisição do SAPI, use `Request::fromGlobals()`.

Middlewares antigos com `before()`/`after()` não são mais aceitos. Implemente
`Psr\Http\Server\MiddlewareInterface::process()`. O código após
`$handler->handle($request)` substitui o antigo `after()`.

`HttpKernel::handle()` apenas devolve a resposta. Emissão é responsabilidade de
`Http\ResponseEmitter` no `public/index.php`.

## 24. Instalação em subdiretório: `APP_BASE_PATH`

O roteador antigo casava o path bruto de `REQUEST_URI`, então uma aplicação servida em
`https://host/app` era irroteável: `/app/users/1` tentava casar a rota `/app/users/1`.

Agora o prefixo é removido antes do casamento e reaplicado na geração de URL. Se a
aplicação roda na raiz do domínio, não há nada a fazer. Se roda em subdiretório:

```env
APP_BASE_PATH=/app
```

`APP_BASE_PATH` vence a derivação por `dirname(SCRIPT_NAME)`, que continua existindo como
conveniência — sob `try_files` do nginx e algumas configurações de FPM o `SCRIPT_NAME` não
corresponde ao prefixo real. Para forçar a raiz mesmo com `SCRIPT_NAME` apontando para um
subdiretório, declare `APP_BASE_PATH=/`.

`route()` e `UrlGenerator` já devolvem o path com o prefixo. `Request::basePath()` expõe o
valor resolvido.

## 25. Gramática de parâmetros opcionais

O marcador de opcional vem **depois do nome**, nunca no fim do placeholder:

```php
#[Route('/posts/{slug}/{page?}')]        // correto
#[Route('/posts/{slug}/{page?:\d+}')]    // correto, com restrição
#[Route('/posts/{slug}/{page:\d+?}')]    // NÃO é opcional: \d+? é quantificador lazy
```

A ambiguidade é real — `\d+?` é uma repetição lazy válida em PCRE —, então a posição do
`?` é o que distingue os dois casos.

Um placeholder fora dessa gramática agora **estoura na compilação**. Antes ele era
compilado como texto literal, produzindo uma rota que nunca casava e nenhum erro.

## 26. Kit de testes HTTP

`HttpKernel::handle()` devolve a resposta sem emitir nada, e o kit é a colheita disso:
a mesma pilha de produção, exercitada em memória, sem servidor, sem superglobais e sem
output buffering.

```php
use NeoFramework\Core\Testing\HttpTestCase;

final class UserTest extends HttpTestCase
{
    protected function controllers(): array { return [UserController::class]; }

    public function testShow(): void
    {
        $this->getJson('/users/42')->assertOk()->assertJsonPath('id', 42);

        $this->postJson('/users', ['email' => 'ana@example.com'])
            ->assertCreated()
            ->assertHeader('Location');

        $this->delete('/users/1')->assertMethodNotAllowed()->assertAllows(['GET', 'HEAD']);
    }
}
```

`TestClient::forControllers()` monta o kernel a partir das classes informadas — não varre
o disco nem toca no cache de rotas, então um teste enxerga só as rotas que declarou.

O cliente mantém um cookie jar, o que torna testável um fluxo com sessão. Dois clientes no
mesmo processo têm cookies independentes:

```php
$client = TestClient::forControllers([AuthController::class]);
$client->post('/login', ['user' => 'ana']);   // guarda o Set-Cookie
$client->get('/perfil')->assertOk();          // reenvia o cookie
```

Outros ajustes: `withBasePath()` para instalação em subdiretório, `withHeader()` para
headers padrão, `withCsrfToken()` para semear a sessão e enviar o token.

`phpunit/phpunit` é dependência de desenvolvimento do framework; `NeoFramework\Core\Testing`
só carrega dentro da suíte.

## 27. `route:list`

O roteamento por atributo espalha a definição das rotas por todos os controllers. O comando
mostra a tabela compilada:

```bash
php neof route:list                  # tabela
php neof route:list --method PUT     # filtra por verbo
php neof route:list --path /users    # filtra por trecho do path
php neof route:list --json           # para ferramentas
```

`HEAD` não aparece: ele acompanha todo `GET` e só poluiria a saída.

**Correção junto:** `bin/neof` resolvia o autoloader por `__DIR__`, e sob instalação por
path repository com `"symlink": true` isso encontrava o autoloader do próprio framework, que
não conhece `App\`. A descoberta de controllers não achava nada e **`route:cache` gravava um
mapa vazio** — uma aplicação em produção respondia 404 em tudo. Agora o
`$GLOBALS['_composer_autoload_path']` que o Composer declara tem precedência.

## 28. Eventos PSR-14

O framework passa a despachar eventos do ciclo HTTP: `RequestReceived`, `RouteMatched`,
`ResponseCreated` (com a duração, para latência) e `ExceptionRaised` (com o status que será
respondido). Registre listeners em `Config/events.php`:

```php
use NeoFramework\Core\Events\ResponseCreated;

return [
    ResponseCreated::class => [
        App\Listeners\RecordLatency::class,
        [App\Listeners\WarnOnSlowRequest::class, 10],   // prioridade: maior roda antes
    ],
];
```

Listeners são class-string com `__invoke()`, resolvidos pelo container. Um listener
inexistente ou não invocável falha **no registro**, não durante uma requisição, e
`neof event:list` (alias `el`, com `--json`) mostra o mapa em ordem de execução.

Sem listener registrado, despachar é um no-op — instrumentação desligada não altera o
comportamento da aplicação. Para trocar o dispatcher, registre um
`Psr\EventDispatcher\EventDispatcherInterface` no container.

## 29. Request ID e logs estruturados

Toda resposta agora carrega `X-Request-Id`, e o mesmo valor aparece no `requestId` do
Problem Details e no contexto de todo log da requisição — é o que liga o que o usuário viu
ao que ficou registrado.

O `X-Request-Id` recebido é reaproveitado **apenas** quando vem de um proxy declarado em
`http.trusted_proxies` e casa `^[A-Za-z0-9._-]{1,128}$`; fora disso é descartado e um novo é
gerado. O valor vai para header de resposta e para log, então aceitar o que o cliente mandar
seria dar a ele a caneta.

`Config/logging.php` ganhou `level`, `format`, `stream` e `path`:

```php
return [
    'channel' => 'system',
    'level'   => env('LOG_LEVEL') ?: 'debug',
    'format'  => env('LOG_FORMAT') ?: 'line',   // 'json' em produção
    'stream'  => env('LOG_STREAM') ?: 'file',   // 'stderr' em container
    'path'    => 'Logs/system.log',
];
```

`format: json` emite uma entrada por linha, indexável sem parser próprio. `stream: stderr`
é o default correto em container — arquivo dentro do container se perde no restart.
`Logger::channel('queue')` separa canais.

A redação de dados sensíveis agora cobre também os campos que um DTO marca com
`#[Sensitive]`, além do casamento por nome (`password`, `token`, `authorization`…).

## 30. Atributos de origem em DTOs

`#[FromBody]`, `#[FromQuery]`, `#[FromRoute]` e `#[FromHeader]` **passam a funcionar** — antes
eram declarados e nunca lidos, então escrevê-los não tinha efeito nenhum. Cada campo pode
declarar sua origem, e um mesmo DTO pode misturá-las:

```php
final readonly class UpdatePost
{
    public function __construct(
        #[FromRoute] public int $id,
        #[FromQuery] public string $tab,
        #[FromBody] public string $title,
        #[FromHeader('X-Tenant')] public string $tenant,
        #[FromQuery('sort_by')] public ?string $sortBy = null,
    ) {}
}
```

Sem atributo, a origem continua sendo a heurística anterior: corpo interpretado, senão corpo
JSON, senão query string. A validação de rotas em tempo de compilação reconhece variáveis
consumidas por `#[FromRoute]`, então a action não precisa mais declarar o parâmetro.

`#[Sensitive]` também deixou de ser inerte: o campo marcado é registrado no escopo da
requisição e redigido pelo `Logger`. O valor recusado nunca aparece na resposta de validação.

## 31. `Functions` foi removida

`NeoFramework\Core\Functions` reunia comportamento sem relação entre si, incluindo
estado global, parsing de datas, strings, dinheiro e helpers brasileiros. A classe
foi removida; migre para as APIs focadas:

| Antes | Agora |
|---|---|
| `Functions::getRoot()` / `setRoot()` | `Support\ProjectRoot::path()` / `set()` |
| `Functions::getAbsolutePath()` | `Support\Path::normalizeRelative()` |
| `Functions::generateId()` | `Support\Id::randomHex()` |
| `Functions::isMobile()` | `Support\UserAgent::isMobile($userAgent)` |
| helpers de texto | `Support\Str` |
| helpers de data | `Support\Date::parse()`, `format()`, `localized()`, `toSqlDate()` e `toSqlDateTime()` |
| formatação monetária baseada em `float` | `Support\Money` (`ofMinor()` ou `fromDecimal()`) |

Os helpers específicos de Brasil (`CPF`, `CNPJ`, `CEP` e `DRE`) saíram do Core.
Instale o pacote opcional `diogodg/neoframework-br-support` e migre para
`NeoFramework\BrSupport\Cpf`, `Cnpj`, `Cep` e `Dre`. Validação de CPF/CNPJ
confere dígitos verificadores; CEP e DRE validam apenas a estrutura local.

## 32. Rate limiting distribuído e `ext-intl`

O Core agora exige `ext-intl` — `Support\Date::localized()` e `Support\Money::format()`
dependem dele. Imagens Alpine precisam também de `icu-data-full`: sem os dados de locale,
o `intl` compila mas formata tudo como `en_US` silenciosamente.

O rate limiting ganhou um domínio de configuração próprio, `Config/rate_limit.php`.
Aplicações que já usavam `#[RateLimit]` continuam funcionando sem criar o arquivo — os
defaults são `store=cache`, `strategy=fixed_window` e `on_failure=open`.

```php
return [
    'store'      => env('RATE_LIMIT_STORE') ?: 'cache',          // cache | redis
    'strategy'   => env('RATE_LIMIT_STRATEGY') ?: 'fixed_window', // fixed_window | sliding_window
    'on_failure' => env('RATE_LIMIT_ON_FAILURE') ?: 'open',       // open | closed
    'redis'      => ['host' => env('REDIS_HOST') ?: '', 'port' => 6379, 'password' => '', 'prefix' => 'neoframework:rl:', 'timeout' => 0.5],
    'policies'   => ['login' => ['limit' => 5, 'window' => 60, 'key' => 'ip']],
];
```

Quem serve tráfego com mais de um processo deve mudar para `store=redis`: o store de
cache incrementa em read-modify-write e dois workers conseguem ultrapassar o limite.
O store Redis faz incremento e expiração em um único script Lua.

`RateLimitStoreInterface` ganhou `read(string $key): int`, necessário para a janela
deslizante. Implementações de terceiros precisam adicionar o método.

`RateLimitPolicyRegistry::fromConfig()` passa a receber `Config\RateLimitConfig` em vez de
`ConfigRepositoryInterface`, e as políticas são validadas no bootstrap.

Duas correções acompanham a mudança:

- o store de cache — o binding **padrão** — passava a chave crua para o PSR-6, que recusa
  `{}()/\@:`. Como as chaves carregam path, IP e timestamp, toda requisição em rota
  limitada estourava `InvalidArgumentException`. A chave agora é normalizada por hash.
- `route:cache` não devolvia exit code em falha e aceitava `#[RateLimit(policy: '...')]`
  apontando para política inexistente, que só falharia ao servir a rota. Agora sai com
  código 1 nos dois casos.

## 33. Validação: atributos declaram, o motor decide

A Respect Validation continua sendo o motor, mas deixou de ser a superfície
pública. Antes, validar exigia que a aplicação importasse `Respect\Validation\Validator`
e construísse cadeias `v::` — o framework inteiro ficava acoplado à biblioteca.
Agora a regra é um atributo e a tradução acontece atrás de `ValidatorInterface`.

```php
#[Rule('between', [18, 120])] public int $age,
#[Rule('in', [['pix', 'boleto']], message: 'Forma de pagamento inválida.')] public string $payment,
```

`#[Rule]` alcança o catálogo inteiro. Antes disso, `#[Email]` e `#[Length]` eram as
duas únicas regras existentes, e adicionar uma terceira exigia editar o `DtoBinder`.

### O que muda para quem já usa

`#[Email]` e `#[Length]` continuam iguais, com as mesmas mensagens. As duas agora
implementam `Validation\ValidationRule` — se você escreveu um atributo de validação
próprio, ele precisa implementar esse contrato para o binder reconhecê-lo.

`Validator::getErrors()` passa a devolver `array<string, list<string>>` em vez de
`array<string, string>`, alinhado com `ValidationException::$errors` e com o
Problem Details. Código que fazia `echo $errors['email']` vira `$errors['email'][0]`.

`Validator::make()` deixou de aceitar cadeias `v::`. Cada campo recebe um nome de
regra, uma instância de `#[Rule]`, ou uma lista deles:

```php
// antes
(new Validator())->make($dados, ['email' => v::email(), 'senha' => v::stringType()->length(8, null)]);

// agora
(new Validator())->make($dados, ['email' => 'email', 'senha' => ['stringType', new Rule('length', [8, null])]]);
```

A tradução é mecânica: `v::foo(a, b)` vira `new Rule('foo', [a, b])`, e uma cadeia
de vários vira a lista. Manter as duas formas manteria a biblioteca na assinatura
pública — que é exatamente o acoplamento que esta mudança existe para remover.

### Duas correções

As mensagens do motor passam a receber o nome do campo explicitamente. Sem isso a
Respect usa **o próprio valor recebido** como rótulo da mensagem — numa regra como
`in`, a senha do usuário apareceria na resposta 422.

`Factory::setDefaultInstance()`, que publica `UniqueDb` e `ExistsDb` no catálogo,
saiu de `Kernel::init()` para `Application::boot()`. Só o caminho FPM passava por
`Kernel::init()`: sob FrankenPHP ou RoadRunner as regras de banco não existiam
pelo nome.

Ver `docs/VALIDATION.md`.

## 34. Uploads por stream e responses avançadas

### `DiskInterface` ganhou métodos

`writeStream()`, `readStream()` e `exists()` entraram no contrato. Implementações
de terceiros precisam adicioná-los.

### A nova API de upload

```php
$stored = $storage->storeUploadedFile(
    UploadedFiles::get($request, 'avatar'),
    directory: 'avatars',
    rules: UploadRules::images(maxBytes: 5_000_000),
);
```

Recebe `UploadedFileInterface` e devolve `StoredFile` (`path`, `storagePath`,
`size`, `mimeType`). `saveByPath()` e `saveFromRequest()` continuam existindo para
o código que usa `$_FILES`, mas carregam o arquivo inteiro em memória e confiam na
extensão do cliente — migre.

Duas diferenças de comportamento importam na migração:

- **a extensão gravada vem do MIME detectado**, não do nome enviado. Um PNG chamado
  `foto.jpeg` é gravado como `.png`;
- **um MIME que o Core não sabe nomear é recusado**, inclusive com
  `FileStorageType::ANY`. Se você aceitava tipos exóticos, passe a allowlist
  explícita em `UploadRules(mimeTypes: [...])` — e note que a extensão ainda precisa
  ser resolúvel.

Recusas agora são `UploadRejectedException`, que estende `InvalidArgumentException`
— quem já capturava o tipo antigo continua capturando.

### O emitter passou a enviar por blocos

`ResponseEmitter` fazia `echo (string) $response->getBody()`, materializando a
resposta inteira em memória. Streaming e download de arquivo grande não funcionavam
de fato: um vídeo de 2 GB exigia 2 GB de RAM antes do primeiro byte sair. Agora
envia em blocos de 8 KB e **não emite corpo** em 204, 304 e 1xx — se você dependia
de bytes saindo num 304, eles não saem mais.

### Novidades

`FileResponseFactory` (download, inline, `Range`/206/416, `If-Range`, ETag,
`Last-Modified`), `ConditionalRequestMiddleware` (304), `CacheControl` e `Cookie`
tipados, `StreamedResponseFactory` (stream, JSON stream, SSE) e `CallbackStream`.

`Cookie` valida na construção: `SameSite=None` sem `Secure` passa a ser erro. O
navegador descartava esse cookie em silêncio, e o sintoma era uma sessão que só
sumia em produção.

Ver `docs/UPLOADS.md` e `docs/RESPONSES.md`.

## 35. Estilo, matriz de dependências e observabilidade

### `composer test:all` passou a checar estilo

O script prometia um `test:style` que não existia. Agora roda PHP-CS-Fixer com as
regras de `.php-cs-fixer.dist.php`, versionadas no repositório. `composer style:fix`
aplica.

O conjunto é estreito de propósito: pega import morto, espaço no fim da linha,
`array()` e `declare` faltando — não impõe chaves em `if` de uma linha. Um preset
como `@PSR12` reescreveria milhares de linhas e enterraria qualquer diff de verdade.

A primeira execução normalizou 104 arquivos, incluindo 12 testes que não tinham
`declare(strict_types=1)`.

### CI roda `lowest` e `highest`

A matriz prova que os constraints do `composer.json` são verdade. Ela já rendeu:
`NeoFrameworkMigrationCommandsTest::noOptionIsRequired` recebia dois argumentos do
data provider e declarava um — silencioso no PHPUnit 12.1, warning no 12.5.

### Política de compatibilidade

`docs/COMPATIBILITY.md` define o que é público, o que quebra o quê, e o ciclo de
depreciação. Vale a partir da 2.x. Dois pontos que talvez surpreendam:

- **adicionar método a uma interface é major**, não minor: toda implementação de
  terceiro para de compilar;
- **mensagens de exceção e de log não são públicas** — o tipo da exceção é.

### Métricas e tracing

`MetricsExporterInterface` e `TracerInterface`, com `NullMetricsExporter` e
`NullTracer` como bindings padrão. Nada muda até a aplicação trocá-los em
`Config/container.php`.

`HttpMetricsListener` emite `http.server.requests`, `http.server.duration_ms` e
`http.server.exceptions` a partir dos eventos que já existiam. Registre-o em
`Config/events.php`.

O label de rota é o **padrão** (`/orders/{id}`), nunca o path resolvido — um label
de valor aberto cria uma série temporal por requisição.

Ver `docs/OBSERVABILITY.md`.

## 36. Router: URLs validadas, absolutas e assinadas

### `route()` recusa valor que a rota não casa

```php
route('users.show', ['id' => 'abc']);  // rota é {id:\d+}
// antes: "/users/abc" — um link que responde 404 e não avisa ninguém
// agora: InvalidArgumentException
```

Se alguma parte do seu código gerava URLs com valores fora da constraint, ela
passa a lançar. O link que ela produzia já estava quebrado; a diferença é que
agora você fica sabendo.

### Helpers novos

```php
route_url('orders.show', ['id' => 7]);                        // absoluta, exige app.url
signed_route('confirmar', ['token' => $t], expiresInSeconds: 3600);
```

A assinatura usa `crypto.authentication_key` e cobre path e query. Validação por
`UrlGeneratorFactory::signer()->isValid($request->getRequestTarget())`.

### `OPTIONS` é respondido pelo matcher

Uma rota que declara só `POST` agora responde `204` com `Allow: OPTIONS, POST` a
uma requisição `OPTIONS`, sem executar controller. **O header `Allow` de um 405
passou a incluir `OPTIONS`** — se você tem teste que compara a lista exata, ele
precisa do item novo.

Se a sua aplicação declarava `OPTIONS` manualmente numa action, ela continua
funcionando: uma rota declarada vence o comportamento automático.

### Prefixo de nome

```php
#[RoutePrefix('/api/v1', name: 'api.v1.')]
```

Concatenado cru, sem separador implícito. Rotas sem nome continuam sem nome.

### `route:cache` recusa rota inalcançável

Uma rota cujos paths são todos casados por outra declarada antes passa a ser erro
de compilação, com exit code 1. O critério é conservador: sobreposição parcial —
`{id:\d+}` antes de `{slug:[a-z]+}` — **não** é reportada, porque as duas são
alcançáveis.

Ver `docs/ROUTING.md`.

## 37. Eventos de fila, migração e autenticação — e escopo por job

### O worker ganhou escopo

`queue:work` é um processo que roda indefinidamente e não tinha escopo nenhum.
Cada job herdava o que o anterior tivesse deixado no escopo estático — o mesmo
vazamento que a Fase 3 fechou para requisições e nunca para filas.

Três coisas eram **silenciosamente inertes** dentro de um job:

- `Events::dispatch()` não chegava a listener nenhum;
- `Logger` não tinha contexto (sem job id, sem fila);
- `AuthContext::set()` gravava no vazio.

Agora `JobProcessor::processJob()` abre um `JobScope` por job, com `jobId`, `job`
e `queue` no contexto de log. Se você tem um job que dependia de estado deixado
pelo job anterior no mesmo worker, ele para de funcionar — e estava errado.

Um job despachado de dentro de uma requisição restaura o escopo dela ao terminar.

### A CLI também dispatcha

`bin/neof` abre um escopo para o processo inteiro, então qualquer comando pode
despachar eventos. É por isso que `migration:up` consegue emitir `MigrationApplied`.

### Eventos novos

| Evento | Carrega |
|---|---|
| `JobProcessing` | job, fila |
| `JobProcessed` | job, fila, `durationMs` |
| `JobFailed` | job, fila, exceção, `willRetry`, `durationMs` |
| `MigrationApplied` | tag, nº de statements, `dryRun`, `resumed` |
| `UserAuthenticated` | identidade, guard |
| `UserLoggedOut` | identidade (ou null), guard |

`willRetry` separa a falha que ainda tem tentativa da que desistiu — sem ela, um
alerta ligado a `JobFailed` dispararia em toda falha transitória.

Os eventos de auth carregam identidade e guard, **nunca a credencial**.

### `JobProcessor` aceita um dispatcher

```php
new JobProcessor($client, $dispatcher);
```

O segundo argumento é opcional; sem ele o worker roda exatamente como antes.

### `QueueMetricsListener`

Emite `queue.jobs.processed` e `queue.jobs.duration_ms` com labels `job`, `queue`
e `status` (`completed` / `retrying` / `failed`).

Ver `docs/EVENTS.md`.

## 38. Runtimes persistentes verificados no processo real

### O adapter do RoadRunner não tinha sessão

`SessionBridge` é novo e corrige um bug que só apareceu ao servir de verdade: o
RoadRunner não recompõe `$_COOKIE` nem coleta o `Set-Cookie` que
`session_start()` emite por `header()` — ele entrega um request PSR-7 e monta a
resposta a partir do objeto PSR-7. **Nenhum cookie de sessão saía, e todo request
começava do zero.**

A ponte também zera o id ao fim de cada requisição. Sem isso o worker manteria o
id do cliente anterior, e a requisição seguinte — de outra pessoa — abriria a
sessão dele. Um id vindo do cliente só é aceito no formato que o PHP gera;
qualquer outro seria vetor de fixação.

`RoadRunnerWorker` agora recebe um `SessionBridge` opcional no construtor. Quem
instanciava sem argumentos continua funcionando.

### O adapter do RoadRunner mudou de lugar

Saiu de um diretório irmão para `packages/roadrunner`, dentro do repositório do
Core. O pacote publicado continua sendo `diogodg/neoframework-roadrunner`; o que
muda é que agora ele está sob análise estática, style check e CI — fora do
repositório, o namespace não existia no checkout e a suíte abortava inteira ao
carregar o teste do adapter.

Colocá-lo sob PHPStan já apontou um `catch (\Throwable)` morto em volta de
`session_write_close()`.

### Novos requisitos de ambiente

`ext-sockets` entrou no Dockerfile e no CI — o SDK do RoadRunner fala com o
processo pai por socket.

### A suíte tem um grupo `runtime`

`vendor/bin/phpunit --group runtime` mede os critérios da §18 contra FrankenPHP e
RoadRunner servindo a aplicação-sonda de `tests/runtime`. Exige os dois servidores
de pé:

```bash
docker compose up -d php frankenphp roadrunner
docker compose exec php vendor/bin/phpunit --group runtime
```

O restante da suíte roda com `--exclude-group runtime`, que é o que o job
principal do CI faz.

Ver `docs/RUNTIMES.md`.

## 39. Bloqueio de força bruta no login

### `RateLimitStoreInterface` ganhou `forget()`

**Quebra implementações externas do store.** A assinatura nova é:

```php
public function forget(string $key): void;
```

Um login bem-sucedido precisa zerar o contador de falhas — sem isso, errar quatro
vezes antes de acertar deixaria o próximo login legítimo a uma falha do bloqueio.
Os quatro stores do Core já implementam. Uma implementação própria só precisa
apagar a chave; apagar uma que nunca existiu não é erro.

### `LoginThrottle`

`#[RateLimit]` conta requisições. Isso não protege um login, por dois motivos:
cobra o orçamento de quem acertou a senha, e não sabe qual conta está sendo
atacada — um atacante distribuído por muitos IPs varre uma conta sem estourar
contador nenhum.

`LoginThrottle` conta **falhas**, em duas dimensões (identificador e IP), e zera as
duas no sucesso. A verificação da credencial é passada como callable porque a
sequência checar → verificar → contar a falha → zerar no sucesso não pode ser
cumprida pela metade:

```php
$identity = $throttle->attempt($email, $this->request, fn () => $this->verify($email, $password));
```

Enquanto bloqueado, o callable não é chamado — não se chega ao `password_verify`.

### Novos padrões de configuração

- `auth.login_throttle` = `['limit' => 5, 'window' => 900]`. Ligado por padrão:
  deixá-lo desligado faria a proteção depender de o projeto lembrar de configurá-la.
- A política `login` (5 por 900s, por IP) passou a vir declarada em
  `rate_limit.policies`. O exemplo da documentação agora funciona sem configuração,
  e `#[RateLimit(policy: 'login')]` deixa de reprovar em `route:cache`.

Se o seu `Config/rate_limit.php` já declarava `login`, os campos que você informa
continuam vencendo — a configuração do projeto é mesclada sobre os padrões.

### Novos eventos

`LoginFailed` e `LoginThrottled`. Nenhum dos dois carrega a senha; ambos carregam o
identificador tentado, que é o que torna a auditoria capaz de distinguir erro de
digitação de ataque dirigido.

Ver `docs/AUTH.md`.

## 40. Configuração, rotas, listeners e DTOs compiláveis

Duas coisas eram relidas e reinterpretadas em toda requisição de produção.

### `neof event:cache`

`Config/events.php` era incluído e revalidado por requisição — `class_exists` e
`method_exists` por listener. Agora compila para `Cache/events.php`.

Um listener registrado como closure reprova a compilação em vez de sumir dela.
Se você registra listeners por closure em `Config/events.php`, troque por
class-string ou não compile.

`neof event:list` passou a ler pelo mesmo caminho do runtime e informa a origem.

### `neof dto:cache`

O bind de DTO fazia uma varredura de atributos por parâmetro, com uma
instanciação por atributo. Isso virou um plano por classe (`DtoMetadata`),
compilável para `Cache/dto.php`.

**Quebra quem chamava `InputSources::valueFor()` diretamente.** A assinatura
deixou de aceitar `ReflectionParameter` e passou a aceitar `DtoParameter`, que é
o plano já resolvido — ler o atributo ali dentro era metade do custo que a
compilação existe para eliminar.

Os dois comandos são opcionais: sem eles tudo continua funcionando por
Reflection. Num deploy, rode-os junto com `config:cache` e `route:cache`.

## 41. Kit de testes: fakes e transação por teste

### `MailerInterface`

`Email` passou a implementar `NeoFramework\Core\Mail\MailerInterface`, e o
container resolve a interface para ele. Os métodos fluentes agora declaram
`static` em vez de `Email` como retorno — quem estendia `Email` e sobrescrevia
`addEmail`, `addEmailCc`, `addEmailBcc` ou `setFrom` precisa acompanhar a
assinatura. `send()` ganhou tipos explícitos (`string, string, bool`).

O motivo é poder afirmar "este e-mail foi enviado, com este assunto, para este
destinatário" sem abrir SMTP — a alternativa é um servidor de verdade, capaz de
mandar mensagem para gente real a partir de uma suíte.

### `Cache::swap()`

Instala um pool no lugar do configurado; `null` restaura. `Testing\FakeCache`
embrulha isso num pool em memória.

### Fakes

`FakeQueue`, `FakeMailer`, `FakeDispatcher` e `FakeCache` entraram em
`NeoFramework\Core\Testing`, cada um com asserções próprias. Ver `docs/TESTING.md`.

### `DatabaseTransactions`

Trait que abre uma transação no `setUp` e a desfaz no `tearDown`. Exige uma
conexão configurada — o CI agora sobe um Postgres para exercitá-la.

## 42. Pacotes opcionais e preparação para 3.0

Os adapters opcionais agora possuem pacote Composer próprio:

- `diogodg/neoframework-roadrunner`;
- `diogodg/neoframework-opentelemetry`;
- `diogodg/neoframework-image-gd`;
- `diogodg/neoframework-br-support`.

O Core não passa a requerer RoadRunner, OTel, GD, EXIF ou helpers brasileiros.
Instale apenas o pacote de que sua aplicação necessita. O adapter GD sempre
retorna WebP e requer `ext-gd` e `ext-exif`; trate isso como mudança de formato
ao adotá-lo.

Esta separação fecha a preparação da 3.0: integrações de ambiente e domínio
deixam de ser APIs implícitas do Core. A 3.0 poderá remover somente APIs já
marcadas como deprecated em uma minor, sem introduzir nova dependência
obrigatória ou estado compartilhado entre requisições.
