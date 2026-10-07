<?php

namespace App\Http\Controllers;

use App\Actions\Uploads\AppendChunk;
use App\Actions\Uploads\CompleteUpload;
use App\Actions\Uploads\StartUpload;
use App\Models\Node;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function store(Request $request, StartUpload $start): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'path' => ['required', 'string', 'max:2048'],
            'size' => ['required', 'integer', 'min:0'],
            'fingerprint' => ['nullable', 'string', 'max:100'],
        ]);

        $parent = isset($data['parent_id']) ? Node::query()->whereKey($data['parent_id'])->firstOrFail() : null;

        $upload = $start->handle($request->user(), $parent, $data['path'], $data['size'], $data['fingerprint'] ?? null);

        return response()->json($this->state($upload), $upload->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return response()->json($this->state($upload));
    }

    /**
     * Append one chunk. The client states the offset it is writing at; a mismatch returns 409 with
     * the real offset so the client can resume from there.
     */
    public function update(Request $request, Upload $upload, AppendChunk $append, CompleteUpload $complete): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $lock = Cache::lock("upload:{$upload->id}", 120);

        if (! $lock->get()) {
            return response()->json($this->state($upload), 409);
        }

        try {
            $upload->refresh();
            $header = $request->header('Upload-Offset');
            $offset = is_numeric($header) ? (int) $header : -1;

            if ($offset !== $upload->offset) {
                return response()->json($this->state($upload), 409);
            }

            if (! $append->handle($upload, $offset, $request->getContent(true))) {
                return response()->json(['message' => __('The chunk is larger than the declared file size.')], 413);
            }

            if (! $upload->isComplete()) {
                return response()->json($this->state($upload));
            }

            try {
                $node = $complete->handle($upload);
            } catch (ValidationException $e) {
                $upload->discard();

                throw $e;
            }

            return response()->json($this->state($upload) + ['node' => ['id' => $node->id, 'name' => $node->name]]);
        } finally {
            $lock->release();
        }
    }

    public function destroy(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $upload->discard();

        return response()->json(null, 204);
    }

    /**
     * @return array{id: string, offset: int, size: int, chunk_size: int}
     */
    private function state(Upload $upload): array
    {
        return [
            'id' => $upload->id,
            'offset' => $upload->offset,
            'size' => $upload->size,
            'chunk_size' => config('ferrite.chunk_size'),
        ];
    }

    private function authorizeOwner(Request $request, Upload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
    }
}
