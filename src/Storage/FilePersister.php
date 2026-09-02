<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use NeoFramework\Core\Support\Path;

/** Persists already-validated content and maps storage keys to public relative paths. */
final class FilePersister
{
    public function __construct(
        private readonly DiskInterface $disk,
        private readonly string $rootPath,
    ) {
    }

    public function write(string $storagePath, string $contents): string
    {
        $this->disk->write($storagePath, $contents);

        return $this->publicPath($storagePath);
    }

    private function publicPath(string $storagePath): string
    {
        return DIRECTORY_SEPARATOR . Path::normalizeRelative($this->rootPath . DIRECTORY_SEPARATOR . $storagePath);
    }

    /** @param resource $stream */
    public function writeStream(string $storagePath, $stream): string
    {
        $this->disk->writeStream($storagePath, $stream);

        return $this->publicPath($storagePath);
    }

    public function writeRaw(string $storagePath, string $contents): void
    {
        $this->disk->write($storagePath, $contents);
    }

    /** Remove sem exigir que o arquivo exista — usado na limpeza de gravação parcial. */
    public function deleteIfExists(string $storagePath): void
    {
        try {
            if ($this->disk->exists($storagePath)) $this->disk->delete($storagePath);
        } catch (\Throwable) {
            // A causa original da falha é o que interessa; esconder-la atrás de
            // um erro de limpeza tornaria o problema real invisível.
        }
    }

    public function delete(string $storagePath): void
    {
        $this->disk->delete($storagePath);
    }
}
