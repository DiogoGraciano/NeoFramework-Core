<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Queue;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Events\Events;
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

        echo $color->info("Queue Work started") . PHP_EOL;
        try{
            // O dispatcher vem do escopo que o bin/neof abriu; passá-lo ao
            // processor faz cada job reabrir um escopo próprio já com ele dentro.
            (new JobProcessor(QueueManager::getInstance()->getClient(), Events::dispatcher()))->work($queue);
        }
        catch(Exception $e){
            echo $color->error($e->getMessage() . PHP_EOL . $e->getTraceAsString());
        }
    }
}
