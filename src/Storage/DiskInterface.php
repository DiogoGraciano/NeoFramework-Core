<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

interface DiskInterface
{
    public function write(string $path, string $contents): void;

    /**
     * Grava consumindo um stream, sem materializar o conteúdo em memória.
     *
     * @param resource $stream
     */
    public function writeStream(string $path, $stream): void;

    /** @return resource */
    public function readStream(string $path);

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
