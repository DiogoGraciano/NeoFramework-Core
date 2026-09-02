<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use NeoFramework\Core\Config\StorageConfig;
use NeoFramework\Core\Enums\FileStorageDisk;
use NeoFramework\Core\Support\ProjectRoot;
use RuntimeException;

/** Creates the Flysystem adapter selected by the typed storage configuration. */
final class FilesystemFactory
{
    private function __construct()
    {
    }

    public static function create(FileStorageDisk $disk, string $rootPath, StorageConfig $config): DiskInterface
    {
        return new FlysystemDisk(new Filesystem(match ($disk) {
            FileStorageDisk::LOCAL => new LocalFilesystemAdapter(
                ProjectRoot::path() . DIRECTORY_SEPARATOR . $rootPath,
                PortableVisibilityConverter::fromArray([
                    'file' => ['public' => 0644, 'private' => 0644],
                    'dir' => ['public' => 0755, 'private' => 0755],
                ]),
            ),
            FileStorageDisk::S3 => self::s3Adapter($config),
        }));
    }

    private static function s3Adapter(StorageConfig $config): FilesystemAdapter
    {
        if (!class_exists(S3Client::class) || !class_exists(AwsS3V3Adapter::class)) {
            throw new RuntimeException("O disco S3 exige as dependências opcionais 'aws/aws-sdk-php' e 'league/flysystem-aws-s3-v3'. Instale-as com composer require.");
        }

        if ($config->s3Bucket === '') {
            throw new RuntimeException('storage.s3.bucket must be configured for the S3 disk.');
        }

        return new AwsS3V3Adapter(new S3Client($config->s3Client), $config->s3Bucket);
    }
}
