<?php

declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Events\EventDispatcher;
use NeoFramework\Core\Events\JobFailed;
use NeoFramework\Core\Events\JobProcessed;
use NeoFramework\Core\Events\JobProcessing;
use NeoFramework\Core\Events\ListenerProvider;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use NeoFramework\Core\Jobs\JobProcessor;
use NeoFramework\Core\Observability\InMemoryMetricsExporter;
use NeoFramework\Core\Observability\QueueMetricsListener;
use PHPUnit\Framework\TestCase;
use Tests\JobsClass\FailingJob;
use Tests\JobsClass\TestJob;

/** Cliente de fila em memória: o teste é sobre eventos, não sobre driver. */
final class RecordingQueueClient implements Client
{
    /** @var list<string> */
    public array $calls = [];

    public function enqueue(JobEntity $job, string $queue = 'default'): bool { return true; }
    public function dequeue(string $queue = 'default'): ?JobEntity { return null; }
    public function size(string $queue = 'default'): int { return 0; }
    public function clear(string $queue = 'default'): int { return 0; }
    public function getJobs(string $queue = 'default', int $limit = 10): array { return []; }
    public function lock(string $jobId, int $ttl = 60): bool { return true; }
    public function unlock(string $jobId): bool { $this->calls[] = 'unlock'; return true; }
    public function retry(JobEntity $job, string $queue = 'default', int $attempts = 0): bool { $this->calls[] = 'retry'; return true; }
    public function scheduleJob(JobEntity $job, string $queue = 'default'): bool { return true; }
    public function getDueJobs(string $queue = 'default'): array { return []; }
    public function migrateScheduledJobs(string $queue = 'default'): int { return 0; }
    public function markAsCompleted(JobEntity $job, ?string $result = null): bool { $this->calls[] = 'completed'; return true; }
    public function markAsFailed(JobEntity $job, string $error, string $queue = 'default'): bool { $this->calls[] = 'failed'; return true; }
}

final class NeoFrameworkJobEventsTest extends TestCase
{
    /** @var list<object> */
    private array $seen = [];

    private function processorFor(array $events, ?JobEntity &$job = null, int $attempts = 0): JobProcessor
    {
        $provider = new ListenerProvider();
        $this->seen = [];
        $listener = function (object $event): void { $this->seen[] = $event; };
        foreach ($events as $event) $provider->on($event, $listener);

        return new JobProcessor(new RecordingQueueClient(), new EventDispatcher($provider));
    }

    private static function job(string $class, array $args = [], int $attempts = 0): JobEntity
    {
        $job = new JobEntity($class, $args);
        for ($i = 0; $i < $attempts; $i++) $job->incrementAttempts();

        return $job;
    }

    public function testASuccessfulJobDispatchesProcessingAndProcessed(): void
    {
        $processor = $this->processorFor([JobProcessing::class, JobProcessed::class, JobFailed::class]);

        self::assertTrue($processor->processJob(self::job(TestJob::class, ['ok']), 'emails'));

        self::assertCount(2, $this->seen);
        self::assertInstanceOf(JobProcessing::class, $this->seen[0]);
        self::assertSame('emails', $this->seen[0]->queue);
        self::assertInstanceOf(JobProcessed::class, $this->seen[1]);
        self::assertSame(TestJob::class, $this->seen[1]->jobClass());
        self::assertGreaterThanOrEqual(0.0, $this->seen[1]->durationMs);
    }

    /** Falha com tentativa restante é diferente de falha que desistiu. */
    public function testAFailingJobReportsWhetherItWillRetry(): void
    {
        $processor = $this->processorFor([JobFailed::class]);

        self::assertFalse($processor->processJob(self::job(FailingJob::class), 'default'));

        self::assertInstanceOf(JobFailed::class, $this->seen[0]);
        self::assertTrue($this->seen[0]->willRetry);
        self::assertSame('Test Error', $this->seen[0]->exception->getMessage());
    }

    public function testAJobThatExhaustedItsAttemptsIsNotMarkedForRetry(): void
    {
        $processor = $this->processorFor([JobFailed::class]);
        $processor->setMaxAttempts(1);

        $processor->processJob(self::job(FailingJob::class, [], attempts: 1), 'default');

        self::assertFalse($this->seen[0]->willRetry);
    }

    /**
     * `queue:work` é um processo que roda indefinidamente. Sem escopo por job,
     * o que um deixasse no escopo estático chegaria ao próximo — a mesma classe
     * de vazamento que a Fase 3 fechou para o HTTP.
     */
    public function testEachJobGetsAFreshScope(): void
    {
        $processor = $this->processorFor([JobProcessing::class]);
        $processor->processJob(self::job(TestJob::class, ['a']), 'default');

        $leaked = null;
        $provider = new ListenerProvider();
        $provider->on(JobProcessing::class, function () use (&$leaked): void {
            $leaked = RequestScopeContext::current()?->get('sujeira');
            RequestScopeContext::current()?->set('sujeira', 'do job anterior');
        });
        $second = new JobProcessor(new RecordingQueueClient(), new EventDispatcher($provider));

        $second->processJob(self::job(TestJob::class, ['b']), 'default');
        self::assertNull($leaked);
        $second->processJob(self::job(TestJob::class, ['c']), 'default');
        self::assertNull($leaked, 'O segundo job enxergou o que o primeiro deixou no escopo.');
    }

    /** Um job despachado dentro de uma requisição não pode apagar o escopo dela. */
    public function testAnOuterScopeIsRestoredAfterTheJob(): void
    {
        $outer = new RequestScope();
        $outer->set('quem', 'a requisição');
        RequestScopeContext::enter($outer);

        try {
            $this->processorFor([JobProcessing::class])->processJob(self::job(TestJob::class, ['x']), 'default');

            self::assertSame($outer, RequestScopeContext::current());
            self::assertSame('a requisição', RequestScopeContext::current()?->get('quem'));
        } finally {
            RequestScopeContext::leave($outer);
        }
    }

    /** O logger só tem contexto de job porque o escopo existe. */
    public function testTheJobScopeCarriesLogContext(): void
    {
        $context = null;
        $provider = new ListenerProvider();
        $provider->on(JobProcessing::class, function () use (&$context): void {
            $context = RequestScopeContext::current()?->get('neoframework.log_context');
        });

        $job = self::job(TestJob::class, ['x']);
        (new JobProcessor(new RecordingQueueClient(), new EventDispatcher($provider)))->processJob($job, 'relatorios');

        self::assertSame(['jobId' => $job->getId(), 'job' => TestJob::class, 'queue' => 'relatorios'], $context);
    }

    public function testQueueMetricsSeparateRetryFromDefinitiveFailure(): void
    {
        $metrics = new InMemoryMetricsExporter();
        $listener = new QueueMetricsListener($metrics);
        $provider = new ListenerProvider();
        $provider->on(JobProcessed::class, $listener);
        $provider->on(JobFailed::class, $listener);
        $processor = new JobProcessor(new RecordingQueueClient(), new EventDispatcher($provider));

        $processor->processJob(self::job(TestJob::class, ['ok']), 'emails');
        $processor->processJob(self::job(FailingJob::class), 'emails');
        $processor->setMaxAttempts(1);
        $processor->processJob(self::job(FailingJob::class, [], attempts: 1), 'emails');

        $statuses = array_column(array_column($metrics->samples(QueueMetricsListener::PROCESSED), 'labels'), 'status');
        self::assertSame(['completed', 'retrying', 'failed'], $statuses);
        self::assertCount(3, $metrics->samples(QueueMetricsListener::DURATION));
    }

    /** Sem dispatcher o worker roda igual: instrumentação desligada não muda nada. */
    public function testJobsRunWithoutAnyDispatcher(): void
    {
        self::assertTrue((new JobProcessor(new RecordingQueueClient()))->processJob(self::job(TestJob::class, ['x'])));
        self::assertNull(RequestScopeContext::current());
    }
}
