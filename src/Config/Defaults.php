<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

/** Framework defaults. Application values always come from the project Config/. */
final class Defaults
{
    /** @return array<string,mixed> */
    public static function all(): array
    {
        return [
            'app' => ['environment' => 'dev', 'url' => ''],
            'http' => ['base_path' => null, 'trusted_proxies' => [], 'trusted_hosts' => []],
            'cors' => ['enabled' => false, 'allowed_origins' => ['*'], 'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'], 'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'], 'exposed_headers' => [], 'max_age' => 86400, 'allow_credentials' => false],
            'security_headers' => ['enabled' => true, 'vite_csp_relax' => true, 'headers' => []],
            'session' => ['same_site' => 'Lax', 'http_only' => true, 'secure' => null],
            'cache' => ['adapter' => 'filesystem', 'redis' => ['host' => '', 'port' => 6379, 'password' => ''], 'memcached' => ['host' => '', 'port' => 11211, 'user' => '', 'password' => '']],
            'logging' => ['channel' => 'system', 'level' => 'debug', 'format' => 'line', 'stream' => 'file', 'path' => 'Logs/system.log'],
            'queue' => ['driver' => 'files', 'files' => ['path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neoframework_jobs', 'default_ttl' => 86400, 'lock_ttl' => 60], 'redis' => ['host' => '', 'port' => 6379, 'password' => '', 'prefix' => 'neoframework:jobs:']],
            'database' => ['driver' => ''],
            'template' => ['cache' => true],
            'vite' => ['enabled_in_production' => false],
            'mail' => ['host' => '', 'port' => 0, 'username' => '', 'password' => '', 'encryption' => '', 'from_address' => '', 'from_name' => 'Site'],
            'crypto' => ['encryption_key' => '', 'authentication_key' => ''],
            'storage' => ['disk' => 'local', 'root_path' => 'public/assets', 's3' => ['client' => [], 'bucket' => '']],
            'runtime' => ['stateful_services' => []],
            'rate_limit' => ['store' => 'cache', 'strategy' => 'fixed_window', 'on_failure' => 'open', 'redis' => ['host' => '', 'port' => 6379, 'password' => '', 'prefix' => 'neoframework:rl:', 'timeout' => 0.5], 'policies' => ['login' => ['limit' => 5, 'window' => 900, 'key' => 'ip']]],
            // O bloqueio de força bruta é ligado por padrão. Deixá-lo desligado
            // faria a proteção depender de o projeto lembrar de configurá-la, que
            // é exatamente o que não acontece.
            'auth' => ['login_throttle' => ['limit' => 5, 'window' => 900]],
        ];
    }
}
