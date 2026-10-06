<?php

namespace App\Actions\Storage;

use App\Models\StorageDisk;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Support\Facades\Gate;
use Throwable;

class TestStorageDisk
{
    public function __construct(private StorageManager $storage) {}

    /**
     * Write, read back and delete a small probe file. Returns null when everything works,
     * otherwise a short description of what failed.
     */
    public function handle(User $actor, StorageDisk $disk): ?string
    {
        Gate::forUser($actor)->authorize('admin');

        $key = '.shed-probe-'.bin2hex(random_bytes(6));
        $content = 'shed '.bin2hex(random_bytes(8));

        try {
            $filesystem = $this->storage->filesystem($disk);
            $filesystem->put($key, $content);

            if ($filesystem->get($key) !== $content) {
                return __('The probe file could not be read back correctly.');
            }

            $filesystem->delete($key);

            return null;
        } catch (Throwable $e) {
            return mb_strimwidth($e->getMessage(), 0, 300, '…');
        }
    }
}
