<?php

namespace App\Actions\Storage;

use App\Models\StorageDisk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetDefaultStorageDisk
{
    /**
     * New uploads go to the default disk; files already stored stay where they are.
     */
    public function handle(User $actor, StorageDisk $disk): StorageDisk
    {
        Gate::forUser($actor)->authorize('admin');

        DB::transaction(function () use ($disk) {
            StorageDisk::query()->where('is_default', true)->whereKeyNot($disk->id)->update(['is_default' => false]);
            $disk->forceFill(['is_default' => true])->save();
        });

        return $disk;
    }
}
