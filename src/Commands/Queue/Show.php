<?php

namespace NeoFramework\Core\Commands\Queue;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Ahc\Cli\Output\Writer;
use Exception;
use NeoFramework\Core\Jobs\QueueManager;

class Show extends Command
{
    public function __construct()
    {   
        parent::__construct("queue:show","Show all of the jobs from the specified queue");
        
        $this->version("1.0")->arguments('[queue]')->arguments('[limit]');
    }

    public function execute(null|string $queue = "default",null|int $limit = 15){
        $color = new Color;

        if($queue == null){
            $queue = "default";
        }

        if($limit == null){
            $limit = 15;
        }

        $writer = new Writer();

        try{
            $jobs = QueueManager::getInstance()->getClient()->getJobs($queue,$limit);
            $writer->table($jobs);
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
