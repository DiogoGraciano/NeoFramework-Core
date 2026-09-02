<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

/** Gera arquivos PHP a partir de stubs, sem permitir traversal ou overwrite acidental. */
final readonly class StubGenerator
{
    public function __construct(private string $projectRoot, private string $frameworkRoot) {}

    /**
     * @param array{directory:string,namespace:string,suffix:string,stub:string} $type
     * @return array{status:'created'|'exists',path:string,class:string}
     */
    public function make(string $name, array $type, bool $force = false): array
    {
        $parts = $this->parts($name);
        $class = array_pop($parts);
        if ($class === null) throw new \InvalidArgumentException('A class name is required.');
        if (!str_ends_with($class, $type['suffix'])) $class .= $type['suffix'];

        $namespace = $type['namespace'] . ($parts === [] ? '' : '\\' . implode('\\', $parts));
        $directory = rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . $type['directory']
            . ($parts === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts));
        $path = $directory . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$force) return ['status' => 'exists', 'path' => $path, 'class' => $namespace . '\\' . $class];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory {$directory}.");
        }

        $content = strtr($this->stub($type['stub']), ['{{ namespace }}' => $namespace, '{{ class }}' => $class]);
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new \RuntimeException("Unable to write {$path}.");

        return ['status' => 'created', 'path' => $path, 'class' => $namespace . '\\' . $class];
    }

    /** @return list<string> */
    private function parts(string $name): array
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_contains($name, '..')) throw new \InvalidArgumentException('Name must be a relative class name.');

        $parts = explode('/', $name);
        foreach ($parts as &$part) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $part) !== 1) throw new \InvalidArgumentException("Invalid class segment: {$part}");
            $part = str_replace('_', '', ucwords($part, '_'));
        }
        unset($part);

        return $parts;
    }

    private function stub(string $name): string
    {
        $project = rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . $name . '.stub';
        $framework = rtrim($this->frameworkRoot, '/\\') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . $name . '.stub';
        $path = is_file($project) ? $project : $framework;
        $stub = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($stub)) throw new \RuntimeException("Stub not found: {$name}.stub");

        return $stub;
    }
}
