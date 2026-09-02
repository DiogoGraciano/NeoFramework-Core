<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as L;
use NeoFramework\Core\Config\LoggingConfig;
use NeoFramework\Core\Http\RequestScopeContext;
use Psr\Log\LoggerInterface;

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
            $config = LoggingConfig::from(Config::repository());

            // stderr é o default correto em container: o coletor lê a saída do
            // processo, e um arquivo dentro do container se perde no restart.
            $target = match ($config->stream) {
                'stderr' => 'php://stderr',
                'stdout' => 'php://stdout',
                default => str_starts_with($config->path, '/') ? $config->path : \NeoFramework\Core\Support\ProjectRoot::path() . $config->path,
            };

            $handler = new StreamHandler($target, Level::fromName($config->level));

            // Uma entrada por linha, JSON válido: é o que um coletor consegue
            // indexar sem parser próprio.
            if ($config->format === 'json') {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, appendNewline: true));
            }

            $logger = new L($config->channel);
            $logger->pushHandler($handler);

            self::$logger = $logger;
        }

        return self::$logger;
    }

    /**
     * Canal adicional, para separar HTTP, filas, scheduler e segurança.
     *
     * @var array<string,L>
     */
    private static array $channels = [];

    public static function channel(string $name): LoggerInterface
    {
        return self::$channels[$name] ??= self::load()->withName($name);
    }

    public static function instance(): LoggerInterface
    {
        return self::load();
    }

    /**
     * Descarta a instância. Útil em testes.
     */
    public static function reset(): void
    {
        self::$logger = null;
        self::$channels = [];
    }

    public static function debug(array|string $message){
        self::log('debug', $message);
    }

    public static function info(array|string $message){
        self::log('info', $message);
    }

    public static function notice(array|string $message){
        self::log('notice', $message);
    }

    public static function warning(array|string $message){
        self::log('warning', $message);
    }
    public static function error(array|string $message){
        self::log('error', $message);
    }

    public static function critical(array|string $message){
        self::log('critical', $message);
    }

    public static function alert(array|string $message){
        self::log('alert', $message);
    }

    public static function emergency(array|string $message){
        self::log('emergency', $message);
    }

    private static function log(string $level, array|string $message): void
    {
        $scope = RequestScopeContext::current();
        $context = $scope?->get('neoframework.log_context', []);
        self::load()->log($level, is_string($message) ? self::redact($message) : 'Log context', is_array($context) ? [...$context, ...(is_array($message) ? self::redact($message) : [])] : []);
    }

    private static function redact(array|string $message): array|string
    {
        $names = self::sensitiveNames();

        if (is_array($message)) {
            foreach ($message as $key => $value) {
                $message[$key] = preg_match('/(authorization|cookie|password|secret|token|key)/i', (string) $key)
                    || in_array((string) $key, $names, true)
                    ? '[redacted]'
                    : (is_array($value) || is_string($value) ? self::redact($value) : $value);
            }
            return $message;
        }

        $extra = $names === [] ? '' : '|' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));

        return preg_replace('/(?i)\b(authorization|cookie|password|secret|token|api[_-]?key' . $extra . ')\s*[=:]\s*[^\s,;]+/', '$1=[redacted]', $message) ?? $message;
    }

    /**
     * Campos que um DTO marcou com #[Sensitive] nesta requisição.
     *
     * A redação por nome de chave cobre os suspeitos habituais (password,
     * token...), mas não um campo de negócio sensível com nome próprio — "cpf",
     * "pin", "recoveryAnswer". #[Sensitive] é como o desenvolvedor declara esses.
     *
     * @return list<string>
     */
    private static function sensitiveNames(): array
    {
        $names = RequestScopeContext::current()?->get(\NeoFramework\Core\Http\DtoBinder::SENSITIVE_KEYS, []);

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }
}
