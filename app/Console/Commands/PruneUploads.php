<?php

namespace App\Console\Commands;

use App\Actions\Uploads\FailUpload;
use App\Models\Upload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:prune')]
#[Description('Delete stale uploads: unfinished ones, stuck ones and the records of finished ones')]
class PruneUploads extends Command
{
    public function handle(FailUpload $fail): int
    {
        $stale = 0;
        $stuck = 0;
        $finished = 0;

        Upload::query()
            ->where('status', Upload::RECEIVING)
            ->where('updated_at', '<', now()->subHours(config('ferrite.upload_ttl_hours')))
            ->each(function (Upload $upload) use (&$stale) {
                $upload->discard();
                $stale++;
            });

        // The job died and never reported back. One still waiting in the queue is left alone unless
        // it has waited a week (no worker running); its temporary file is then given up too.
        Upload::query()
            ->where('status', Upload::PROCESSING)
            ->where(fn ($query) => $query
                ->where('started_at', '<', now()->subHours(config('ferrite.processing_timeout_hours')))
                ->orWhere(fn ($query) => $query->whereNull('started_at')->where('updated_at', '<', now()->subDays(7))))
            ->each(function (Upload $upload) use ($fail, &$stuck) {
                $fail->handle($upload, __('The file was not stored in time. Try uploading it again.'));
                $stuck++;
            });

        // Finished uploads only linger so the browser can read the outcome.
        Upload::query()
            ->whereIn('status', [Upload::DONE, Upload::FAILED])
            ->where('updated_at', '<', now()->subHour())
            ->each(function (Upload $upload) use (&$finished) {
                $upload->discard();
                $finished++;
            });

        $this->info("Pruned {$stale} unfinished, {$stuck} stuck and {$finished} finished upload(s).");

        return self::SUCCESS;
    }
}
