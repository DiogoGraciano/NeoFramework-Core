<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use League\Flysystem\UnableToReadFile;
use RuntimeException;

/**
 * Disco sem I/O, para testes e para o contract test dos discos.
 *
 * Mantém o mesmo contrato dos demais: o que passa aqui e falha em disco real é
 * diferença de adapter, não de expectativa.
 */
final class InMemoryDisk implements DiskInterface
{
    /** @var array<string,string> */
    private array $files = [];

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function writeStream(string $path, $stream): void
    {
        $contents = stream_get_contents($stream);
        if ($contents === false) throw new RuntimeException("Unable to read the stream written to '{$path}'.");

        $this->files[$path] = $contents;
    }

    public function readStream(string $path)
    {
        if (!isset($this->files[$path])) throw UnableToReadFile::fromLocation($path);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) throw new RuntimeException('Unable to open a temporary stream.');

        fwrite($stream, $this->files[$path]);
        rewind($stream);

        return $stream;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }
}
