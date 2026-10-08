<?php

namespace App\Http\Controllers;

use App\Actions\Uploads\AppendChunk;
use App\Actions\Uploads\FailUpload;
use App\Actions\Uploads\FindUploadConflicts;
use App\Actions\Uploads\StartUpload;
use App\Jobs\FinalizeUpload;
use App\Models\Node;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class UploadController extends Controller
{
    public function store(Request $request, StartUpload $start): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'path' => ['required', 'string', 'max:2048'],
            'size' => ['required', 'integer', 'min:0'],
            'fingerprint' => ['nullable', 'string', 'max:100'],
            'replace' => ['nullable', 'boolean'],
        ]);

        $parent = isset($data['parent_id']) ? Node::query()->whereKey($data['parent_id'])->firstOrFail() : null;

        $upload = $start->handle($request->user(), $parent, $data['path'], $data['size'], $data['fingerprint'] ?? null, (bool) ($data['replace'] ?? false));

        return response()->json($this->state($upload), $upload->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Which of the files about to be uploaded would meet one of the same name, so the browser can ask
     * what to do first. Advisory: the real decision is made when each upload is stored.
     */
    public function conflicts(Request $request, FindUploadConflicts $find): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'paths' => ['required', 'array', 'min:1', 'max:5000'],
            'paths.*' => ['required', 'string', 'max:2048'],
        ]);

        $parent = isset($data['parent_id']) ? Node::query()->whereKey($data['parent_id'])->firstOrFail() : null;

        return response()->json($find->handle($request->user(), $parent, array_values($data['paths'])));
    }

    public function show(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return $this->report($upload);
    }

    /**
     * Append one chunk. The client states the offset it is writing at; a mismatch returns 409 with
     * the real offset so the client can resume from there.
     */
    public function update(Request $request, Upload $upload, AppendChunk $append, FailUpload $fail): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return $this->receive($request, $upload, $append, $fail);
    }

    public function destroy(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return $this->discard($upload);
    }

    protected function report(Upload $upload): JsonResponse
    {
        return response()->json($this->state($upload));
    }

    protected function receive(Request $request, Upload $upload, AppendChunk $append, FailUpload $fail): JsonResponse
    {
        $lock = Cache::lock("upload:{$upload->id}", 120);

        if (! $lock->get()) {
            return response()->json($this->state($upload), 409);
        }

        try {
            $upload->refresh();
            $header = $request->header('Upload-Offset');
            $offset = is_numeric($header) ? (int) $header : -1;

            // Already handed to the job (or finished): there is nothing left to receive.
            if (! $upload->isReceiving() || $offset !== $upload->offset) {
                return response()->json($this->state($upload), 409);
            }

            if (! $append->handle($upload, $offset, $request->getContent(true))) {
                return response()->json(['message' => __('The chunk is larger than the declared file size.')], 413);
            }

            if (! $upload->isComplete()) {
                return response()->json($this->state($upload));
            }

            // From here the status keeps further chunks out, so the lock is not held while storing.
            $upload->forceFill(['status' => Upload::PROCESSING])->save();
        } finally {
            $lock->release();
        }

        // The copy to the disk can take long on a remote disk, so a queued job does it. With the
        // sync queue (no worker) it has already run when dispatch returns.
        try {
            FinalizeUpload::dispatch($upload->id);
        } catch (Throwable $e) {
            report($e);

            // Not queued at all (queue unreachable): nothing would ever pick it up.
            $fail->handle($upload, __('The file could not be queued for storing. Try uploading it again.'));
        }

        $upload->refresh();

        // The record stays until the browser has read the outcome (it then deletes it) or prune does.
        return match ($upload->status) {
            Upload::DONE => response()->json($this->state($upload)),
            Upload::FAILED => response()->json(['message' => $upload->error, 'errors' => ['size' => [$upload->error]]] + $this->state($upload), 422),
            default => response()->json($this->state($upload), 202),
        };
    }

    protected function discard(Upload $upload): JsonResponse
    {
        // Being stored: the job owns the temporary file until it reports back.
        if ($upload->status === Upload::PROCESSING) {
            return response()->json($this->state($upload), 409);
        }

        $upload->discard();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(Upload $upload): array
    {
        return [
            'id' => $upload->id,
            'offset' => $upload->offset,
            'size' => $upload->size,
            'chunk_size' => config('ferrite.chunk_size'),
            'status' => $upload->status,
        ] + ($upload->status === Upload::DONE ? ['node' => ['id' => $upload->node_id, 'name' => $upload->node?->name]] : [])
          + ($upload->status === Upload::FAILED ? ['error' => $upload->error] : []);
    }

    private function authorizeOwner(Request $request, Upload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
    }
}
