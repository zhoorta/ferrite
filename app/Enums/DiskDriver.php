<?php

namespace App\Enums;

/**
 * The storage backends an admin can add, with the settings each one needs. Setting keys are the
 * ones Laravel's filesystem config expects, so they pass straight to the disk.
 */
enum DiskDriver: string
{
    case Local = 'local';
    case S3 = 's3';
    case Sftp = 'sftp';

    public function label(): string
    {
        return match ($this) {
            self::Local => __('Local folder'),
            self::S3 => __('S3 or compatible'),
            self::Sftp => __('SFTP'),
        };
    }

    /**
     * @return list<array{key: string, label: string, type: 'text'|'number'|'password'|'textarea'|'checkbox', required: bool, secret: bool, rules: list<string>, hint?: string}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Local => [
                ['key' => 'root', 'label' => __('Folder'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:500', 'starts_with:/'], 'hint' => __('Absolute path, writable by the web server.')],
            ],
            self::S3 => [
                ['key' => 'bucket', 'label' => __('Bucket'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:255']],
                ['key' => 'region', 'label' => __('Region'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:100']],
                ['key' => 'key', 'label' => __('Access key ID'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:255']],
                ['key' => 'secret', 'label' => __('Secret access key'), 'type' => 'password', 'required' => true, 'secret' => true, 'rules' => ['string', 'max:500']],
                ['key' => 'endpoint', 'label' => __('Endpoint'), 'type' => 'text', 'required' => false, 'secret' => false, 'rules' => ['url:http,https', 'max:500'], 'hint' => __('Only for S3-compatible services such as MinIO, Hetzner or Backblaze.')],
                ['key' => 'use_path_style_endpoint', 'label' => __('Use path-style URLs'), 'type' => 'checkbox', 'required' => false, 'secret' => false, 'rules' => ['boolean']],
                ['key' => 'root', 'label' => __('Key prefix'), 'type' => 'text', 'required' => false, 'secret' => false, 'rules' => ['string', 'max:255']],
            ],
            self::Sftp => [
                ['key' => 'host', 'label' => __('Host'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:255']],
                ['key' => 'port', 'label' => __('Port'), 'type' => 'number', 'required' => false, 'secret' => false, 'rules' => ['integer', 'between:1,65535']],
                ['key' => 'username', 'label' => __('Username'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:255']],
                ['key' => 'password', 'label' => __('Password'), 'type' => 'password', 'required' => false, 'secret' => true, 'rules' => ['string', 'max:500']],
                ['key' => 'privateKey', 'label' => __('Private key'), 'type' => 'textarea', 'required' => false, 'secret' => true, 'rules' => ['string', 'max:10000'], 'hint' => __('PEM text. Use this or a password.')],
                ['key' => 'passphrase', 'label' => __('Key passphrase'), 'type' => 'password', 'required' => false, 'secret' => true, 'rules' => ['string', 'max:500']],
                ['key' => 'hostFingerprint', 'label' => __('Host fingerprint'), 'type' => 'text', 'required' => false, 'secret' => false, 'rules' => ['string', 'max:255'], 'hint' => __('MD5 fingerprint of the server key. Strongly recommended: it protects against someone impersonating the server.')],
                ['key' => 'root', 'label' => __('Folder'), 'type' => 'text', 'required' => true, 'secret' => false, 'rules' => ['string', 'max:500', 'starts_with:/']],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return array_column(array_filter($this->fields(), fn (array $field) => $field['secret']), 'key');
    }
}
