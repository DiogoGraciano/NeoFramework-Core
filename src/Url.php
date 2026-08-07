<?php

namespace NeoFramework\Core;

final class Url
{
    public static function getUriPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return $uri ? strtok($uri, '?') : '/';
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
            return "home";
        }

        if(substr_count($uri,'/') > 1){
            list($controller) = array_values(array_filter(explode('/',$uri)));
            return (($controller));
        }
        return ((ltrim($uri,"/")));
    }

    /**
     * Indica se a requisição chegou por HTTPS.
     *
     * Cabeçalhos X-Forwarded-* são controlados pelo cliente e só são levados em
     * conta quando o REMOTE_ADDR está declarado em TRUSTED_PROXIES.
     */
    public static function isSecure(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        if (!empty($https) && strtolower((string) $https) !== 'off') {
            return true;
        }

        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }

        if (self::isFromTrustedProxy()) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

            if ($proto === 'https') {
                return true;
            }

            if (($_SERVER['HTTP_X_FORWARDED_PORT'] ?? null) == 443) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se a requisição veio de um proxy declarado em TRUSTED_PROXIES.
     */
    public static function isFromTrustedProxy(): bool
    {
        $trusted = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));

        if (!$trusted) {
            return false;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return $remote !== '' && in_array($remote, $trusted, true);
    }

    /**
     * URL base da aplicação, sempre terminada em barra.
     *
     * O Host informado pelo cliente só é usado quando APP_URL não está definida
     * e o host consta em TRUSTED_HOSTS; caso contrário cai para SERVER_NAME.
     * Sem isso, um Host forjado contamina qualquer link gerado (redirects,
     * e-mails de recuperação de senha, cache).
     */
    public static function getUrlBase(): string
    {
        $appUrl = (string) env('APP_URL');

        if ($appUrl !== '') {
            return rtrim($appUrl, '/') . '/';
        }

        $protocol = self::isSecure() ? 'https' : 'http';

        return rtrim($protocol . "://" . self::resolveHost(), '/') . '/';
    }

    /**
     * Resolve o host da requisição respeitando a allowlist TRUSTED_HOSTS.
     */
    private static function resolveHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $trusted = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS'))));

        if ($host !== '' && self::isValidHost($host)) {
            if (!$trusted || in_array(strtolower($host), array_map('strtolower', $trusted), true)) {
                return $host;
            }
        }

        $fallback = (string) ($_SERVER['SERVER_NAME'] ?? '');

        if ($fallback !== '' && self::isValidHost($fallback)) {
            return $fallback;
        }

        return $trusted[0] ?? 'localhost';
    }

    /**
     * Recusa hosts com caracteres que permitiriam injeção de cabeçalho ou path.
     */
    private static function isValidHost(string $host): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-._]+(:\d{1,5})?$/', $host);
    }

    public static function getUrlCompleta()
    {
        return rtrim(self::getUrlBase(), "/") . self::getUriPath();
    }
}
