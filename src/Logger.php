<?php
namespace NeoFramework\Core;
use Monolog\Level;
use Monolog\Logger as L;
use Monolog\Handler\StreamHandler;

class Logger
{
    private static ?L $logger = null;

    /**
     * Instância compartilhada do logger.
     *
     * O FirePHPHandler foi removido: ele serializa cada registro em cabeçalhos
     * X-Wf-* da resposta HTTP, o que em produção entrega mensagens de erro e
     * stack traces direto ao cliente.
     */
    private static function load(): L
    {
        if (self::$logger === null) {
            $logger = new L('System');
            $logger->pushHandler(new StreamHandler(Functions::getRoot().'Logs/system.log', Level::Debug));

            self::$logger = $logger;
        }

        return self::$logger;
    }

    /**
     * Descarta a instância. Útil em testes.
     */
    public static function reset(): void
    {
        self::$logger = null;
    }

    public static function debug(array|string $message){
        self::load()->debug($message);
    }

    public static function info(array|string $message){
        self::load()->info($message);
    }

    public static function notice(array|string $message){
        self::load()->notice($message);
    }

    public static function warning(array|string $message){
        self::load()->warning($message);
    }
    public static function error(array|string $message){
        self::load()->error($message);
    }

    public static function critical(array|string $message){
        self::load()->critical($message);
    }

    public static function alert(array|string $message){
        self::load()->alert($message);
    }

    public static function emergency(array|string $message){
        self::load()->emergency($message);
    }
}