<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

/** Um upload aceito e persistido. */
final readonly class StoredFile
{
    public function __construct(
        /** Caminho público, relativo à raiz da aplicação. */
        public string $path,
        /** Chave dentro do disco, que `DiskInterface` entende. */
        public string $storagePath,
        public int $size,
        public string $mimeType,
    ) {}

    public function __toString(): string
    {
        return $this->path;
    }
}
