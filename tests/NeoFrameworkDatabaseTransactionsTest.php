<?php
declare(strict_types=1);

namespace Tests;

use Diogodg\Neoorm\Connection;
use NeoFramework\Core\Testing\DatabaseTransactions;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkDatabaseTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    public function testTheTestBodyRunsInsideAnOpenTransaction(): void
    {
        self::assertTrue(Connection::getConnection()->inTransaction());
    }

    public function testWorkDoneInTheTestIsUndoneByTheRollback(): void
    {
        $pdo = Connection::getConnection();
        $table = 'tx_probe_' . bin2hex(random_bytes(4));

        // Postgres tem DDL transacional, então a própria criação da tabela
        // desaparece no rollback — não sobra resíduo nem se este teste falhar.
        $pdo->exec("CREATE TABLE {$table} (id int)");
        $pdo->exec("INSERT INTO {$table} (id) VALUES (1)");
        self::assertSame(1, (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn());

        // Fecha e reabre o ciclo dentro do próprio teste: assim a prova não
        // depende da ordem de execução entre casos, que é aleatória no CI.
        $this->rollBackDatabaseTransaction();
        $this->beginDatabaseTransaction();

        $exists = $pdo->query("SELECT to_regclass('{$table}')")->fetchColumn();
        self::assertNull($exists, 'A tabela sobreviveu ao rollback.');
    }

    public function testRollingBackTwiceIsNotAnError(): void
    {
        // O tearDown roda sempre; se ele estourasse por transação já fechada,
        // um teste que commitou de propósito derrubaria o relatório inteiro.
        $this->rollBackDatabaseTransaction();
        $this->rollBackDatabaseTransaction();

        $this->beginDatabaseTransaction();
        self::assertTrue(Connection::getConnection()->inTransaction());
    }
}
