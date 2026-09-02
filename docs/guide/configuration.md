# Configuração

Configuração é código PHP versionado em `Config/`, com segredos e diferenças de ambiente no `.env`. O bootstrap valida a forma dos valores; uma chave malformada falha cedo, antes de atender uma requisição.

## Arquivos principais

| Arquivo | Controla |
|---|---|
| `app.php` | ambiente e URL canônica da aplicação |
| `http.php` | `base_path`, proxies e hosts confiáveis |
| `database.php` | driver de banco usado pela aplicação |
| `cache.php` | filesystem, Redis ou Memcached |
| `session.php` | cookies de sessão |
| `cors.php` | origens, métodos e headers CORS |
| `security_headers.php` | headers de proteção e ajuste para Vite |
| `mail.php` | SMTP e remetente |
| `queue.php` | driver de filas, locks e TTL |
| `storage.php` | disco local ou S3 |
| `rate_limit.php` | políticas, store e estratégia de limite |
| `events.php` | listeners por evento |
| `container.php` | bindings próprios da aplicação |
| `template.php` e `vite*.php` | templates e assets |

## Ambiente

Leia variáveis somente dentro de `Config/`. Isso mantém a decisão de ambiente centralizada e permite descobrir todos os inputs externos de uma aplicação olhando uma única pasta.

```php
// Config/app.php
return [
    'environment' => env('ENVIRONMENT', 'dev'),
    'url' => env('APP_URL', ''),
];
```

`app.url` precisa de uma URL absoluta para gerar links absolutos com `route_url()`. Configurar o host a partir da requisição é inseguro atrás de proxies e pode vazar domínios indevidos.

## Container da aplicação

`Config/container.php` registra implementações de contratos que pertencem ao seu domínio ou a integrações externas:

```php
use App\Auth\DatabaseTokenRepository;
use App\Auth\UserProvider;
use NeoFramework\Core\Auth\{TokenRepositoryInterface, UserProviderInterface};

return [
    UserProviderInterface::class => new UserProvider(),
    TokenRepositoryInterface::class => new DatabaseTokenRepository(),
];
```

O Core fornece defaults seguros para recursos opcionais. Troque uma interface pelo adapter real apenas quando o projeto decidir usar esse recurso. [Autenticação](/AUTH) e [observabilidade](/OBSERVABILITY) mostram bindings completos.

## Cache de metadados

Em desenvolvimento, os arquivos podem ser relidos para facilitar mudanças. Em produção, compile os quatro mapas depois de instalar dependências e antes de subir processos:

```bash
php neof config:cache
php neof route:cache
php neof event:cache
php neof dto:cache
```

Repita a compilação em todo deploy que altere código, `Config/` ou variáveis de ambiente. Não transporte um `Cache/` compilado de outro ambiente.

> [!WARNING]
> Cache de configuração não é uma forma de esconder segredo. Continue excluindo `.env`, chaves privadas e tokens do repositório, das imagens e dos logs.
