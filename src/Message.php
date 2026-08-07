<?php

namespace NeoFramework\Core;

use NeoFramework\Core\Session;

/**
 * Mensagens de uma requisição para a próxima (flash messages).
 *
 * Os setters acumulam: uma operação que reporta vários problemas não perde
 * todos menos o último.
 */
class Message
{
    public static function clean(): void
    {
        Session::set("Error", []);
        Session::set("Message", []);
        Session::set("Sucessos", []);
    }

    public static function getError():array
    {
        return Session::get("Error")?:[];
    }

    public static function setError(...$erros):void
    {
        self::append("Error", $erros);
    }

    public static function getMessage():array
    {
        return Session::get("Message")?:[];
    }

    public static function setMessage(...$Mensagens):void
    {
        self::append("Message", $Mensagens);
    }

    public static function getSuccess():array
    {
        return Session::get("Sucessos")?:[];
    }

    public static function setSuccess(...$Sucessos):void
    {
        self::append("Sucessos", $Sucessos);
    }

    /**
     * Substitui o conteúdo da chave, descartando o que já estava lá.
     */
    public static function replaceError(...$erros):void
    {
        Session::set("Error", $erros);
    }

    private static function append(string $key, array $values): void
    {
        $current = Session::get($key);
        $current = is_array($current) ? $current : [];

        Session::set($key, array_merge($current, $values));
    }
}
