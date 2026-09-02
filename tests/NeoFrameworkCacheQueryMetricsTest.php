<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Cache;
use NeoFramework\Core\Events\CacheAccessed;
use NeoFramework\Core\Events\EventDispatcher;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\ListenerProvider;
use NeoFramework\Core\Events\QueryExecuted;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Observability\CacheMetricsListener;
use NeoFramework\Core\Observability\InMemoryMetricsExporter;
use NeoFramework\Core\Observability\InstrumentedPdo;
use NeoFramework\Core\Observability\QueryMetricsListener;
use NeoFramework\Core\Testing\FakeCache;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkCacheQueryMetricsTest extends TestCase
{
    private ?RequestScope $scope = null;

    private function listening(array $map): InMemoryMetricsExporter
    {
        $metrics = new InMemoryMetricsExporter();
        $provider = new ListenerProvider();
        foreach ($map as $event => $listener) $provider->on($event, $listener($metrics));

        $this->scope = new RequestScope();
        $this->scope->set(Events::KEY, new EventDispatcher($provider));
        RequestScopeContext::enter($this->scope);

        return $metrics;
    }

    /**
     * Conta amostras de um contador por rótulo, no formato "chave=valor".
     *
     * @return array<string,int>
     */
    private function counted(InMemoryMetricsExporter $metrics, string $name): array
    {
        $counts = [];
        foreach ($metrics->samples($name) as $sample) {
            if ($sample['type'] !== 'counter') continue;
            $key = implode('|', array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($sample['labels']), $sample['labels']));
            $counts[$key] = ($counts[$key] ?? 0) + (int) $sample['value'];
        }

        return $counts;
    }

    protected function tearDown(): void
    {
        if ($this->scope !== null) RequestScopeContext::leave($this->scope);
        $this->scope = null;
        FakeCache::uninstall();
    }

    public function testCacheHitAndMissShareOneCounterWithAResultLabel(): void
    {
        $metrics = $this->listening([CacheAccessed::class => static fn ($m) => new CacheMetricsListener($m)]);
        FakeCache::install();

        $cache = new Cache();
        $cache->getItem('ausente');

        $item = $cache->getItem('presente');
        $item->set('x');
        $cache->save($item);
        $cache->getItem('presente');

        $counters = $this->counted($metrics, CacheMetricsListener::ACCESSES);
        // Acerto e erro só significam algo um em relação ao outro: precisam sair
        // do mesmo contador, ou alguém coleta só o acerto e conclui 100%.
        self::assertSame(2, $counters['result=miss'] ?? null);
        self::assertSame(1, $counters['result=hit'] ?? null);
    }

    public function testTheCacheKeyNeverBecomesALabel(): void
    {
        $metrics = $this->listening([CacheAccessed::class => static fn ($m) => new CacheMetricsListener($m)]);
        FakeCache::install();

        // PSR-6 recusa ":" numa chave, então o identificador vai sem separador.
        (new Cache())->getItem('usuario_42_perfil');

        // Chave como label é cardinalidade ilimitada — derruba o coletor.
        foreach (array_keys($this->counted($metrics, CacheMetricsListener::ACCESSES)) as $key) self::assertStringNotContainsString('usuario', $key);
    }

    public function testQueryMetricsLabelTheOperationNotTheSql(): void
    {
        $metrics = new InMemoryMetricsExporter();
        $listener = new QueryMetricsListener($metrics);

        $listener(new QueryExecuted('SELECT * FROM users WHERE id = ?', 3.5, 'principal'));
        $listener(new QueryExecuted('  insert into users (a) values (?)', 1.0, 'principal'));
        $listener(new QueryExecuted('VACUUM', 9.0));

        $counters = $this->counted($metrics, QueryMetricsListener::EXECUTED);
        self::assertSame(1, $counters['operation=select|connection=principal'] ?? null);
        self::assertSame(1, $counters['operation=insert|connection=principal'] ?? null);
        // O que não é CRUD vira `other` em vez de virar uma série nova.
        self::assertSame(1, $counters['operation=other'] ?? null);

        foreach (array_keys($counters) as $key) self::assertStringNotContainsString('users', $key);
    }

    public function testTheInstrumentedPdoDispatchesForDirectAndPreparedQueries(): void
    {
        $metrics = $this->listening([QueryExecuted::class => static fn ($m) => new QueryMetricsListener($m)]);

        $pdo = new InstrumentedPdo(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DBHOST') ?: 'postgres', getenv('DBPORT') ?: '5432', getenv('DBNAME') ?: 'neoorm_test'),
            getenv('DBUSER') ?: 'postgres',
            getenv('DBPASSWORD') ?: 'postgres',
            connection: 'testes',
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->beginTransaction();

        try {
            $pdo->exec('CREATE TEMP TABLE metrics_probe (id int)');

            $insert = $pdo->prepare('INSERT INTO metrics_probe (id) VALUES (?)');
            // A mesma statement executada três vezes tem de contar três: medir o
            // `prepare` contaria uma consulta que nem tocou o banco.
            foreach ([1, 2, 3] as $id) $insert->execute([$id]);

            $pdo->query('SELECT count(*) FROM metrics_probe');
        } finally {
            $pdo->rollBack();
        }

        $counters = $this->counted($metrics, QueryMetricsListener::EXECUTED);
        self::assertSame(3, $counters['operation=insert|connection=testes'] ?? null);
        self::assertSame(1, $counters['operation=select|connection=testes'] ?? null);
    }

    public function testAFailedQueryIsStillMeasured(): void
    {
        $metrics = $this->listening([QueryExecuted::class => static fn ($m) => new QueryMetricsListener($m)]);

        $pdo = new InstrumentedPdo(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DBHOST') ?: 'postgres', getenv('DBPORT') ?: '5432', getenv('DBNAME') ?: 'neoorm_test'),
            getenv('DBUSER') ?: 'postgres',
            getenv('DBPASSWORD') ?: 'postgres',
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        try {
            $pdo->query('SELECT * FROM tabela_que_nao_existe');
        } catch (\PDOException) {
        }

        // Uma consulta que estourou também consumiu tempo de banco, e costuma
        // ser a mais lenta de todas.
        self::assertSame(1, $this->counted($metrics, QueryMetricsListener::EXECUTED)['operation=select'] ?? null);
    }
}
