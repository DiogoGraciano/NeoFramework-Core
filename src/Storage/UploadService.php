<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use InvalidArgumentException;
use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\Support\Id;
use NeoFramework\Core\Support\Path;
use NeoFramework\Core\Support\Str;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/** Validates a source file and persists it under a server-generated storage key. */
final class UploadService
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly FilePersister $persister,
        private readonly UploadScannerInterface $scanner = new NullUploadScanner(),
    ) {
    }

    /**
     * Persiste um upload PSR-7 sem carregá-lo inteiro em memória.
     *
     * @throws UploadRejectedException quando o cliente errou
     */
    public function storeUploadedFile(UploadedFileInterface $file, string $directory, UploadRules $rules): StoredFile
    {
        if ($file->getError() !== UPLOAD_ERR_OK) throw new UploadRejectedException(self::errorMessage($file->getError()));

        $temporary = UploadedFileReader::toTemporaryFile($file->getStream(), $rules->maxBytes);

        try {
            $extension = $this->validator->extensionForDetectedType($temporary['path'], $rules);
            $mimeType = $this->validator->detect($temporary['path']);

            // Roda sobre o temporário e antes da gravação: um veredito negativo
            // significa que nada chegou a ser publicado.
            $this->scanner->scan($temporary['path'], $mimeType);

            $storagePath = Path::normalizeRelative($directory) . DIRECTORY_SEPARATOR . $this->storedName($file->getClientFilename() ?? '', $extension);

            $path = $this->persistStream($temporary['path'], $storagePath);

            return new StoredFile($path, $storagePath, $temporary['size'], $mimeType);
        } finally {
            @unlink($temporary['path']);
        }
    }

    /** Copia o temporário para o disco e não deixa arquivo pela metade se falhar. */
    private function persistStream(string $temporaryPath, string $storagePath): string
    {
        $handle = fopen($temporaryPath, 'rb');
        if ($handle === false) throw new RuntimeException('Unable to read the temporary upload file.');

        try {
            return $this->persister->writeStream($storagePath, $handle);
        } catch (Throwable $error) {
            $this->persister->deleteIfExists($storagePath);
            throw $error;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
    }

    private static function errorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File size is not allowed',
            UPLOAD_ERR_NO_FILE => 'File not found in the request',
            // O resto é falha de servidor (sem diretório temporário, disco cheio,
            // extensão que abortou). Não descreva a causa para o cliente.
            default => 'Error while saving file',
        };
    }

    public function store(string $source, string $originalName, string $folder, int $maximumBytes, FileStorageType $type): string
    {
        if (!is_file($source)) {
            throw new InvalidArgumentException('File not found');
        }

        $error = $this->validator->validate($source, $originalName, $maximumBytes, $type);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException('Unable to read upload source.');
        }

        return $this->persister->write(Path::normalizeRelative($folder) . DIRECTORY_SEPARATOR . $this->storedName($originalName), $contents);
    }

    private function storedName(string $originalName, ?string $extension = null): string
    {
        $extension ??= strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $slug = substr(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)), 0, 60);
        $name = Id::randomHex();

        if ($slug !== '') {
            $name .= '_' . $slug;
        }

        return $name . '.' . $extension;
    }
}
