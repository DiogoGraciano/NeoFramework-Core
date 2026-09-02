<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use PHPUnit\Framework\Assert;

/**
 * Fila em memória com asserções.
 *
 * Um teste que enfileira de verdade depende do driver de arquivos ou do Redis,
 * fica lento e passa a falhar por causa de resto de execução anterior. O que o
 * teste quer afirmar é quase sempre "este job foi enfileirado com estes
 * argumentos", e isso não precisa de backend nenhum.
 */
final class FakeQueue implements Client
{
    /** @var list<array{job:JobEntity,queue:string}> */
    private array $pushed = [];

    /** @var list<string> */
    private array $locked = [];

    public function enqueue(JobEntity $job, string $queue = 'default'): bool
    {
        $this->pushed[] = ['job' => $job, 'queue' => $queue];

        return true;
    }

    public function scheduleJob(JobEntity $job, string $queue = 'default'): bool
    {
        return $this->enqueue($job, $queue);
    }

    public function dequeue(string $queue = 'default'): ?JobEntity
    {
        foreach ($this->pushed as $index => $entry) {
            if ($entry['queue'] !== $queue) continue;
            unset($this->pushed[$index]);
            $this->pushed = array_values($this->pushed);

            return $entry['job'];
        }

        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->jobsOn($queue));
    }

    public function clear(string $queue = 'default'): int
    {
        $removed = $this->size($queue);
        $this->pushed = array_values(array_filter($this->pushed, static fn (array $e): bool => $e['queue'] !== $queue));

        return $removed;
    }

    /** @return list<JobEntity> */
    public function getJobs(string $queue = 'default', int $limit = 10): array
    {
        return array_slice($this->jobsOn($queue), 0, $limit);
    }

    /** @return list<JobEntity> */
    public function getDueJobs(string $queue = 'default'): array
    {
        return $this->jobsOn($queue);
    }

    public function migrateScheduledJobs(string $queue = 'default'): int
    {
        return 0;
    }

    public function lock(string $jobId, int $ttl = 60): bool
    {
        if (in_array($jobId, $this->locked, true)) return false;
        $this->locked[] = $jobId;

        return true;
    }

    public function unlock(string $jobId): bool
    {
        $this->locked = array_values(array_filter($this->locked, static fn (string $id): bool => $id !== $jobId));

        return true;
    }

    public function retry(JobEntity $job, string $queue = 'default', int $attempts = 0): bool
    {
        return $this->enqueue($job, $queue);
    }

    public function markAsCompleted(JobEntity $job, ?string $result = null): bool
    {
        return true;
    }

    public function markAsFailed(JobEntity $job, string $error, string $queue = 'default'): bool
    {
        return true;
    }

    /**
     * Afirma que um job da classe foi enfileirado.
     *
     * O callback recebe o `JobEntity` para inspecionar argumentos: sem ele o
     * teste provaria apenas que *algum* job daquele tipo passou, o que costuma
     * ser verdade mesmo quando os argumentos estão errados.
     *
     * @param callable(JobEntity,string):bool|null $filter
     */
    public function assertPushed(string $class, ?callable $filter = null): void
    {
        foreach ($this->pushed as $entry) {
            if ($entry['job']->getClass() !== $class) continue;
            if ($filter === null || $filter($entry['job'], $entry['queue'])) return;
        }

        Assert::fail("Nenhum job {$class} enfileirado" . ($filter === null ? '.' : ' com os argumentos esperados.'));
    }

    public function assertNotPushed(string $class): void
    {
        Assert::assertNotContains($class, $this->classesPushed(), "Job {$class} foi enfileirado e não deveria.");
    }

    public function assertPushedTimes(string $class, int $times): void
    {
        $actual = count(array_filter($this->pushed, static fn (array $e): bool => $e['job']->getClass() === $class));

        Assert::assertSame($times, $actual, "Esperado {$times} job(s) {$class}, encontrado {$actual}.");
    }

    public function assertNothingPushed(): void
    {
        Assert::assertSame([], $this->classesPushed(), 'A fila deveria estar vazia.');
    }

    /** @return list<string> */
    public function classesPushed(): array
    {
        return array_map(static fn (array $e): string => $e['job']->getClass(), $this->pushed);
    }

    /** @return list<JobEntity> */
    private function jobsOn(string $queue): array
    {
        return array_values(array_map(
            static fn (array $e): JobEntity => $e['job'],
            array_filter($this->pushed, static fn (array $e): bool => $e['queue'] === $queue),
        ));
    }
}
