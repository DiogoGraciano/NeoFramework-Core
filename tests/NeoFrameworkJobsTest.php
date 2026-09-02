<?php
declare(strict_types=1);

namespace Tests;

use DateTime;
use Exception;
use NeoFramework\Core\Jobs\Drivers\Files;
use NeoFramework\Core\Jobs\Drivers\Redis;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use NeoFramework\Core\Jobs\JobProcessor;
use NeoFramework\Core\Jobs\QueueManager;
use PHPUnit\Framework\TestCase;
use Redis as PhpRedis;
use Tests\JobsClass\FailingJob;
use Tests\JobsClass\TestJob;

/**
 * Consolidated test class for NeoFramework Core Jobs
 */
/**
 * O mock do driver nasce no `setUp()` porque o `JobProcessor` exige um `Client` na
 * construção, mas só os casos de processamento configuram expectativas nele. O
 * atributo evita que os demais sejam apontados como mock sem expectativa — a
 * alternativa seria duplicar a montagem do processor em cada um deles.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class NeoFrameworkJobsTest extends TestCase
{
    private Client $driver;
    private $driverMock;
    private $processor;
    private string $testPrefix = 'test:neoframework:jobs:';
    private string $driverType = 'redis'; // Can be 'redis' or 'files'
    private string $storagePath = '';

    /**
     * @before
     *
     * O driver de arquivos recebe um diretório PRÓPRIO por teste.
     *
     * Antes o caminho da limpeza vinha de `env("JOBS_STORAGE_PATH")` enquanto o
     * driver passou a resolvê-lo pelo Config, cujo default é
     * /tmp/neoframework_jobs: limpava-se um diretório e escrevia-se em outro, e
     * a fila acumulava entre os casos. Um diretório por teste corrige o
     * descasamento e elimina de vez a fila compartilhada entre os 26 casos.
     */
    protected function setUp(): void
    {
        $this->driverType = env('QUEUE_DRIVER') ?? "files";

        // Setup for tests using mocks
        $this->driverMock = $this->createMock(Client::class);
        $this->processor = new JobProcessor($this->driverMock);

        try {
            if ($this->driverType === 'redis') {
                $this->driver = new Redis([
                    'prefix' => $this->testPrefix,
                    'host' => env("REDIS_HOST"),
                    'port' => env("REDIS_PORT"),
                    'password' => env("REDIS_PASSWORD")
                ]);
                $this->cleanupRedisBeforeTest();
            } else {
                $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                    . 'neoframework_jobs_test_' . bin2hex(random_bytes(8));
                $this->cleanupFileJsonBeforeTest($this->storagePath);
                $this->driver = new Files(['prefix' => 'test_', 'path' => $this->storagePath]);
            }
        } catch (\Exception $e) {
            // Indisponibilidade real do driver é a ÚNICA razão legítima para pular.
            $this->markTestSkipped('Driver de filas indisponível: ' . $e->getMessage());
        }
    }

    /**
     * Cleans all Redis test keys before each test
     */
    private function cleanupRedisBeforeTest(): void
    {
        if ($this->driverType !== 'redis') {
            return;
        }

        // Connect directly to Redis to clean keys
        $host = env("REDIS_HOST");
        $port = env("REDIS_PORT");
        $password = env("REDIS_PASSWORD", "");

        if (!$host || !$port) {
            throw new Exception("Redis host or port not configured");
        }

        $redis = new PhpRedis();

        try {
            $redis->connect($host, $port);

            if ($password) {
                $redis->auth($password);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to connect to Redis: " . $e->getMessage());
        }

        // Clean all keys related to tests
        $keys = $redis->keys($this->testPrefix . '*');
        if (!empty($keys)) {
            $redis->del($keys);
        }
    }

    /**
     * Cleans all FileJson test files before each test
     */
    private function cleanupFileJsonBeforeTest(string $tempDir): void
    {
        if ($this->driverType !== 'files') {
            return;
        }

        // If directory exists, recursively remove all files
        if (file_exists($tempDir)) {
            $this->recursiveRemoveDirectory($tempDir);
        }

        // Recreate the directory
        mkdir($tempDir, 0755, true);
    }

    /**
     * Recursively remove a directory and its contents
     */
    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /**
     * @after
     */
    protected function tearDown(): void
    {
        try {
            if ($this->driverType === 'redis') {
                $this->cleanupRedisBeforeTest();
            } elseif ($this->storagePath !== '') {
                $this->recursiveRemoveDirectory($this->storagePath);
            }
        } catch (Exception $e) {
            // Limpeza best-effort: falha aqui não deve mascarar o resultado.
        }
    }

    // ===== JOB ENTITY TESTS =====

    public function testJobEntityCanBeCreated(): void
    {
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], null);
        $this->assertInstanceOf(JobEntity::class, $job);
    }

    public function testJobEntityCanConvertToArray(): void
    {
        $now = new DateTime();
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], $now);
        $array = $job->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('TestJob', $array['class']);
        $this->assertEquals(['arg1', 'arg2'], $array['args']);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $array['schedule']);
    }

    public function testJobEntityCanConvertToJson(): void
    {
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], null);
        $json = $job->toJson();

        $this->assertIsString($json);
        $this->assertJson($json);

        $decodedJson = json_decode($json, true);
        $this->assertEquals('TestJob', $decodedJson['class']);
    }

    public function testJobEntityCanCreateFromArray(): void
    {
        $data = [
            'id' => 'job_123',
            'class' => 'TestJob',
            'args' => ['arg1', 'arg2'],
            'schedule' => '2023-01-01 12:00:00',
            'attempts' => 2,
            'status' => 'failed',
            'error' => 'Test error'
        ];

        $job = JobEntity::fromArray($data);

        $this->assertEquals('job_123', $job->getId());
        $this->assertEquals('TestJob', $job->getClass());
        $this->assertEquals(['arg1', 'arg2'], $job->getArgs());
        $this->assertInstanceOf(DateTime::class, $job->getSchedule());
        $this->assertEquals(2, $job->getAttempts());
        $this->assertEquals('failed', $job->getStatus());
        $this->assertEquals('Test error', $job->getError());
    }

    public function testJobEntityCanCreateFromJson(): void
    {
        $json = json_encode([
            'id' => 'job_123',
            'class' => 'TestJob',
            'args' => ['arg1', 'arg2']
        ]);

        $job = JobEntity::fromJson($json);

        $this->assertEquals('job_123', $job->getId());
        $this->assertEquals('TestJob', $job->getClass());
        $this->assertEquals(['arg1', 'arg2'], $job->getArgs());
    }

    public function testJobEntityCanCheckIfJobIsDue(): void
    {
        // Job without scheduling is always due
        $job1 = new JobEntity('TestJob', [], null);
        $this->assertTrue($job1->isDue());

        // Job scheduled for the past is due
        $pastDate = new DateTime('-1 hour');
        $job2 = new JobEntity('TestJob', [], $pastDate);
        $this->assertTrue($job2->isDue());

        // Job scheduled for the future is not due
        $futureDate = new DateTime('+1 hour');
        $job3 = new JobEntity('TestJob', [], $futureDate);
        $this->assertFalse($job3->isDue());
    }

    // ===== DRIVER TESTS =====

    public function testEnqueueAddsJobToQueue(): void
    {
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], null);

        $result = $this->driver->enqueue($job);
        $size = $this->driver->size();

        $this->assertTrue($result);
        $this->assertEquals(1, $size, 'Queue should contain 1 job after enqueuing');
    }

    public function testScheduleJobAddsJobToScheduledQueue(): void
    {
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], new DateTime('+1 hour'));

        $result = $this->driver->scheduleJob($job);

        $this->assertTrue($result);

        // Check if job is in scheduled queue
        $dueJobs = $this->driver->getDueJobs();
        $this->assertCount(0, $dueJobs, 'Should have no due jobs now (job was scheduled for the future)');

        // Check if job details can be found
        $jobs = $this->driver->getJobs();
        $this->assertCount(0, $jobs, 'Should have no jobs in main queue');
    }

    public function testDequeueReturnsAndRemovesJobFromQueue(): void
    {
        // Create and enqueue a job
        $job = new JobEntity('TestJob', ['arg1', 'arg2'], null);
        $this->driver->enqueue($job);

        // Check if there's 1 job in the queue
        $sizeBefore = $this->driver->size();
        $this->assertEquals(1, $sizeBefore, 'Queue should contain 1 job before dequeuing');

        // Dequeue the job
        $dequeued = $this->driver->dequeue();

        // Check returned job details
        $this->assertInstanceOf(JobEntity::class, $dequeued);
        $this->assertEquals('TestJob', $dequeued->getClass());
        $this->assertEquals(['arg1', 'arg2'], $dequeued->getArgs());
        $this->assertEquals('processing', $dequeued->getStatus());

        // Check if job was removed from queue
        $sizeAfter = $this->driver->size();
        $this->assertEquals(0, $sizeAfter, 'Queue should be empty after dequeuing');
    }

    public function testMigrateScheduledJobsMovesJobsToMainQueue(): void
    {
        // Create jobs with past dates
        $pastDate = new DateTime('-1 hour');
        $job1 = new JobEntity('TestJob1', ['arg1'], $pastDate);
        $job2 = new JobEntity('TestJob2', ['arg2'], $pastDate);

        // Schedule jobs (which are in the past)
        $this->driver->scheduleJob($job1);
        $this->driver->scheduleJob($job2);

        // Check main queue before migration
        $sizeBefore = $this->driver->size();
        $this->assertEquals(0, $sizeBefore, 'Main queue should be empty before migration');

        // Migrate jobs
        $count = $this->driver->migrateScheduledJobs();

        // Check if two jobs were migrated
        $this->assertEquals(2, $count, 'Two jobs should have been migrated');

        // Check main queue after migration
        $sizeAfter = $this->driver->size();
        $this->assertEquals(2, $sizeAfter, 'Main queue should contain 2 jobs after migration');

        // Check if scheduled queue is empty
        $dueJobs = $this->driver->getDueJobs();
        $this->assertCount(0, $dueJobs, 'Scheduled queue should be empty after migration');
    }

    public function testLockCreatesLockForJob(): void
    {
        $jobId = 'job_' . uniqid();

        // Create a lock
        $result = $this->driver->lock($jobId);
        $this->assertTrue($result, 'Should be able to create a lock for the job');

        // Try to create a second lock (which should fail)
        $secondLock = $this->driver->lock($jobId);
        $this->assertFalse($secondLock, 'Should not be able to create a second lock for the same job');
    }

    public function testUnlockRemovesLockForJob(): void
    {
        $jobId = 'job_' . uniqid();

        // Create a lock
        $this->driver->lock($jobId);

        // Remove the lock
        $result = $this->driver->unlock($jobId);
        $this->assertTrue($result, 'Should be able to remove the lock');

        // Check if can create a new lock after removal
        $newLock = $this->driver->lock($jobId);
        $this->assertTrue($newLock, 'Should be able to create a new lock after removing the previous one');
    }

    public function testSizeReturnsQueueLength(): void
    {
        // Initially, queue should be empty
        $size = $this->driver->size();
        $this->assertEquals(0, $size, 'Queue should be empty initially');

        // Add 3 jobs
        $job1 = new JobEntity('TestJob1', [], null);
        $job2 = new JobEntity('TestJob2', [], null);
        $job3 = new JobEntity('TestJob3', [], null);

        $this->driver->enqueue($job1);
        $this->driver->enqueue($job2);
        $this->driver->enqueue($job3);

        // Check queue size
        $size = $this->driver->size();
        $this->assertEquals(3, $size, 'Queue should contain 3 jobs');
    }

    public function testGetJobsReturnsJobsFromQueue(): void
    {
        // Add 2 jobs
        $job1 = new JobEntity('TestJob1', ['arg1'], null);
        $job2 = new JobEntity('TestJob2', ['arg2'], null);

        $this->driver->enqueue($job1);
        $this->driver->enqueue($job2);

        // Get jobs
        $jobs = $this->driver->getJobs();

        // Check if returned 2 jobs
        $this->assertCount(2, $jobs, 'Should return 2 jobs');

        // Check job details
        // Note: order may vary depending on implementation (LIFO or FIFO)
        $classes = [$jobs[0]->getClass(), $jobs[1]->getClass()];
        $this->assertContains('TestJob1', $classes);
        $this->assertContains('TestJob2', $classes);
    }

    public function testRetryAddsJobBackToQueue(): void
    {
        // Create and dequeue a job
        $job = new JobEntity('TestJob', ['arg1'], null);
        $this->driver->enqueue($job);
        $job = $this->driver->dequeue();

        // Check if queue is empty
        $sizeBefore = $this->driver->size();
        $this->assertEquals(0, $sizeBefore, 'Queue should be empty after dequeuing');

        // Retry the job
        $result = $this->driver->retry($job, 'default', 2);
        $this->assertTrue($result, 'Should be able to retry the job');

        // Check if job was added back to queue
        $sizeAfter = $this->driver->size();
        $this->assertEquals(1, $sizeAfter, 'Queue should contain 1 job after retrying');

        // Check retried job details
        $retriedJob = $this->driver->dequeue();
        $this->assertEquals('TestJob', $retriedJob->getClass());
        $this->assertEquals(2, $retriedJob->getAttempts(), 'Job should have 2 attempts');
    }

    public function testGetDueJobsReturnsScheduledJobsDue(): void
    {
        // Create a job scheduled for the past
        $pastDate = new DateTime('-1 hour');
        $job = new JobEntity('TestJob', ['arg1'], $pastDate);

        // Schedule the job
        $this->driver->scheduleJob($job);

        // Get due jobs
        $dueJobs = $this->driver->getDueJobs();

        // Check if returned 1 job
        $this->assertCount(1, $dueJobs, 'Should return 1 due job');
        $this->assertEquals('TestJob', $dueJobs[0]->getClass());
    }

    public function testMarkAsCompletedUpdatesJobStatus(): void
    {
        // Create and enqueue a job
        $job = new JobEntity('TestJob', ['arg1'], null);
        $this->driver->enqueue($job);
        $job = $this->driver->dequeue();

        // Mark job as completed
        $result = $this->driver->markAsCompleted($job, 'Job result');
        $this->assertTrue($result, 'Should be able to mark job as completed');

        // Note: We would need to add a method to retrieve specific job details
        // or check in a real database
    }

    public function testMarkAsFailedUpdatesJobStatusAndAddsToFailedQueue(): void
    {
        // Create and enqueue a job
        $job = new JobEntity('TestJob', ['arg1'], null);
        $this->driver->enqueue($job);
        $job = $this->driver->dequeue();

        // Mark job as failed
        $result = $this->driver->markAsFailed($job, 'Test error');
        $this->assertTrue($result, 'Should be able to mark job as failed');

        // Note: We would need to add additional methods to check the failed queue
    }

    // ===== JOB PROCESSOR TESTS =====

    public function testProcessJobHandlesValidJob(): void
    {
        $job = new JobEntity(TestJob::class, ['test'], null);

        // Configure mock for lock method
        $this->driverMock->expects($this->once())
            ->method('lock')
            ->willReturn(true);

        // Configure mock for markAsCompleted method
        $this->driverMock->expects($this->once())
            ->method('markAsCompleted')
            ->willReturn(true);

        // Configure mock for unlock method
        $this->driverMock->expects($this->once())
            ->method('unlock')
            ->willReturn(true);

        $result = $this->processor->processJob($job);
        $this->assertTrue($result);
    }

    public function testProcessJobRejectsUnregisteredJobs(): void
    {
        $job = new JobEntity('UnregisteredJob', [], null);

        // Configure mock for markAsFailed method
        $this->driverMock->expects($this->once())
            ->method('markAsFailed')
            ->willReturn(true);

        $result = $this->processor->processJob($job);
        $this->assertFalse($result);
    }

    public function testProcessJobHandlesExceptions(): void
    {
        $job = new JobEntity(FailingJob::class, [], null);

        // Configure mock for lock method
        $this->driverMock->expects($this->once())
            ->method('lock')
            ->willReturn(true);

        // Configure mock for retry or markAsFailed method
        $this->driverMock->expects($this->once())
            ->method('retry')
            ->willReturn(true);

        // Configure mock for unlock method
        $this->driverMock->expects($this->once())
            ->method('unlock')
            ->willReturn(true);

        $result = $this->processor->processJob($job);
        $this->assertFalse($result);
    }

    public function testProcessJobMarksJobAsFailedAfterMaxAttempts(): void
    {
        $job = new JobEntity(FailingJob::class, [], null);
        $job->setAttempts(3); // Already tried 3 times
        $this->processor->setMaxAttempts(3); // Maximum of 3 attempts

        // Configure mock for lock method
        $this->driverMock->expects($this->once())
            ->method('lock')
            ->willReturn(true);

        // Configure mock for markAsFailed method
        $this->driverMock->expects($this->once())
            ->method('markAsFailed')
            ->willReturn(true);

        // Configure mock for unlock method
        $this->driverMock->expects($this->once())
            ->method('unlock')
            ->willReturn(true);

        $result = $this->processor->processJob($job);
        $this->assertFalse($result);
    }

    // ===== QUEUE MANAGER TESTS =====

    public function testCanGetInstance(): void
    {
        // Force reset of singleton for testing
        $reflection = new \ReflectionClass(QueueManager::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Set up mock env function if it doesn't exist
        if (!function_exists('env')) {
            function env($key, $default = null)
            {
                $values = [
                    'QUEUE_DRIVER' => 'redis',  // Could be 'redis' or 'files'
                    'REDIS_HOST' => 'localhost',
                    'REDIS_PORT' => 6379,
                    'JOBS_STORAGE_PATH' => sys_get_temp_dir() . '/neoframeworkjobs_test'
                ];
                return $values[$key] ?? $default;
            }
        }

        $instance = QueueManager::getInstance();
        $this->assertInstanceOf(QueueManager::class, $instance);

        $client = $instance->getClient();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testCanSetClient(): void
    {
        $mockClient = $this->createMock(Client::class);

        $queueManager = QueueManager::getInstance();
        $queueManager->setClient($mockClient);

        $this->assertSame($mockClient, $queueManager->getClient());
    }

    // ===== INTEGRATION TESTS =====

    public function testJobCanBeDispatchedThroughStaticMethod(): void
    {
        // Create a mock job class that extends Job
        $jobClass = new class() extends \NeoFramework\Core\Abstract\Job {
            public function handle()
            {
                return true;
            }

            public static function getQueueManager()
            {
                return QueueManager::getInstance();
            }
        };

        $className = get_class($jobClass);

        // Reset and set up QueueManager
        $reflection = new \ReflectionClass(QueueManager::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $queueManager = QueueManager::getInstance();
        $queueManager->setClient($this->driverMock);

        // Configure mock for enqueue method
        $this->driverMock->expects($this->once())
            ->method('enqueue')
            ->with(
                $this->callback(function ($job) use ($className) {
                    return $job instanceof JobEntity && $job->getClass() === $className;
                })
            )
            ->willReturn(true);

        // Dispatch the job
        $reflection = new \ReflectionMethod($className, 'dispatch');
        $job = $reflection->invoke(null, ['test']);

        $this->assertInstanceOf(JobEntity::class, $job);
        $this->assertEquals($className, $job->getClass());
    }

    public function testJobCanBeScheduledThroughStaticMethod(): void
    {
        // Create an anonymous job class extending Job
        $jobClass = new class() extends \NeoFramework\Core\Abstract\Job {
            public function handle()
            {
                return true;
            }

            public static function getQueueManager()
            {
                return \NeoFramework\Core\Jobs\QueueManager::getInstance();
            }
        };

        $className = get_class($jobClass);
        $schedule = new \DateTime('+1 hour');

        // Reset the QueueManager singleton instance
        $reflection = new \ReflectionClass(\NeoFramework\Core\Jobs\QueueManager::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Schedule the job using the 'later' static method via reflection
        $reflection = new \ReflectionMethod($className, 'later');
        $job = $reflection->invoke(null, $schedule, ['test']);

        // Assert the returned object is a valid JobEntity
        $this->assertInstanceOf(\NeoFramework\Core\Jobs\Entity\JobEntity::class, $job);
        $this->assertEquals($className, $job->getClass());
        $this->assertEquals($schedule->format('Y-m-d H:i:s'), $job->getSchedule()->format('Y-m-d H:i:s'));
    }
}
