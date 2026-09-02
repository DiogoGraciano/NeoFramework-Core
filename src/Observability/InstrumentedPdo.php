<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\QueryExecuted;
use PDO;
use PDOStatement;

/**
 * PDO que mede o que executa e despacha `QueryExecuted`.
 *
 * É opt-in porque o Core não constrói a conexão — a NeoORM tem o singleton
 * dela, e nenhuma biblioteca deve instrumentar por baixo de outra sem ser
 * pedida. Uma aplicação que queira as métricas de query embrulha a própria:
 *
 * ```php
 * return [PDO::class => fn () => new InstrumentedPdo($dsn, $user, $senha, connection: 'principal')];
 * ```
 *
 * Estende `PDO` em vez de decorar por composição porque quem recebe a conexão
 * declara `PDO` no type hint; um decorador seria recusado ali.
 */
final class InstrumentedPdo extends PDO
{
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        private readonly ?string $connection = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);

        // As statements preparadas nascem já instrumentadas: é o PDO que as
        // cria, então não há onde envolvê-las depois.
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InstrumentedStatement::class, [$this->connection]]);
    }

    public function exec(string $statement): int|false
    {
        return $this->measure($statement, fn (): int|false => parent::exec($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        // `query` executa na hora e não passa por `execute()`, então medir aqui
        // não duplica a contagem das preparadas.
        return $this->measure($query, fn (): PDOStatement|false => $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs));
    }

    /**
     * @template T
     * @param callable():T $run
     * @return T
     */
    private function measure(string $sql, callable $run): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $run();
        } finally {
            Events::dispatch(new QueryExecuted($sql, (hrtime(true) - $startedAt) / 1_000_000, $this->connection));
        }
    }
}
