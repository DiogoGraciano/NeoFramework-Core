<?php

namespace NeoFramework\Core;

use Dotenv\Dotenv;
use Respect\Validation\Factory;

class Kernel
{
    public static function loadEnv(){
        $dotenv = Dotenv::createImmutable(Functions::getRoot());
        $dotenv->load();
    }

    public static function init()
    {
        error_reporting(E_ALL);

        self::loadEnv();

        $isProduction = env("ENVIRONMENT") === "prod";

        // Em produção os detalhes vão para o log, nunca para a resposta.
        ini_set('display_errors', $isProduction ? '0' : '1');

        $whoops = new \Whoops\Run;
        if (!$isProduction) {
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        } else {
            $whoops->pushHandler(function ($e) {
                Logger::error('Error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                $response = new Response;
                $response->setCode(self::resolveHttpStatus($e));
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

    /**
     * Converte o código de uma exceção em status HTTP.
     *
     * getCode() não é um status: PDOException devolve SQLSTATE ("42S02") e
     * muitas bibliotecas usam códigos próprios. Passar isso direto para
     * setCode(int) provocava TypeError dentro do próprio handler de erro.
     */
    private static function resolveHttpStatus(\Throwable $e): int
    {
        $code = $e->getCode();

        if (is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }

        return 500;
    }
}
