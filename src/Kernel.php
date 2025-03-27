<?php

namespace NeoFramework\Core;

use Respect\Validation\Factory;

class Kernel
{
    public static function init()
    {
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
