<?php

namespace App\Actions\Storage;

use App\Models\StorageDisk;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteStorageDisk
{
    /**
     * Removes the disk's settings only; it never touches the remote storage. A disk that is the
     * default or still holds files cannot be removed.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, StorageDisk $disk): void
    {
        Gate::forUser($actor)->authorize('admin');

        if ($disk->is_default) {
            throw ValidationException::withMessages(['disk' => __('Make another disk the default first.')]);
        }

        if ($disk->nodes()->exists()) {
            throw ValidationException::withMessages(['disk' => __('This disk still holds files.')]);
        }

        $disk->delete();
    }
}
