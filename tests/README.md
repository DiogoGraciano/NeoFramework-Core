# NeoFramework Core Tests

Este diretório contém os testes unitários e de integração para o NeoFramework Core.

## Estrutura dos Testes

### Arquivos de Teste

- **NeoFrameworkRequestTest.php** - Testa a classe `Request` e suas funcionalidades
- **NeoFrameworkResponseTest.php** - Testa a classe `Response` e suas funcionalidades  
- **NeoFrameworkControllerTest.php** - Testa a classe abstrata `Controller`
- **NeoFrameworkRouterTest.php** - Testa a classe `Router` e roteamento
- **NeoFrameworkJobsTest.php** - Testa o sistema de Jobs (filas, processamento, etc.)

### Classes Auxiliares

- **JobsClass/TestJob.php** - Job de teste que executa com sucesso
- **JobsClass/FailingJob.php** - Job de teste que falha propositalmente

## Pré-requisitos

### Dependências Obrigatórias

- PHP 8.0 ou superior
- Composer
- PHPUnit 9.0 ou superior

### Dependências Opcionais (para testes completos)

- Redis (para testes de Jobs com driver Redis)
- PostgreSQL (para testes de banco de dados)

## Instalação

1. Instale as dependências do Composer:
```bash
composer install
```

2. Configure as variáveis de ambiente no arquivo `phpunit.xml` conforme necessário.

## Executando os Testes

### Executar Todos os Testes

```bash
# Executar todos os testes
./vendor/bin/phpunit

# Ou usando o comando do framework
php bin/neof te
```

### Executar Testes Específicos

```bash
# Executar apenas testes de Request
./vendor/bin/phpunit --testsuite "Request Tests"

# Executar apenas testes de Response
./vendor/bin/phpunit --testsuite "Response Tests"

# Executar apenas testes de Controller
./vendor/bin/phpunit --testsuite "Controller Tests"

# Executar apenas testes de Router
./vendor/bin/phpunit --testsuite "Router Tests"

# Executar apenas testes de Jobs
./vendor/bin/phpunit --testsuite "Jobs Tests"
```

### Executar Teste Individual

```bash
# Executar um arquivo de teste específico
./vendor/bin/phpunit tests/NeoFrameworkRequestTest.php

# Executar um método de teste específico
./vendor/bin/phpunit --filter testGetMethod tests/NeoFrameworkRequestTest.php
```

### Executar com Cobertura de Código

```bash
# Gerar relatório de cobertura em HTML
./vendor/bin/phpunit --coverage-html coverage-html

# Gerar relatório de cobertura em texto
./vendor/bin/phpunit --coverage-text

# Gerar relatório de cobertura em XML (para CI/CD)
./vendor/bin/phpunit --coverage-clover coverage.xml
```

## Configuração de Ambiente

### Variáveis de Ambiente para Testes

As seguintes variáveis são configuradas automaticamente no `phpunit.xml`:

```xml
<!-- Ambiente de Teste -->
<env name="ENVIRONMENT" value="test"/>

<!-- Configuração de Jobs -->
<env name="JOBS_STORAGE_PATH" value="Jobs_Test"/>
<env name="QUEUE_DRIVER" value="files"/>

<!-- Configuração Redis (opcional) -->
<env name="REDIS_HOST" value="redis"/>
<env name="REDIS_PORT" value="6379"/>
<env name="REDIS_PASSWORD" value="RedisPass"/>

<!-- Configuração de Banco (opcional) -->
<env name="DRIVER" value="pgsql"/>
<env name="DBHOST" value="postgres"/>
<env name="DBPORT" value="5432"/>
<env name="DBNAME" value="neoorm_test"/>
```

### Configuração para Docker

Se estiver usando Docker, certifique-se de que os serviços Redis e PostgreSQL estejam rodando:

```yaml
# docker-compose.yml
version: '3.8'
services:
  redis:
    image: redis:alpine
    ports:
      - "6379:6379"
    command: redis-server --requirepass RedisPass
  
  postgres:
    image: postgres:13
    environment:
      POSTGRES_DB: neoorm_test
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
    ports:
      - "5432:5432"
```

## Cobertura dos Testes

### Request Tests
- ✅ Métodos GET, POST, COOKIE, SERVER
- ✅ Sanitização de dados
- ✅ Manipulação de headers
- ✅ Processamento de arquivos
- ✅ Tokens CSRF
- ✅ Corpo da requisição (JSON, XML)
- ✅ Detecção de requisições AJAX

### Response Tests
- ✅ Códigos de status HTTP
- ✅ Manipulação de headers
- ✅ Cookies (criação, exclusão)
- ✅ Redirecionamentos
- ✅ Conteúdo (string, array, objeto)
- ✅ Content-Type e expiração
- ✅ Method chaining

### Controller Tests
- ✅ Injeção de dependências
- ✅ Paginação (offset, limit)
- ✅ Detecção de dispositivos móveis
- ✅ Propriedades readonly
- ✅ Validação de CSRF token
- ✅ Method chaining

### Router Tests
- ✅ Roteamento básico
- ✅ Parâmetros de rota (numéricos, strings, regex)
- ✅ Parâmetros opcionais
- ✅ Validação de parâmetros
- ✅ Detecção de home
- ✅ Middlewares globais
- ✅ Route rewrite

### Jobs Tests
- ✅ Entidade JobEntity
- ✅ Drivers (Files, Redis)
- ✅ Enfileiramento e desenfileiramento
- ✅ Jobs agendados
- ✅ Processamento de jobs
- ✅ Retry e falhas
- ✅ Locks para concorrência
- ✅ Queue Manager
- ✅ Integração com Abstract Job

## Troubleshooting

### Problemas Comuns

1. **Erro de conexão Redis**
   ```
   Could not connect to driver: Failed to connect to Redis
   ```
   - Verifique se o Redis está rodando
   - Confirme as credenciais no `phpunit.xml`

2. **Erro de permissão em arquivos**
   ```
   Failed to create directory: Jobs_Test
   ```
   - Verifique as permissões do diretório de trabalho
   - Execute com `sudo` se necessário (não recomendado)

3. **Testes pulados (skipped)**
   ```
   Could not connect to driver: ...
   ```
   - Isso é normal quando dependências opcionais não estão disponíveis
   - Os testes continuarão com mocks

### Debug de Testes

Para debugar testes específicos:

```bash
# Executar com verbose máximo
./vendor/bin/phpunit --verbose --debug

# Executar sem capturar output
./vendor/bin/phpunit --no-coverage --stop-on-failure

# Executar com informações de memória
./vendor/bin/phpunit --verbose --log-junit tests-junit.xml
```

## Contribuindo

### Adicionando Novos Testes

1. Crie um novo arquivo de teste seguindo o padrão `NeoFramework{Component}Test.php`
2. Estenda `PHPUnit\Framework\TestCase`
3. Implemente `setUp()` e `tearDown()` para limpar estado
4. Use nomes descritivos para métodos de teste
5. Adicione o teste suite no `phpunit.xml`

### Boas Práticas

- ✅ Sempre limpe superglobals no `setUp()` e `tearDown()`
- ✅ Use mocks para dependências externas
- ✅ Teste casos de sucesso e falha
- ✅ Use `markTestSkipped()` quando dependências não estão disponíveis
- ✅ Documente testes complexos
- ✅ Mantenha testes independentes entre si

### Exemplo de Teste

```php
<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NeoFramework\Core\YourClass;

class YourClassTest extends TestCase
{
    private YourClass $instance;

    protected function setUp(): void
    {
        parent::setUp();
        // Limpar estado global
        $_GET = [];
        $_POST = [];
        
        $this->instance = new YourClass();
    }

    public function testMethodDoesWhatExpected(): void
    {
        // Arrange
        $input = 'test input';
        
        // Act
        $result = $this->instance->method($input);
        
        // Assert
        $this->assertEquals('expected output', $result);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Limpar estado global
        $_GET = [];
        $_POST = [];
    }
}
```

## Relatórios

Os testes geram os seguintes relatórios:

- `coverage-html/` - Relatório de cobertura em HTML
- `coverage.txt` - Relatório de cobertura em texto
- `coverage.xml` - Relatório de cobertura em XML (Clover)
- `tests-junit.xml` - Relatório JUnit para CI/CD

Estes arquivos são ignorados pelo Git e devem ser gerados localmente ou em pipelines de CI/CD. 