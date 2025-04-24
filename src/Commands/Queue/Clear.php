<?php

namespace NeoFramework\Core\Commands\Queue;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Jobs\QueueManager;

class Clear extends Command
{
    public function __construct()
    {   
        parent::__construct("queue:clear","Clear all of the jobs from the specified queue");
        
        $this->version("1.0")->arguments('[queue]');
    }

    public function execute(null|string $queue = "default"){
        $color = new Color;

        if($queue == null){
            $queue = "default";
        }

        try{
            echo $color->ok("Clear ".QueueManager::getInstance()->getClient()->clear($queue)." jobs from queue ".$queue.PHP_EOL);
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
