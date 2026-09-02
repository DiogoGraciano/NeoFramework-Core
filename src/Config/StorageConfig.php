<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

use NeoFramework\Core\Enums\FileStorageDisk;
final readonly class StorageConfig {
    private function __construct(public FileStorageDisk $disk, public string $rootPath, public array $s3Client, public string $s3Bucket) {}
    public static function from(ConfigRepositoryInterface $c): self { $disk = match (strtolower(ConfigValidator::string($c, 'storage.disk', 'local'))) { 'local' => FileStorageDisk::LOCAL, 's3' => FileStorageDisk::S3, default => throw new ConfigurationException("Configuration key 'storage.disk' is unsupported.") }; $client = $c->get('storage.s3.client', []); if (!is_array($client)) throw new ConfigurationException("Configuration key 'storage.s3.client' must be an array."); $self = new self($disk, ConfigValidator::string($c, 'storage.root_path', 'public/assets'), $client, ConfigValidator::string($c, 'storage.s3.bucket')); if ($self->disk === FileStorageDisk::S3 && $self->s3Bucket === '') throw new ConfigurationException("Configuration key 'storage.s3.bucket' is required for the S3 disk."); return $self; }
}
