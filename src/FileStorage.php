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
            $client = new S3Client($_ENV["AWS_CLIENT"]);
            $adapter = new AwsS3V3Adapter($client, $_ENV["AWS_BUCKETNAME"]);
        }

        $this->filesystem = new Filesystem($adapter);
    }

    public function saveByPath(string $path, string $folder = "images", string $name = "", $maxSizeBytes = 6000000, FileStorageType $type = FileStorageType::IMAGE): bool|string 
    {
        if (file_exists($path)) {

            if (!$this->validType($path, $type)) {
                return false;
            }

            if (!$this->validSize($path, $maxSizeBytes)) {
                return false;
            }

            $name = str_replace(" ", "_", basename($name ?: $path));

            $completePath = $folder . DIRECTORY_SEPARATOR . Functions::generateId() . $name;

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

            if (!$this->validType($fileArray["tmp_name"], $type)) {
                return false;
            }

            if (!$this->validSize($fileArray["tmp_name"], $maxSizeBytes)) {
                return false;
            }

            $fileName = str_replace(" ", "_", basename($fileArray['name']));

            $fullPath = $destinationFolder . DIRECTORY_SEPARATOR . time() . $fileName;

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

    public function validType(string $filePath, FileStorageType $type): bool
    {
        try {
            $mimeType = mime_content_type($filePath);
        } catch (Exception $e) {
            Message::setError("File type is not allowed");
            return false;
        }

        $allowedTypes = [];
        if ($type == FileStorageType::DOCUMENT)
            $allowedTypes = ["application/pdf", "application/doc", "application/docx", "application/rtf", "application/txt", "application/odf", "application/msword"];
        elseif ($type == FileStorageType::IMAGE)
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/avif', 'image/webp', 'image/svg+xml'];

        if ($type != FileStorageType::ANY && !in_array($mimeType, $allowedTypes)) {
            Message::setError("File type is not allowed");
            return false;
        }

        return true;
    }

    public function validSize(string $filePath, int $maxSize)
    {
        try {
            $fileSize = fileSize($filePath);
        } catch (Exception $e) {
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