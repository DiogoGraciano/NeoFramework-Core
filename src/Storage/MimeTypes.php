<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use NeoFramework\Core\Enums\FileStorageType;

/**
 * Allowlists por tipo e a extensão canônica de cada MIME.
 *
 * A extensão do arquivo gravado sai **daqui**, a partir do MIME detectado no
 * conteúdo — nunca do nome enviado pelo cliente. Um MIME que este mapa não sabe
 * nomear é recusado: sem extensão conhecida não há como afirmar que servir o
 * arquivo é seguro.
 */
final class MimeTypes
{
    private const IMAGE = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/webp', 'image/bmp'];

    private const DOCUMENT = [
        'application/pdf', 'application/doc', 'application/docx', 'application/rtf', 'application/txt',
        'text/plain', 'application/odf', 'application/vnd.oasis.opendocument.text', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** @var array<string,string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/rtf' => 'rtf',
        'application/rtf' => 'rtf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/zip' => 'zip',
    ];

    private function __construct() {}

    /** @return list<string>|null null quando o tipo não restringe MIME */
    public static function forStorageType(FileStorageType $type): ?array
    {
        return match ($type) {
            FileStorageType::IMAGE => self::IMAGE,
            FileStorageType::DOCUMENT => self::DOCUMENT,
            FileStorageType::ANY => null,
        };
    }

    public static function extensionFor(string $mimeType): ?string
    {
        return self::EXTENSIONS[strtolower($mimeType)] ?? null;
    }
}
