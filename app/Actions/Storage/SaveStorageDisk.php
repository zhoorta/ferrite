<?php

namespace App\Actions\Storage;

use App\Enums\DiskDriver;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\Encryption\KeyRing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveStorageDisk
{
    /**
     * Create a disk, or update one. The driver of an existing disk cannot change. When editing,
     * a blank secret keeps the stored one.
     *
     * @param  array<string, mixed>  $data  name, driver (new disks only) and config.* settings
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?StorageDisk $disk, array $data): StorageDisk
    {
        Gate::forUser($actor)->authorize('admin');

        $driver = $disk !== null ? DiskDriver::from($disk->driver) : DiskDriver::tryFrom((string) ($data['driver'] ?? ''));

        if ($driver === null) {
            throw ValidationException::withMessages(['driver' => __('Choose a storage type.')]);
        }

        $stored = $disk->config ?? [];

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100', Rule::unique('storage_disks', 'name')->ignore($disk?->id)],
            ...$this->configRules($driver, $stored),
        ])->validate();

        $config = $this->normalize($driver, $validated['config'] ?? [], $stored);

        $this->validateCredentials($driver, $config);

        // A caller that says nothing about encryption leaves it as it is.
        $encrypt = $this->encryptFlag($disk, array_key_exists('encrypt', $data) ? (bool) $data['encrypt'] : ($disk->encrypt ?? false));

        $disk ??= new StorageDisk(['driver' => $driver->value, 'is_default' => ! StorageDisk::query()->exists()]);
        $disk->fill(['name' => $validated['name'], 'config' => $config, 'encrypt' => $encrypt])->save();

        return $disk;
    }

    /**
     * "Encrypt files" is chosen when a disk is created. It cannot be switched on or off later for a disk that
     * already holds files (that would leave it half and half with nobody knowing which): `files:encrypt` converts
     * a disk in place. Turning it on needs FERRITE_FILE_KEY, because a disk set to encrypt never writes plaintext.
     *
     * @throws ValidationException
     */
    private function encryptFlag(?StorageDisk $disk, bool $wanted): bool
    {
        if ($disk !== null && $disk->encrypt === $wanted) {
            return $wanted;
        }

        if ($disk !== null && $disk->nodes()->exists()) {
            throw ValidationException::withMessages(['encrypt' => __('Encryption cannot be changed on a disk that already holds files. Run files:encrypt to convert it.')]);
        }

        if ($wanted && ! app(KeyRing::class)->configured()) {
            throw ValidationException::withMessages(['encrypt' => __('Set FERRITE_FILE_KEY in the environment first (see docs/encryption.md).')]);
        }

        return $wanted;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, list<mixed>>
     */
    private function configRules(DiskDriver $driver, array $stored): array
    {
        $rules = [];

        foreach ($driver->fields() as $field) {
            $keepsStored = $field['secret'] && filled($stored[$field['key']] ?? null);

            $rules["config.{$field['key']}"] = [
                $field['required'] && ! $keepsStored ? 'required' : 'nullable',
                ...$field['rules'],
            ];
        }

        return $rules;
    }

    /**
     * Cast values, drop empty ones, and fill in blank secrets from the stored config.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function normalize(DiskDriver $driver, array $input, array $stored): array
    {
        $config = [];

        foreach ($driver->fields() as $field) {
            $value = $input[$field['key']] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($field['secret'] && blank($value)) {
                $value = $stored[$field['key']] ?? null;
            }

            $value = match ($field['type']) {
                'checkbox' => (bool) $value,
                'number' => blank($value) ? null : (int) $value,
                default => blank($value) ? null : (string) $value,
            };

            // Unset checkboxes are simply left out; the driver's default applies.
            if ($value !== null && $value !== false) {
                $config[$field['key']] = $value;
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    private function validateCredentials(DiskDriver $driver, array $config): void
    {
        if ($driver === DiskDriver::Sftp && blank($config['password'] ?? null) && blank($config['privateKey'] ?? null)) {
            throw ValidationException::withMessages(['config.password' => __('Enter a password or a private key.')]);
        }
    }
}
