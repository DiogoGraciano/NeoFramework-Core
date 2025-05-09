<?php

namespace NeoFramework\Core;

use DateTime;
use GO\Job;
use GO\Scheduler as GOScheduler;
use NeoFramework\Core\Commands\Schedule\Run;
use NeoFramework\Core\Commands\Schedule\Work;

class Scheduler
{
    private static ?GOScheduler $instance = null;

    private function __construct(){
    }

    private static function getInstance():GOScheduler
    {
        if (self::$instance === null) {
            self::$instance = new GOScheduler();
        }
        
        return self::$instance;
    }

    public static function call(callable $fn,array $args = [],?string $id = null):Job
    {
        return self::getInstance()->call($fn,$args,$id);
    }

    public static function php(string $script,?string $bin = null,array $args = [],?string $id = null):Job
    {
        return self::getInstance()->php($script,$bin,$args,$id);
    }

    public static function raw(string $command,array $args = [],?string $id = null):Job
    {
        return self::getInstance()->raw($command,$args,$id);
    }

    public static function run(?DateTime $runTime = null):array
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $backtrace[1]['class'] ?? null;

        if ($callerClass !== Run::class) {
            throw new \Exception("The run method can only be called by the schedule:run command.");
        }

        return self::getInstance()->run($runTime);
    }

    public static function work(array $seconds = [0]):void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $backtrace[1]['class'] ?? null;

        if ($callerClass !== Work::class) {
            throw new \Exception("The run method can only be called by the schedule:work command.");
        }

        self::getInstance()->work($seconds);
    }
}
