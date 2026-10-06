<?php

namespace App\Support;

use App\Models\StorageDisk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

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

    public function filesystem(StorageDisk $disk): Filesystem
    {
        $config = ['driver' => $disk->driver, 'throw' => true, ...($disk->config ?? [])];

        match ($disk->driver) {
            'local' => $config['root'] ??= config('shed.local_root'),
            // Fail fast when the service is unreachable; there is no overall timeout, uploads can be large.
            's3' => $config += ['http' => ['connect_timeout' => 10], 'retries' => 2],
            'sftp' => $config += ['port' => 22, 'timeout' => 10],
            default => null,
        };

        return Storage::build($config);
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
