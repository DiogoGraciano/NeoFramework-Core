<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Transaction\TransactionRegistry;
use Diogodg\Neoorm\Transaction\Transactions;

/**
 * Isola cada teste numa transação desfeita no fim.
 *
 * A alternativa usual é truncar as tabelas entre testes, o que é lento e,
 * quando alguém esquece uma tabela nova, produz o pior tipo de falha: um teste
 * que passa sozinho e falha na suíte, ou o contrário, dependendo da ordem.
 *
 * O `rollBack` acontece mesmo quando o teste falha — é `tearDown`, não o fim do
 * método de teste. Sem isso a primeira falha deixaria dados para trás e
 * derrubaria os casos seguintes por um motivo que não é o deles.
 */
trait DatabaseTransactions
{
    private ?Transactions $databaseTransaction = null;

    protected function beginDatabaseTransaction(): void
    {
        // O driver vem da MESMA fonte que `Connection::getConnection()` usa.
        // Ler o `database.driver` do framework abriria a porta para a transação
        // ser criada com um dialeto diferente do da conexão de verdade.
        $config = DatabaseConfig::fromConfig();

        $this->databaseTransaction = TransactionRegistry::for(
            Connection::getConnection(),
            DialectFactory::for($config->driver, $config->schema ?: null),
        );
        $this->databaseTransaction->begin();
    }

    protected function rollBackDatabaseTransaction(): void
    {
        if ($this->databaseTransaction === null) return;

        // Só desfaz o que ainda está aberto: um teste que commitou de propósito
        // não pode fazer o tearDown estourar por transação inexistente.
        if ($this->databaseTransaction->inTransaction()) $this->databaseTransaction->rollBack();

        $this->databaseTransaction = null;
        TransactionRegistry::flush(Connection::getConnection());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beginDatabaseTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackDatabaseTransaction();
        parent::tearDown();
    }
}
