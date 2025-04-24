<?php

namespace NeoFramework\Core\Commands\Queue;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Jobs\JobProcessor;
use NeoFramework\Core\Jobs\QueueManager;

class Work extends Command
{
    public function __construct()
    {   
        parent::__construct("queue:work","Start processing jobs on the queue as a daemon");
        
        $this->version("1.0")->arguments('[queue]');
    }

    public function execute(null|string $queue = "default"){
        $color = new Color;

        if($queue == null){
            $queue = "default";
        }

        try{
            (new JobProcessor(QueueManager::getInstance()->getClient()))->work($queue);
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
