<?php

namespace App\Console\Commands;

use App\Actions\Nodes\PurgeNode;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Support\Encryption\BlobConverter;
use App\Support\Encryption\KeyRing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('files:rekey {--no-verify : Do not read every new blob back and compare its sha256}
    {--limit=0 : Convert at most this many blobs, then stop (0: all)}')]
#[Description('Re-wrap the data key of every encrypted file with the current FERRITE_FILE_KEY, after a key rotation (docs/encryption.md)')]
class RekeyFiles extends Command
{
    public function handle(BlobConverter $converter, KeyRing $keys, PurgeNode $purge): int
    {
        if (! $keys->configured()) {
            $this->error('FERRITE_FILE_KEY is not set.');

            return self::FAILURE;
        }

        $current = $keys->currentId();
        $limit = max(0, (int) $this->option('limit'));
        $verify = ! $this->option('no-verify');
        $disks = [];
        $done = 0;
        $failed = 0;
        $last = 0;

        while (true) {
            $batch = DB::table('nodes')
                ->where('type', NodeType::File->value)
                ->whereNotNull('path')
                ->whereNotNull('encryption_key_id')
                ->where('encryption_key_id', '!=', $current)
                ->groupBy('disk_id', 'path')
                ->havingRaw('min(id) > ?', [$last])
                ->orderByRaw('min(id)')
                ->limit(50)
                ->selectRaw('min(id) as first_id, disk_id, path')
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                $last = max($last, (int) $row->first_id);

                if ($limit > 0 && $done + $failed >= $limit) {
                    break 2;
                }

                $sample = Node::query()->findOrFail((int) $row->first_id);
                $disk = $disks[$row->disk_id] ??= StorageDisk::query()->findOrFail((int) $row->disk_id);

                try {
                    [$key, $keyId] = $converter->rewrap($disk, $sample, $verify);
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("File {$sample->id} ({$sample->name}): {$e->getMessage()}");

                    continue;
                }

                DB::table('nodes')
                    ->where('disk_id', $disk->id)
                    ->where('path', $row->path)
                    ->where('encryption_key_id', '!=', $current)
                    ->update(['path' => $key, 'encryption_key_id' => $keyId]);

                $purge->deleteBlobs([['size' => 0, 'disk_id' => $disk->id, 'path' => (string) $row->path]]);
                $done++;
            }
        }

        $this->info("Re-wrapped {$done} blobs with key {$current}.".($failed > 0 ? " {$failed} failed, see above; run the command again to retry them." : ''));

        if ($failed === 0 && $limit === 0) {
            $this->line('Once this reports nothing left to do, the old key can be removed from FERRITE_FILE_KEYS_OLD (keep a backup of it as long as any backup of the files exists).');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
