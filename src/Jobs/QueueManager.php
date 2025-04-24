<?php

namespace NeoFramework\Core\Jobs;

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
            $driver = env('QUEUE_DRIVER');
            
            switch ($driver) {
                case 'redis':
                default:
                    $client = new Drivers\Redis();
                    break;
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
