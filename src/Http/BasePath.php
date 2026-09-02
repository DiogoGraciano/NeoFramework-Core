<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Config;

/**
 * Prefixo sob o qual a aplicação está montada.
 *
 * Sem isso uma instalação em subdiretório é irroteável: "/app/users/1" tenta
 * casar a rota "/app/users/1" em vez de "/users/1".
 */
final class BasePath
{
    /**
     * APP_BASE_PATH ganha de SCRIPT_NAME: sob `try_files` do nginx e algumas
     * configurações de FPM o SCRIPT_NAME não corresponde ao prefixo real, então
     * a derivação é conveniência, não contrato.
     */
    public static function resolve(array $server = []): string
    {
        $explicit = Config::get('http.base_path');
        if (is_string($explicit) && trim($explicit, '/') !== '') return '/' . trim($explicit, '/');
        if ($explicit !== null) return '';

        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($script === '') return '';

        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $directory === '' || $directory === '.' ? '' : $directory;
    }

    /** Remove o prefixo do path, respeitando a fronteira de segmento. */
    public static function strip(string $path, string $base): string
    {
        if ($base === '' || $base === '/') return $path === '' ? '/' : $path;
        if (strcasecmp($path, $base) === 0) return '/';
        if (!str_starts_with($path, $base . '/')) return $path === '' ? '/' : $path;

        $stripped = substr($path, strlen($base));
        return $stripped === '' ? '/' : $stripped;
    }

    /** Prefixa um path gerado, sem duplicar barras. */
    public static function prepend(string $path, string $base): string
    {
        if ($base === '' || $base === '/') return $path;
        return $base . '/' . ltrim($path, '/');
    }
}
