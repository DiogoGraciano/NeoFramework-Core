# Estrutura do projeto

O Core é uma biblioteca; o skeleton é onde a sua aplicação mora. Separar os dois preserva upgrades simples e evita que regras de negócio sejam misturadas ao framework.

```text
meu-projeto/
├── App/                 # controllers, serviços, modelos, views e código do domínio
├── Config/              # configuração versionada por área
├── Migrations/          # migrações da base de dados
├── Tests/               # testes da aplicação
├── public/              # única raiz exposta pelo servidor web
├── resources/           # fontes CSS/JS e outros recursos de build
├── Cache/               # artefatos gerados; não versionar
├── Logs/                # saída local; não versionar
├── vendor/              # dependências do Composer
├── .env                 # segredos e valores do ambiente; não versionar
└── neof                 # CLI do projeto
```

## Onde cada decisão fica

| Necessidade | Lugar certo |
|---|---|
| Endpoint HTTP | `App/Controllers` |
| Regra de negócio reutilizável | `App/Services` ou um módulo de domínio |
| Contrato de entrada | `App/Requests` |
| Middleware da aplicação | `App/Middleware` |
| Template e layout | `App/View` |
| Ajuste por ambiente | `.env` |
| Default, forma e integração | `Config/*.php` |
| Arquivo web acessível | `public/` |

O diretório `Cache/` é descartável: rotas, DTOs, eventos e configuração podem ser regenerados. Não edite seus arquivos manualmente nem dependa do formato deles.

## Ponto de entrada

`public/index.php` carrega o autoloader, define a raiz do projeto e inicia o kernel. Essa borda é deliberadamente pequena: o `HttpKernel` processa uma requisição e devolve uma resposta; o emissor é a única camada que envia output ao PHP.

```php
use NeoFramework\Core\Kernel;
use NeoFramework\Core\Support\ProjectRoot;

ProjectRoot::set(dirname(__DIR__));
Kernel::init();
```

Para PHP-FPM, Nginx ou Apache devem encaminhar URLs inexistentes para esse arquivo, mantendo os arquivos estáticos em `public/`. Para configurações de worker, consulte [runtimes persistentes](/RUNTIMES).

## Geração de código

Use a CLI para manter os namespaces e a organização inicial consistentes:

```bash
php neof make:controller Products
php neof make:request CreateProduct
php neof make:middleware ApiAuthentication
php neof make:job SendReceipt
php neof make:test ProductsController
```

Nomes aninhados aceitam `/`, por exemplo `Admin/Products`. Os geradores não sobrescrevem arquivos: passe `--force` apenas quando essa for uma decisão consciente. `stubs:publish` copia os stubs para edição no projeto.
