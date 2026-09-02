<?php
declare(strict_types=1);

namespace NeoFramework\Core\Jobs;

use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\JobFailed;
use NeoFramework\Core\Events\JobProcessed;
use NeoFramework\Core\Events\JobProcessing;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use Psr\EventDispatcher\EventDispatcherInterface;

class JobProcessor
{
    private Client $client;
    private int $maxAttempts = 3;
    private bool $shouldStop = false;

    public function __construct(Client $client, private readonly ?EventDispatcherInterface $dispatcher = null)
    {
        $this->client = $client;
    }

    /**
     * Define o número máximo de tentativas para cada job
     */
    public function setMaxAttempts(int $attempts): self
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    /**
     * Processa um único job, dentro de um escopo próprio.
     */
    public function processJob(JobEntity $job, string $queue = "default"): bool
    {
        return JobScope::run($job, $queue, $this->dispatcher, fn (): bool => $this->run($job, $queue));
    }

    private function run(JobEntity $job, string $queue): bool
    {
        $class = $job->getClass();

        if (!\is_subclass_of($class, "NeoFramework\Core\Abstract\Job")) {
            $this->client->markAsFailed($job, "Job class '{$class}' not extends NeoFramework\Core\Abstract\Job",$queue);
            return false;
        }

        if (!$this->client->lock($job->getId())) {
            return false;
        }

        Events::dispatch(new JobProcessing($job, $queue));
        $startedAt = microtime(true);

        try {
            $jobInstance = new $class(...$job->getArgs());

            $result = call_user_func_array([$jobInstance, 'handle'],[]);

            $this->client->markAsCompleted($job,is_string($result) ? $result : null);
            Events::dispatch(new JobProcessed($job, $queue, (microtime(true) - $startedAt) * 1000));

            return true;
        } catch (\Throwable $e) {
            // Throwable, e não Exception: um TypeError dentro do job derrubava o
            // worker e deixava o lock preso até expirar.
            $willRetry = $job->getAttempts() < $this->maxAttempts;

            if ($willRetry) {
                // Sem a fila, um job de fila nomeada voltava sempre para "default".
                $this->client->retry($job, $queue);
            } else {
                $this->client->markAsFailed($job, $e->getMessage(),$queue);
            }

            Events::dispatch(new JobFailed($job, $queue, $e, $willRetry, (microtime(true) - $startedAt) * 1000));

            return false;
        } finally {
            // O unlock precisa acontecer mesmo se markAsFailed/markAsCompleted
            // lançarem, caso contrário o job trava para sempre.
            $this->client->unlock($job->getId());
        }
    }

    /**
     * Inicia o worker para processar jobs continuamente
     */
    public function work(string $queue = "default", int $sleep = 1): void
    {
        $color = new Color();
        while (!$this->shouldStop) {

            $job = $this->client->dequeue($queue);

            if ($job) {
                if($this->processJob($job,$queue))
                    echo $color->ok("Job processed: " . $job->toJson() . PHP_EOL);
                else
                    echo $color->error("Job failed: " . $job->toJson() . PHP_EOL);
            } else {
                sleep($sleep);
            }
        }
    }

    /**
     * Sinaliza que o worker deve parar de processar
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }
}
