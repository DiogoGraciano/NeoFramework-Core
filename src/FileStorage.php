<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use Exception;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToRetrieveMetadata;
use NeoFramework\Core\Config\StorageConfig;
use NeoFramework\Core\Enums\FileStorageDisk;
use NeoFramework\Core\Enums\FileStorageType;
use NeoFramework\Core\Storage\FilePersister;
use NeoFramework\Core\Storage\FilesystemFactory;
use NeoFramework\Core\Storage\NullUploadScanner;
use NeoFramework\Core\Storage\StoredFile;
use NeoFramework\Core\Storage\UploadRules;
use NeoFramework\Core\Storage\UploadScannerInterface;
use NeoFramework\Core\Storage\UploadService;
use NeoFramework\Core\Storage\UploadValidator;
use Psr\Http\Message\UploadedFileInterface;

class FileStorage
{
    private string $rootPath;
    private FilePersister $persister;
    private UploadService $uploads;

    public function __construct(?FileStorageDisk $disk = null, ?string $rootPath = null, ?StorageConfig $config = null, ?UploadScannerInterface $scanner = null)
    {
        $config ??= StorageConfig::from(Config::repository());
        $disk ??= $config->disk;
        $this->rootPath = $rootPath ?? $config->rootPath;
        $this->persister = new FilePersister(FilesystemFactory::create($disk, $this->rootPath, $config), $this->rootPath);
        $this->uploads = new UploadService(new UploadValidator(), $this->persister, $scanner ?? new NullUploadScanner());
    }

    /**
     * Persiste um upload PSR-7.
     *
     * Diferente de `saveFromRequest()`, o conteúdo nunca é carregado inteiro em
     * memória e a extensão gravada vem do MIME detectado, não do nome enviado.
     * Recusas viram `UploadRejectedException` com mensagem exibível.
     */
    public function storeUploadedFile(UploadedFileInterface $file, string $directory = 'images', ?UploadRules $rules = null): StoredFile
    {
        return $this->uploads->storeUploadedFile($file, $directory, $rules ?? UploadRules::images());
    }

    public function saveByPath(string $path, string $folder = "images", string $name = "", $maxSizeBytes = 6000000, FileStorageType $type = FileStorageType::IMAGE): bool|string
    {
        try {
            return $this->uploads->store($path, basename($name ?: $path), $folder, $maxSizeBytes, $type);
        } catch (\InvalidArgumentException $e) {
            Message::setError($e->getMessage());
            return false;
        } catch (Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            Message::setError("Error while saving file");
            return false;
        }
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

            try {
                return $this->uploads->store($fileArray['tmp_name'], $originalName, $destinationFolder, $maxSizeBytes, $type);
            } catch (\InvalidArgumentException $e) {
                Message::setError($e->getMessage());
                return false;
            } catch (Exception $e) {
                Logger::error($e->getMessage() . $e->getTraceAsString());
                Message::setError("Error while saving file");
                return false;
            }
        }

        Message::setError("File not found in the request");
        return false;
    }

    public function saveFromString(string $fullPath = "images", string $contents = ""): bool
    {
        try {
            $this->persister->writeRaw($fullPath, $contents);
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
            $this->persister->delete($filePath);
            return true;
        } catch (FilesystemException | UnableToRetrieveMetadata $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            Message::setError("Error while deleting file");
            return false;
        }
    }
}
