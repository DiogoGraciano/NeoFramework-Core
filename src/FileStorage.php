<?php

namespace NeoFramework\Core;

use NeoFramework\Core\Enums\FileStorageDisk;
use NeoFramework\Core\Enums\FileStorageType;
use Aws\S3\S3Client;
use Exception;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

class FileStorage
{
    /**
     * Extensões nunca aceitas, mesmo com FileStorageType::ANY.
     *
     * Um arquivo gravado sob public/ com uma destas extensões é executado pelo
     * servidor web, transformando upload em execução remota de código.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'htaccess', 'htpasswd', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com',
        'bat', 'cmd', 'jsp', 'asp', 'aspx', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'svgz', 'xhtml',
    ];

    /**
     * Extensões aceitas por tipo, cruzadas com o MIME detectado.
     */
    private const ALLOWED_EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'avif', 'webp', 'bmp'],
        'document' => ['pdf', 'doc', 'docx', 'rtf', 'txt', 'odt', 'odf'],
    ];

    private string $rootPath;
    private Filesystem $filesystem;

    public function __construct(FileStorageDisk $disk = FileStorageDisk::LOCAL, $rootPath = "public/assets")
    {
        $this->rootPath = $rootPath;
        if ($disk == FileStorageDisk::LOCAL)
            $adapter = new LocalFilesystemAdapter(
                Functions::getRoot() . DIRECTORY_SEPARATOR . $this->rootPath,
                PortableVisibilityConverter::fromArray([
                    'file' => [
                        'public' => 0644,
                        'private' => 0644,
                    ],
                    'dir' => [
                        'public' => 0755,
                        'private' => 0755,
                    ],
                ])
            );
        else {
            if (!class_exists(S3Client::class) || !class_exists(AwsS3V3Adapter::class)) {
                throw new Exception(
                    "O disco S3 exige as dependências opcionais 'aws/aws-sdk-php' e " .
                    "'league/flysystem-aws-s3-v3'. Instale-as com composer require."
                );
            }

            $client = new S3Client(env("AWS_CLIENT"));
            $adapter = new AwsS3V3Adapter($client, env("AWS_BUCKETNAME"));
        }

        $this->filesystem = new Filesystem($adapter);
    }

    public function saveByPath(string $path, string $folder = "images", string $name = "", $maxSizeBytes = 6000000, FileStorageType $type = FileStorageType::IMAGE): bool|string
    {
        if (file_exists($path)) {

            $originalName = basename($name ?: $path);

            if (!$this->validExtension($originalName, $type)) {
                return false;
            }

            if (!$this->validType($path, $type)) {
                return false;
            }

            if (!$this->validSize($path, $maxSizeBytes)) {
                return false;
            }

            $completePath = $folder . DIRECTORY_SEPARATOR . $this->buildStoredName($originalName);

            try {
                $this->filesystem->write($completePath, file_get_contents($path), []);
                return DIRECTORY_SEPARATOR . Functions::getAbsolutePath($this->rootPath . DIRECTORY_SEPARATOR . $completePath);
            } catch (Exception $e) {
                Logger::error($e->getMessage() . $e->getTraceAsString());
                Message::setError("Error while saving file");
                return false;
            }
        }

        Message::setError("File not found");
        return false;
    }

    public function saveFromRequest(array $fileArray, string $destinationFolder = "images", $maxSizeBytes = 6000000, FileStorageType $type = FileStorageType::IMAGE): bool|string
    {
        if (isset($fileArray["tmp_name"]) && $fileArray['error'] == 0) {

            // O caminho precisa ter vindo de um upload HTTP; caso contrário um
            // tmp_name forjado permitiria copiar qualquer arquivo do servidor.
            if (PHP_SAPI !== 'cli' && !is_uploaded_file($fileArray["tmp_name"])) {
                Message::setError("Invalid upload");
                return false;
            }

            $originalName = basename((string) ($fileArray['name'] ?? ''));

            if (!$this->validExtension($originalName, $type)) {
                return false;
            }

            if (!$this->validType($fileArray["tmp_name"], $type)) {
                return false;
            }

            if (!$this->validSize($fileArray["tmp_name"], $maxSizeBytes)) {
                return false;
            }

            $fullPath = $destinationFolder . DIRECTORY_SEPARATOR . $this->buildStoredName($originalName);

            try {
                $this->filesystem->write($fullPath, file_get_contents($fileArray['tmp_name']));
                return DIRECTORY_SEPARATOR . Functions::getAbsolutePath($this->rootPath . DIRECTORY_SEPARATOR . $fullPath);
            } catch (Exception $e) {
                Logger::error($e->getMessage() . $e->getTraceAsString());
                Message::setError("Error while saving file");
                return false;
            }
        }

        Message::setError("File not found in the request");
        return false;
    }

    /**
     * Valida a extensão do arquivo.
     *
     * A checagem de MIME sozinha não basta: quem grava o arquivo sob public/ é
     * a extensão que determina se o servidor web vai executá-lo.
     */
    public function validExtension(string $fileName, FileStorageType $type): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension === '') {
            Message::setError("File type is not allowed");
            return false;
        }

        // Vale inclusive para FileStorageType::ANY
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            Message::setError("File type is not allowed");
            return false;
        }

        $allowed = match ($type) {
            FileStorageType::IMAGE => self::ALLOWED_EXTENSIONS['image'],
            FileStorageType::DOCUMENT => self::ALLOWED_EXTENSIONS['document'],
            default => null,
        };

        if ($allowed !== null && !in_array($extension, $allowed, true)) {
            Message::setError("File type is not allowed");
            return false;
        }

        return true;
    }

    /**
     * Valida o MIME real do conteúdo.
     *
     * image/svg+xml está fora da lista de propósito: um SVG servido do próprio
     * domínio executa JavaScript, o que torna o upload um vetor de XSS.
     */
    public function validType(string $filePath, FileStorageType $type): bool
    {
        // mime_content_type não lança: devolve false e emite warning.
        $mimeType = @mime_content_type($filePath);

        if ($mimeType === false) {
            Message::setError("File type is not allowed");
            return false;
        }

        $allowedTypes = [];
        if ($type == FileStorageType::DOCUMENT)
            $allowedTypes = ["application/pdf", "application/doc", "application/docx", "application/rtf", "application/txt", "text/plain", "application/odf", "application/vnd.oasis.opendocument.text", "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"];
        elseif ($type == FileStorageType::IMAGE)
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/webp', 'image/bmp'];

        if ($type != FileStorageType::ANY && !in_array($mimeType, $allowedTypes, true)) {
            Message::setError("File type is not allowed");
            return false;
        }

        return true;
    }

    /**
     * Nome com que o arquivo será gravado.
     *
     * O nome original não é reaproveitado: ele é controlado pelo cliente e
     * serviria para sobrescrever arquivos ou adivinhar caminhos. Só a extensão,
     * já validada, é preservada.
     */
    private function buildStoredName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $slug = Functions::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $slug = substr($slug, 0, 60);

        $name = bin2hex(random_bytes(16));

        if ($slug !== '') {
            $name .= '_' . $slug;
        }

        return $extension === '' ? $name : $name . '.' . $extension;
    }

    public function validSize(string $filePath, int $maxSize)
    {
        // filesize() devolve false e emite warning; não lança.
        $fileSize = @filesize($filePath);

        if ($fileSize === false) {
            Message::setError("File size is not allowed");
            return false;
        }

        if ($fileSize > $maxSize) {
            Message::setError("File size is not allowed");
            return false;
        }

        return true;
    }

    public function saveFromString(string $fullPath = "images", string $contents = ""): bool
    {
        try {
            $this->filesystem->write($fullPath, $contents);
            return true;
        } catch (Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            Message::setError("Error while saving file");
            return false;
        }
    }

    public function delete(string $filePath): bool
    {
        try {
            $this->filesystem->delete($filePath);
            return true;
        } catch (FilesystemException | UnableToRetrieveMetadata $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            Message::setError("Error while deleting file");
            return false;
        }
    }
}