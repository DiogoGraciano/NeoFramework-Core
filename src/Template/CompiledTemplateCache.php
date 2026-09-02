<?php
declare(strict_types=1);

namespace NeoFramework\Core\Template;

use NeoFramework\Core\Config;
use NeoFramework\Core\Support\ProjectRoot;

/** Disk cache for compiler output, isolated from template parsing and rendering. */
final class CompiledTemplateCache
{
    /** Increment when the serialized compiler output changes. */
    private const VERSION = 2;

    private function __construct(private readonly ?string $directory)
    {
    }

    public static function fromDirectory(?string $directory = null): self
    {
        if ($directory !== null) {
            $directory = trim($directory);

            return new self($directory === '' ? null : rtrim($directory, '/'));
        }

        if (!Config::get('template.cache', true)) {
            return new self(null);
        }

        $directory = ProjectRoot::path() . 'Cache/templates';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return new self(null);
        }

        return new self(is_writable($directory) ? $directory : null);
    }

    /** @return array{body:array,keys:array,children:array,finally:array,vars:array,blocks:array}|null */
    public function get(string $filename, bool $accurate): ?array
    {
        $file = $this->file($filename, $accurate);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $compiled = require $file;

        return is_array($compiled) ? $compiled : null;
    }

    /** @param array{body:array,keys:array,children:array,finally:array,vars:array,blocks:array} $compiled */
    public function put(string $filename, bool $accurate, array $compiled): void
    {
        $file = $this->file($filename, $accurate);
        if ($file === null) {
            return;
        }

        $temporary = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, '<?php return ' . var_export($compiled, true) . ';') === false) {
            return;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
        }
    }

    private function file(string $filename, bool $accurate): ?string
    {
        if ($this->directory === null) {
            return null;
        }

        $key = md5(implode('|', [
            self::VERSION,
            realpath($filename) ?: $filename,
            (string) filemtime($filename),
            $accurate ? '1' : '0',
        ]));

        return $this->directory . '/' . $key . '.php';
    }
}
