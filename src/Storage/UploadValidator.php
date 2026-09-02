<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use NeoFramework\Core\Enums\FileStorageType;

/** Validates an upload without filesystem persistence or presentation side effects. */
final class UploadValidator
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'htaccess', 'htpasswd', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com',
        'bat', 'cmd', 'jsp', 'asp', 'aspx', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'svgz', 'xhtml',
    ];

    private const EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'avif', 'webp', 'bmp'],
        'document' => ['pdf', 'doc', 'docx', 'rtf', 'txt', 'odt', 'odf'],
    ];

    /** Returns null when valid, otherwise a safe user-facing error message. */
    public function validate(string $path, string $originalName, int $maximumBytes, FileStorageType $type): ?string
    {
        return $this->extensionError($originalName, $type)
            ?? $this->mimeError($path, $type)
            ?? $this->sizeError($path, $maximumBytes);
    }

    public function extensionError(string $fileName, FileStorageType $type): ?string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return 'File type is not allowed';
        }

        $allowed = match ($type) {
            FileStorageType::IMAGE => self::EXTENSIONS['image'],
            FileStorageType::DOCUMENT => self::EXTENSIONS['document'],
            FileStorageType::ANY => null,
        };

        return $allowed !== null && !in_array($extension, $allowed, true) ? 'File type is not allowed' : null;
    }

    public function mimeError(string $path, FileStorageType $type): ?string
    {
        $mimeType = @mime_content_type($path);
        if ($mimeType === false) {
            return 'File type is not allowed';
        }

        $allowed = MimeTypes::forStorageType($type);

        return $allowed !== null && !in_array($mimeType, $allowed, true) ? 'File type is not allowed' : null;
    }

    public function sizeError(string $path, int $maximumBytes): ?string
    {
        $size = @filesize($path);

        return $size === false || $size > $maximumBytes ? 'File size is not allowed' : null;
    }

    /**
     * Valida o que foi lido de um upload PSR-7 e devolve a extensão que o
     * arquivo terá no disco.
     *
     * A extensão vem do MIME detectado. Um `.php` renomeado para `.jpg` é
     * recusado aqui pelo conteúdo, e um `.jpg` de verdade é gravado como `.jpg`
     * mesmo que o cliente tenha chamado de outra coisa.
     *
     * @throws UploadRejectedException
     */
    public function extensionForDetectedType(string $path, UploadRules $rules): string
    {
        $mimeType = $this->detect($path);
        $allowed = $rules->allowedMimeTypes();

        if ($allowed !== null && !in_array($mimeType, $allowed, true)) throw new UploadRejectedException('File type is not allowed');

        return MimeTypes::extensionFor($mimeType) ?? throw new UploadRejectedException('File type is not allowed');
    }

    public function detect(string $path): string
    {
        $mimeType = @mime_content_type($path);

        return $mimeType === false ? throw new UploadRejectedException('File type is not allowed') : strtolower($mimeType);
    }
}
