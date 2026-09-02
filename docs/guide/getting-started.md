# Comece aqui

O NeoFramework exige PHP 8.4 e Composer. O caminho mais direto é usar o [skeleton da aplicação](https://github.com/DiogoGraciano/NeoFramework), que já organiza `App/`, `Config/`, `public/` e os comandos locais.

## Criar a aplicação

Clone o skeleton e instale as dependências:

```bash
git clone https://github.com/DiogoGraciano/NeoFramework meu-projeto
cd meu-projeto
composer install
cp .env.example .env
```

O arquivo `.env` contém apenas valores específicos do ambiente. Versione os arquivos PHP em `Config/`; nunca os segredos.

## Servir localmente

O ponto de entrada público é `public/index.php`. Para começar sem servidor web configurado:

```bash
php -S localhost:8000 -t public
```

Abra `http://localhost:8000`. Em produção, configure o servidor para que **somente** `public/` seja acessível; `App/`, `Config/`, `vendor/` e `.env` não devem ser servidos.

## Criar a primeira rota

Crie um controller em `App/Controllers/HealthController.php`:

```php
<?php

namespace App\Controllers;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Response;

final class HealthController extends Controller
{
    #[Route('/health', ['GET'], name: 'health')]
    public function show(): Response
    {
        return $this->json(['status' => 'ok']);
    }
}
```

Os controllers são descobertos pelas rotas declaradas com atributos. Ao acessar `/health`, a aplicação retorna JSON sem que a action precise emitir headers ou chamar `exit`.

## Verificar a instalação

A CLI vem como `neof` na raiz do projeto. Use-a para consultar o estado do ambiente e a tabela de rotas:

```bash
php neof about
php neof route:list
php neof doctor
```

Antes de publicar, compile os metadados que não mudam dentro do deploy:

```bash
php neof config:cache
php neof route:cache
php neof event:cache
php neof dto:cache
```

Cada comando possui a versão `:clear`. Veja [configuração](/guide/configuration) para entender quando compilar e [runtimes](/RUNTIMES) para workers persistentes.

## Próximos passos

1. Entenda a [estrutura do projeto](/guide/project-structure).
2. Crie endpoints com [controllers e respostas](/guide/controllers).
3. Receba dados tipados com [DTOs e validação](/VALIDATION).
