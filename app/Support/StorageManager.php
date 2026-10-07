<?php

namespace App\Support;

use App\Models\StorageDisk;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathPrefixer;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Resolves the Flysystem disk behind a `storage_disks` row. Blobs are stored under random keys,
 * so nothing here knows about names or folders.
 */
class StorageManager
{
    /**
     * The disk new files go to. A local disk is created on first use.
     */
    public function default(): StorageDisk
    {
        return StorageDisk::query()->where('is_default', true)->first()
            ?? StorageDisk::create(['name' => 'local', 'driver' => 'local', 'config' => null, 'is_default' => true]);
    }

    /** @var array<int, SftpConnectionProvider> */
    private array $sftpProviders = [];

    public function filesystem(StorageDisk $disk): Filesystem
    {
        return Storage::build($this->diskConfig($disk));
    }

    /**
     * @return array<string, mixed>
     */
    private function diskConfig(StorageDisk $disk): array
    {
        $config = ['driver' => $disk->driver, 'throw' => true, ...($disk->config ?? [])];

        match ($disk->driver) {
            'local' => $config['root'] ??= config('ferrite.local_root'),
            // Fail fast when the service is unreachable; there is no overall timeout, uploads can be large.
            's3' => $config += ['http' => ['connect_timeout' => 10], 'retries' => 2],
            'sftp' => $config += ['port' => 22, 'timeout' => 10],
            default => null,
        };

        return $config;
    }

    /**
     * Open a blob for reading, positioned at $offset. SFTP reads are fetched on demand, so jumping
     * into a big file does not download what comes before; S3 is asked for the byte range. Other
     * drivers use Flysystem's own (seekable) stream. $size is the blob's size in bytes.
     *
     * @return resource
     */
    public function openStream(StorageDisk $disk, string $path, int $size, int $offset = 0)
    {
        if ($disk->driver === 'sftp') {
            $config = $disk->config ?? [];
            $remote = (new PathPrefixer((string) ($config['root'] ?? '')))->prefixPath($path);
            $connection = $this->sftpProviders[$disk->id ?? spl_object_id($disk)] ??= SftpConnectionProvider::fromArray($this->diskConfig($disk));

            return SftpStream::open($connection->provideConnection(), $remote, $size, $offset);
        }

        if ($disk->driver === 's3' && $offset > 0) {
            return $this->s3Range($disk, $path, $offset);
        }

        $stream = $this->filesystem($disk)->readStream($path) ?? throw new RuntimeException('Cannot read the file.');

        if ($offset > 0 && fseek($stream, $offset) !== 0) {
            // A stream that cannot seek (an HTTP body): read and drop what comes before.
            for ($toSkip = $offset; $toSkip > 0 && ! feof($stream);) {
                $read = strlen((string) fread($stream, min(1024 * 1024, $toSkip)));

                if ($read === 0) {
                    break;
                }

                $toSkip -= $read;
            }
        }

        return $stream;
    }

    /**
     * The blob from $offset to its end, as a stream of just that range.
     *
     * @return resource
     */
    protected function s3Range(StorageDisk $disk, string $path, int $offset)
    {
        $config = $disk->config ?? [];
        $result = $this->s3Filesystem($disk)->getClient()->getObject([
            'Bucket' => $config['bucket'] ?? '',
            'Key' => (new PathPrefixer((string) ($config['root'] ?? '')))->prefixPath($path),
            'Range' => "bytes={$offset}-",
        ]);

        return StreamWrapper::getResource($result['Body']);
    }

    private function s3Filesystem(StorageDisk $disk): AwsS3V3Adapter
    {
        $filesystem = $this->filesystem($disk);

        return $filesystem instanceof AwsS3V3Adapter ? $filesystem : throw new RuntimeException('The disk is not an S3 disk.');
    }

    /**
     * A new random blob key, sharded by prefix to keep directories small.
     */
    public function newKey(): string
    {
        $hex = bin2hex(random_bytes(20));

        return substr($hex, 0, 2).'/'.substr($hex, 2);
    }
}
