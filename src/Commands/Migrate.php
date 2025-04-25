<?php

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Diogodg\Neoorm\Migrations\GeneretePhpDoc;
use Diogodg\Neoorm\Migrations\Migrate as MigrationsMigrate;
use Exception;

class Migrate extends Command
{
    public function __construct()
    {   
        parent::__construct("migrate","Make migration of your database");
        
        $this->version("1.0")->option("-r --recreate","Recreate your database");
    }

    public function execute(null|bool $recreate){

        $color = new Color;

        try{
            (new MigrationsMigrate)->execute(!is_null($recreate));

            if(env("ENVIRONMENT") != "prod"){
                (new GeneretePhpDoc)->execute();
            }
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
