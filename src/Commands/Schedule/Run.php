<?php

namespace NeoFramework\Core\Commands\Schedule;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Functions;
use NeoFramework\Core\Scheduler;

class Run extends Command
{
    public function __construct()
    {   
        parent::__construct("schedule:run","Start processing schedule");
        
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

            echo $color->ok(json_encode(Scheduler::run(),JSON_PRETTY_PRINT)).PHP_EOL;
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
