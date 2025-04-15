<?php

namespace NeoFramework\Core;

use Dotenv\Dotenv;
use Respect\Validation\Factory;

class Kernel
{
    public static function init()
    {   
        error_reporting(E_ALL);

        $dotenv = Dotenv::createImmutable(Functions::getRoot());
        $dotenv->load();

        $whoops = new \Whoops\Run;
        if ($_ENV["ENVIRONMENT"] !== "prod") {
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        } else {
            $whoops->pushHandler(function ($e) {
                Logger::error('Error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                $response = new Response;
                $response->setCode($e->getCode() ?: 500);
                $response->send();
            });
        }
        $whoops->register();

        Session::start();

        Factory::setDefaultInstance(
            (new Factory())
                ->withRuleNamespace('NeoFramework\Core\Validator\Rules')
                ->withExceptionNamespace('NeoFramework\Core\Validator\Exceptions')
        );

        (new Router)->load();
    }
}
