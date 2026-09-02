<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

/** Publica os stubs do framework para permitir customização no projeto. */
final readonly class StubPublisher
{
    public function __construct(private string $projectRoot, private string $frameworkRoot) {}

    /** @return array{created:int,exists:int} */
    public function publish(bool $force = false): array
    {
        $source = rtrim($this->frameworkRoot, '/\\') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs';
        $target = rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs';
        if (!is_dir($source)) throw new \RuntimeException('Framework stubs directory is missing.');
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) throw new \RuntimeException("Unable to create {$target}.");

        $result = ['created' => 0, 'exists' => 0];
        foreach (glob($source . DIRECTORY_SEPARATOR . '*.stub') ?: [] as $file) {
            $destination = $target . DIRECTORY_SEPARATOR . basename($file);
            if (is_file($destination) && !$force) { $result['exists']++; continue; }
            if (copy($file, $destination) === false) throw new \RuntimeException("Unable to publish {$destination}.");
            $result['created']++;
        }

        return $result;
    }
}
