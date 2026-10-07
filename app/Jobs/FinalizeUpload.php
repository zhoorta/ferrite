<?php

namespace App\Jobs;

use App\Actions\Uploads\CompleteUpload;
use App\Actions\Uploads\FailUpload;
use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    /** Seconds. Keep `retry_after` of the queue connection above this, or the job is started twice. */
    public int $timeout = 21600;

    public bool $failOnTimeout = true;

    public function __construct(public string $uploadId) {}

    public function handle(CompleteUpload $complete, FailUpload $fail): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload === null || $upload->status !== Upload::PROCESSING) {
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

        if ($upload !== null && $upload->status === Upload::PROCESSING) {
            app(FailUpload::class)->handle($upload, __('The file could not be stored. Try uploading it again.'));
        }
    }
}
