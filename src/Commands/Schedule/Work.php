<?php

namespace NeoFramework\Core\Commands\Schedule;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Functions;
use NeoFramework\Core\Scheduler;

class Work extends Command
{
    public function __construct()
    {   
        parent::__construct("schedule:work","Start processing schedule");
        
        $this->version("1.0");
    }

    public function execute(){
        $color = new Color;

        try{
            if(!file_exists(Functions::getRoot()."schedule.php")){
                echo $color->error("Config file not found in ".Functions::getRoot()."schedule.php").PHP_EOL;
                return;
            }

            require_once Functions::getRoot()."schedule.php";

            echo $color->info("Schedule Work started").PHP_EOL;

            Scheduler::work();
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
