<?php

namespace App\Jobs;

use App\Actions\Uploads\CompleteUpload;
use App\Actions\Uploads\FailUpload;
use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Hashes a fully received upload and copies it to its disk. On a remote disk the copy can take
 * minutes, which is why it does not run inside the request that carried the last chunk.
 */
class FinalizeUpload implements ShouldQueue
{
    use Queueable;

    /** One try: a half-finished copy is cleaned up and the user retries from the browser. */
    public int $tries = 1;

    /** Seconds. The `uploads` queue connection's retry_after is above this, or the job would start twice. */
    public int $timeout = 21600;

    public bool $failOnTimeout = true;

    public function __construct(public string $uploadId)
    {
        $this->onConnection('uploads');
    }

    public function handle(CompleteUpload $complete, FailUpload $fail): void
    {
        // Claim it: from now on the processing timeout runs (time waiting in the queue does not count).
        $upload = DB::transaction(function () {
            $upload = Upload::query()->lockForUpdate()->find($this->uploadId);

            if ($upload === null || $upload->status !== Upload::PROCESSING || $upload->started_at !== null) {
                return null;
            }

            $upload->forceFill(['started_at' => now()])->save();

            return $upload;
        });

        if ($upload === null) {
            return;
        }

        try {
            $complete->handle($upload);
        } catch (ValidationException $e) {
            $fail->handle($upload, $e->validator->errors()->first() ?: __('The file could not be stored.'));
        }
    }

    public function failed(Throwable $e): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload !== null) {
            app(FailUpload::class)->handle($upload, __('The file could not be stored. Try uploading it again.'));
        }
    }
}
