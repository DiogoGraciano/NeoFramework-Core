<?php
declare(strict_types=1);

namespace NeoFramework\Core\Jobs;

use Exception;
use NeoFramework\Core\Config;
use NeoFramework\Core\Config\QueueConfig;
use NeoFramework\Core\Jobs\Drivers\Files;
use NeoFramework\Core\Jobs\Drivers\Redis;
use NeoFramework\Core\Jobs\Interfaces\Client;

class QueueManager
{
    private static ?QueueManager $instance = null;
    private Client $client;

    private function __construct(Client $client)
    {
        $this->client = $client;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $config = QueueConfig::from(Config::repository());
            switch ($config->driver) {
               case 'redis':
                    $client = new Redis(['host' => $config->redisHost, 'port' => $config->redisPort, 'password' => $config->redisPassword, 'prefix' => $config->redisPrefix]);
                    break;
               case 'files':
                    $client = new Files(['path' => $config->filesPath, 'defaultJobTTL' => $config->defaultTtl, 'lockTTL' => $config->lockTtl]);
                    break;
                default:
                    throw new Exception("Unsupported queue driver: {$config->driver}");
            }

            self::$instance = new self($client);
        }

        return self::$instance;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }
}
