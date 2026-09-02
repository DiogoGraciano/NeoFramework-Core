# Banco de dados e NeoORM

O NeoFramework Core não acopla seu modelo de dados a um ORM. Ele usa contratos e PDO nos limites, o que permite integrar a persistência que fizer sentido para a aplicação. O pacote oficial [NeoORM](https://github.com/DiogoGraciano/NeoORM) fornece schema, migrações, query builder tipado e geração de tabelas/rows para MySQL e PostgreSQL.

## Configurar o driver

O skeleton lê o driver em `Config/database.php`:

```php
return ['driver' => env('DRIVER', '')];
```

Defina no `.env` os dados de conexão esperados pelo projeto e mantenha o driver coerente em desenvolvimento, testes e produção. O NeoORM possui suítes de integração separadas para MySQL e PostgreSQL justamente para que uma query não seja “portável” só na teoria.

## Migrações

Use a CLI da aplicação para inspecionar e aplicar migrações antes de subir uma versão que depende de schema novo:

```bash
php neof migration:status
php neof migration:up
```

Trate a migração como etapa explícita do deploy. O processo web não deve tentar alterar schema ao atender a primeira requisição; isso concorre com workers e torna uma falha de banco uma indisponibilidade de HTTP.

## Acesso a dados

Mantenha acesso a banco em repositórios ou serviços do domínio e injete-os no controller. Assim a action orquestra HTTP, enquanto testes de negócio podem trabalhar sem servidor:

```php
final class ProductService
{
    public function find(int $id): Product
    {
        // consulta NeoORM/PDO e regra de domínio
    }
}
```

Use transações onde há uma unidade de alteração indivisível. Nos testes, o trait `DatabaseTransactions` abre uma transação por caso e a desfaz até quando uma asserção falha; veja [testes](/TESTING).

## NeoORM em detalhe

A documentação do Core evita duplicar o manual do ORM. Consulte o [README do NeoORM](https://github.com/DiogoGraciano/NeoORM) para schema, query builder, codegen e suporte aos dialetos.
