<?php

namespace App\Console\Commands;

use App\Actions\Nodes\PurgeNode;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\NodeVersion;
use App\Models\StorageDisk;
use App\Support\Encryption\BlobConverter;
use App\Support\Encryption\KeyRing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Throwable;

#[Signature('files:encrypt {disk : Name or id of the storage disk}
    {--yes : Do not ask before turning encryption on for the disk}
    {--no-verify : Do not read every new blob back and compare its sha256 (faster, less safe)}
    {--limit=0 : Convert at most this many blobs, then stop (0: all)}')]
#[Description('Encrypt the plaintext files already on a disk, in place and resumable (docs/encryption.md)')]
class EncryptFiles extends Command
{
    public function handle(BlobConverter $converter, KeyRing $keys, PurgeNode $purge): int
    {
        $name = (string) $this->argument('disk');
        $disk = StorageDisk::query()->where('name', $name)->first() ?? (ctype_digit($name) ? StorageDisk::query()->find((int) $name) : null);

        if ($disk === null) {
            $this->error("No storage disk called \"{$name}\".");

            return self::FAILURE;
        }

        if (! $keys->configured()) {
            $this->error('FERRITE_FILE_KEY is not set. Generate one with: php -r "echo base64_encode(random_bytes(32));"');
            $this->line('Put it in .env and back it up together with APP_KEY: without it every encrypted file is lost.');

            return self::FAILURE;
        }

        if (! $disk->encrypt) {
            $this->warn("\"{$disk->name}\" is not set to encrypt yet. New uploads to it will be encrypted from now on, and the files already on it are converted now.");

            if (! $this->option('yes') && ! $this->confirm('Turn encryption on for this disk and convert it?')) {
                return self::FAILURE;
            }

            $disk->forceFill(['encrypt' => true])->save();
        }

        $limit = max(0, (int) $this->option('limit'));
        $verify = ! $this->option('no-verify');
        $done = 0;
        $failed = 0;
        $bytes = 0;

        // The files' blobs, then the blobs of their earlier versions (docs/versions.md): both are stored the same way.
        foreach (['nodes', 'node_versions'] as $table) {
            $last = 0;

            while (true) {
                $batch = DB::table($table)
                    ->when($table === 'nodes', fn ($query) => $query->where('type', NodeType::File->value))
                    ->where('disk_id', $disk->id)
                    ->whereNotNull('path')
                    ->whereNull('encryption_key_id')
                    ->groupBy('path')
                    ->havingRaw('min(id) > ?', [$last])
                    ->orderByRaw('min(id)')
                    ->limit(50)
                    ->selectRaw('min(id) as first_id, path')
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch as $row) {
                    $last = max($last, (int) $row->first_id);

                    if ($limit > 0 && $done + $failed >= $limit) {
                        break 3;
                    }

                    $sample = $table === 'nodes' ? Node::query()->findOrFail((int) $row->first_id) : NodeVersion::query()->findOrFail((int) $row->first_id)->asNode();

                    try {
                        [$key, $keyId] = $converter->encrypt($disk, $sample, $verify);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("File {$sample->id} ({$sample->name}): {$e->getMessage()}");

                        continue;
                    }

                    // Everything that shares this blob moves to the new one; updated_at stays as it was.
                    foreach (['nodes', 'node_versions'] as $target) {
                        DB::table($target)
                            ->where('disk_id', $disk->id)
                            ->where('path', $row->path)
                            ->whereNull('encryption_key_id')
                            ->update(['path' => $key, 'encryption_key_id' => $keyId]);
                    }

                    // The old blob goes once nothing points at it (a file added meanwhile may still have).
                    $purge->deleteBlobs([['size' => 0, 'disk_id' => $disk->id, 'path' => (string) $row->path]]);

                    $done++;
                    $bytes += $sample->size;
                }
            }
        }

        $this->info("Encrypted {$done} blobs (".Number::fileSize($bytes).').'.($failed > 0 ? " {$failed} failed, see above; run the command again to retry them." : ''));

        if ($limit > 0 && $done + $failed >= $limit) {
            $this->line('Stopped at --limit; run it again to continue.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
