<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use Dotenv\Dotenv;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\ConfigValidator;
use NeoFramework\Core\Http\ResponseEmitter;

class Kernel
{
    public static function loadEnv(): void
    {
        $dotenv = Dotenv::createImmutable(\NeoFramework\Core\Support\ProjectRoot::path());
        $dotenv->safeLoad();
        Config::reset();
    }

    public static function init(): void
    {
        error_reporting(E_ALL);

        self::loadEnv();
        ConfigValidator::validate(Config::repository()->all());

        $isProduction = AppConfig::from(Config::repository())->isProduction();

        // Em produção os detalhes vão para o log, nunca para a resposta.
        ini_set('display_errors', $isProduction ? '0' : '1');

        $whoops = new \Whoops\Run;
        if (!$isProduction) {
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        } else {
            $whoops->pushHandler(function ($e) {
                Logger::error('Error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                (new ResponseEmitter())->emit(new Response(self::resolveHttpStatus($e)));
            });
        }
        $whoops->register();

        Session::start();

        $response = (new Application())->boot()->handle(Request::fromGlobals());
        (new ResponseEmitter())->emit($response);
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
