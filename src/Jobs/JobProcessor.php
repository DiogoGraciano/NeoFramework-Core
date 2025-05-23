<?php

namespace NeoFramework\Core\Jobs;

use Ahc\Cli\Output\Color;
use Ahc\Cli\Output\Writer;
use Exception;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;

class JobProcessor
{
    private Client $client;
    private int $maxAttempts = 3;
    private bool $shouldStop = false;

    public function __construct(Client $client)
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
     * Processa um único job
     */
    public function processJob(JobEntity $job,string $queue = "default"): bool
    {
        $class = $job->getClass();
        
        if (!\is_subclass_of($class, "NeoFramework\Core\Abstract\Job")) {
            $this->client->markAsFailed($job, "Job class '{$class}' not extends NeoFramework\Core\Abstract\Job",$queue);
            return false;
        }
        
        if (!$this->client->lock($job->getId())) {
            return false;
        }
        
        try {
            $jobInstance = new $class(...$job->getArgs());
            
            $result = call_user_func_array([$jobInstance, 'handle'],[]);
            
            $this->client->markAsCompleted($job,is_string($result) ? $result : null);
            
            $this->client->unlock($job->getId());
            
            return true;
        } catch (Exception $e) {
            if ($job->getAttempts() < $this->maxAttempts) {
                $this->client->retry($job);
            } else {
                $this->client->markAsFailed($job, $e->getMessage(),$queue);
            }

            $this->client->unlock($job->getId());
            
            return false;
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
                    echo $color->ok("Job processed: ".$job->toJson().PHP_EOL);
                else
                    echo $color->error("Job failed: ".$job->toJson().PHP_EOL);
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