<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use League\Flysystem\FilesystemOperator;

/** Adapter for the optional Flysystem implementation behind the Core disk contract. */
final class FlysystemDisk implements DiskInterface
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    public function write(string $path, string $contents): void
    {
        $this->filesystem->write($path, $contents);
    }

    public function writeStream(string $path, $stream): void
    {
        $this->filesystem->writeStream($path, $stream);
    }

    public function readStream(string $path)
    {
        return $this->filesystem->readStream($path);
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    public function delete(string $path): void
    {
        $this->filesystem->delete($path);
    }
}
