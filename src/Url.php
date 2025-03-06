<?php

namespace NeoFramework\Core;

final class Url
{
    public static function getUriPath(): string
    {
        return $_SERVER['REQUEST_URI'] ? strtok($_SERVER['REQUEST_URI'], '?') : '/';
    }

    public static function getPathRouter(): string
    {
        if (substr_count(self::getUriPath(), '/') > 1) {
            $method = array_values(array_filter(explode('/', self::getUriPath())));
            if (array_key_exists(1, $method))
                return $method[1];
        }

        return "index";
    }

    public static function getUriQuery(): string
    {
        return $_SERVER['QUERY_STRING'] ?? '';
    }

    public static function getUriQueryArray(): array
    {
        $result = [];
        $query = Url::getUriQuery();

        !$query ?: parse_str($query, $result);

        return $result ? $result : [];
    }

    public static function getControlerByUri(){

        $uri = self::getUriPath();

        if($uri == "/"){
            return "inicio";
        }

        if(substr_count($uri,'/') > 1){
            list($controller) = array_values(array_filter(explode('/',$uri)));
            return (($controller));
        }
        return ((ltrim($uri,"/")));
    }

    public static function getUrlBase(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $isSecure = (!empty($https) && $https !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? false) == 443 || ($_SERVER['SERVER_PORT'] ?? false) == 443;

        $protocol = $isSecure ? 'https' : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        return rtrim($protocol . "://" . $host, '/') . '/';
    }

    public static function getUrlCompleta()
    {
        return rtrim(self::getUrlBase(), "/") . self::getUriPath();
    }
}
