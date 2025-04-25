<?php

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Helper\Shell;
use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use Exception;
use NeoFramework\Core\Bundler;
use NeoFramework\Core\Functions;

class Build extends Command
{
    public function __construct()
    {   
        parent::__construct("build","Bumdle your js e css files");
        
        $this->version("1.0");
    }

    public function execute(){
        $color = new Color;

        try{
            if(file_exists(Functions::getRoot()."tailwindcss")){
                $shell = new Shell(Functions::getRoot()."tailwindcss -i ./Resources/Css/main.css -o ./Resources/Css/tailwind.css --minify");
                $shell->execute();
                $shell->getErrorOutput();
            }

            if(!file_exists(Functions::getRoot()."Config".DIRECTORY_SEPARATOR."bundler.config.php")){
                echo $color->error("Config file not found in ".Functions::getRoot()."Config".DIRECTORY_SEPARATOR."bundler.config.php");
                return;
            }

            $config = include Functions::getRoot()."Config".DIRECTORY_SEPARATOR."bundler.config.php";

            Bundler::build($config);
        }
        catch(Exception $e){
            echo $color->error($e->getMessage().PHP_EOL.$e->getTraceAsString());
        }
    }
}
