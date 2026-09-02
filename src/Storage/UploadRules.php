<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use InvalidArgumentException;
use NeoFramework\Core\Enums\FileStorageType;

/**
 * O que um upload precisa satisfazer para ser aceito.
 *
 * O tipo MIME é sempre o detectado do conteúdo. O que o cliente enviou como
 * nome ou content-type não participa da decisão em lugar nenhum.
 */
final readonly class UploadRules
{
    /** @param list<string>|null $mimeTypes allowlist explícita; null usa a do tipo */
    public function __construct(
        public int $maxBytes = 6_000_000,
        public ?array $mimeTypes = null,
        public FileStorageType $type = FileStorageType::IMAGE,
    ) {
        if ($maxBytes < 1) throw new InvalidArgumentException('maxBytes must be positive.');
        if ($mimeTypes === []) throw new InvalidArgumentException('An empty MIME allowlist would reject every upload.');
    }

    public static function images(int $maxBytes = 6_000_000): self
    {
        return new self($maxBytes, null, FileStorageType::IMAGE);
    }

    public static function documents(int $maxBytes = 6_000_000): self
    {
        return new self($maxBytes, null, FileStorageType::DOCUMENT);
    }

    /** @return list<string>|null null significa "qualquer MIME que o Core saiba nomear" */
    public function allowedMimeTypes(): array|null
    {
        return $this->mimeTypes ?? MimeTypes::forStorageType($this->type);
    }
}
