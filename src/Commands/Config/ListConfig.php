<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Config;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Config\ConfigRepository;

final class ListConfig extends Command
{
    public function __construct()
    {
        parent::__construct('config:list', 'List effective configuration with secrets redacted');
        $this->option('-j, --json', 'Emit stable JSON');
    }

    public function execute(): int
    {
        $items = self::redact(ConfigRepository::fromRoot()->all());
        if ($this->json) {
            echo json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        }
        foreach (self::flatten($items) as $key => $value) {
            echo $key . '=' . (is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_THROW_ON_ERROR)) . PHP_EOL;
        }
        return 0;
    }

    /** @param array<string,mixed> $items @return array<string,mixed> */
    public static function redact(array $items): array
    {
        foreach ($items as $key => $value) {
            if (preg_match('/(secret|password|token|key|credential)/i', (string) $key)) {
                $items[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $items[$key] = self::redact($value);
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $items @return array<string,mixed> */
    private static function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            $name = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value) && !array_is_list($value)) $flat += self::flatten($value, $name);
            else $flat[$name] = $value;
        }
        ksort($flat);
        return $flat;
    }
}
