<?php

use App\Actions\Storage\SaveStorageDisk;
use App\Models\StorageDisk;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    config(['ferrite.file_key' => base64_encode(str_repeat('k', 32)), 'ferrite.file_key_id' => 1, 'ferrite.file_keys_old' => null]);
});

function localDisk(array $extra = []): array
{
    return ['name' => 'vault', 'driver' => 'local', 'config' => ['root' => storage_path('framework/testing/vault')], ...$extra];
}

it('creates a disk that encrypts when the file key is set', function () {
    $disk = app(SaveStorageDisk::class)->handle($this->admin, null, localDisk(['encrypt' => true]));

    expect($disk->fresh()->encrypt)->toBeTrue();
});

it('creates plain disks by default', function () {
    expect(app(SaveStorageDisk::class)->handle($this->admin, null, localDisk())->fresh()->encrypt)->toBeFalse();
});

it('refuses to turn encryption on without FERRITE_FILE_KEY', function () {
    config(['ferrite.file_key' => null]);

    expect(fn () => app(SaveStorageDisk::class)->handle($this->admin, null, localDisk(['encrypt' => true])))
        ->toThrow(ValidationException::class, 'FERRITE_FILE_KEY');
    expect(StorageDisk::where('name', 'vault')->exists())->toBeFalse();
});

it('does not let encryption be switched on or off for a disk that already holds files, but renaming it is fine', function () {
    $disk = app(SaveStorageDisk::class)->handle($this->admin, null, localDisk());
    $disk->forceFill(['is_default' => true])->save();
    storedFile($this->admin, 'a.txt', 'x');

    expect(fn () => app(SaveStorageDisk::class)->handle($this->admin, $disk, localDisk(['encrypt' => true])))
        ->toThrow(ValidationException::class, 'files:encrypt');

    app(SaveStorageDisk::class)->handle($this->admin, $disk, localDisk(['name' => 'renamed']));
    expect($disk->fresh()->name)->toBe('renamed')->and($disk->fresh()->encrypt)->toBeFalse();

    $encrypted = encryptedDisk();
    encryptedFile($this->admin, 'b.txt', 'y', $encrypted);

    expect(fn () => app(SaveStorageDisk::class)->handle($this->admin, $encrypted, ['name' => 'encrypted', 'driver' => 'local', 'config' => [], 'encrypt' => false]))
        ->toThrow(ValidationException::class);
});

it('lets an empty disk change its mind', function () {
    $disk = app(SaveStorageDisk::class)->handle($this->admin, null, localDisk(['encrypt' => true]));

    app(SaveStorageDisk::class)->handle($this->admin, $disk, localDisk(['encrypt' => false]));

    expect($disk->fresh()->encrypt)->toBeFalse();
});

it('shows the option in the admin page and marks encrypted disks', function () {
    encryptedDisk();

    Livewire::test('pages::admin.disks')
        ->assertSee('Encrypted')
        ->call('add')
        ->set('name', 'vault2')
        ->set('driver', 'local')
        ->set('config', ['root' => storage_path('framework/testing/vault2')])
        ->set('encrypt', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(StorageDisk::where('name', 'vault2')->first()->encrypt)->toBeTrue();
});

it('shows the error when the key is missing', function () {
    config(['ferrite.file_key' => null]);

    Livewire::test('pages::admin.disks')
        ->call('add')
        ->set('name', 'vault3')
        ->set('driver', 'local')
        ->set('config', ['root' => storage_path('framework/testing/vault3')])
        ->set('encrypt', true)
        ->call('save')
        ->assertHasErrors(['encrypt']);
});
