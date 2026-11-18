<?php

namespace App\Console\Commands;

use App\Models\StorageDisk;
use App\Support\StorageChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

#[Signature('ferrite:storage:check
    {--disk= : Only this disk (name or id)}
    {--verify : Read every blob back and compare its sha256 (the integrity scrub); without it only existence and size are checked}
    {--limit=0 : With --verify, read at most this many blobs, the least recently verified first (0: all)}
    {--orphans : Also list blobs on the disks that no file points at (slow on big remote disks)}')]
#[Description('Check that every stored file has its blob, and optionally that the blob still matches its sha256 (docs/storage-check.md)')]
class CheckStorage extends Command
{
    public function handle(StorageChecker $checker): int
    {
        $only = null;

        if ($this->option('disk') !== null) {
            $name = (string) $this->option('disk');
            $only = StorageDisk::query()->where('name', $name)->first() ?? (ctype_digit($name) ? StorageDisk::query()->find((int) $name) : null);

            if ($only === null) {
                $this->error("No storage disk called \"{$name}\".");

                return self::FAILURE;
            }
        }

        $verify = (bool) $this->option('verify');
        $limit = max(0, (int) $this->option('limit'));

        $bar = $this->output->createProgressBar();
        $bar->start();

        $result = $checker->blobs($only, $verify, $limit, function (int $done, int $total) use ($bar) {
            $bar->setMaxSteps(max($total, $done));
            $bar->setProgress($done);
        });

        $bar->finish();
        $this->newLine(2);

        foreach ($result['problems'] as $problem) {
            $this->line(sprintf('<error> %s </error> file %d "%s" on %s, %s: %s', strtoupper($problem['kind']), $problem['node_id'], $problem['name'], $problem['disk'], $problem['path'], $problem['detail']));
        }

        $orphans = 0;

        if ($this->option('orphans')) {
            foreach (StorageDisk::query()->when($only !== null, fn ($query) => $query->whereKey($only?->id))->get() as $disk) {
                try {
                    foreach ($checker->orphans($disk) as $path) {
                        $orphans++;
                        $this->line("<comment> ORPHAN </comment> {$disk->name}: {$path}");
                    }
                } catch (\Throwable $e) {
                    $this->error("Cannot list {$disk->name}: {$e->getMessage()}");
                }
            }
        }

        $count = count($result['problems']);
        $summary = sprintf('%s %d blobs (%s): %s%s.', $verify ? 'Verified' : 'Checked', $result['checked'], Number::fileSize($result['bytes']), $count === 0 ? 'no problems' : "{$count} problems", $orphans > 0 ? ", {$orphans} orphans" : '');

        if ($count === 0) {
            $this->info($summary);

            return self::SUCCESS;
        }

        $this->error($summary);
        Log::error('ferrite:storage:check found problems', ['problems' => $count, 'verify' => $verify, 'first' => array_slice($result['problems'], 0, 20)]);

        return self::FAILURE;
    }
}
