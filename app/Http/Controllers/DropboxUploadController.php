<?php

namespace App\Http\Controllers;

use App\Actions\Uploads\AppendChunk;
use App\Actions\Uploads\FailUpload;
use App\Actions\Uploads\StartDropboxUpload;
use App\Models\Share;
use App\Models\Upload;
use App\Support\ShareAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chunked uploads by guests through a drop-box link (`/s/{token}/uploads`). Same protocol as
 * UploadController. A guest can only touch uploads started in their own session through this
 * link: the upload id is remembered in the session, so nothing else is reachable by guessing.
 */
class DropboxUploadController extends UploadController
{
    public function __construct(private ShareAccess $access) {}

    public function start(Request $request, string $token, StartDropboxUpload $start): JsonResponse
    {
        $share = $this->dropbox($token);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'size' => ['required', 'integer', 'min:0'],
            'fingerprint' => ['nullable', 'string', 'max:100'],
        ]);

        $upload = $start->handle($share, $data['path'], $data['size'], $data['fingerprint'] ?? null, $this->sessionIds($share));

        $this->remember($share, $upload);

        return response()->json($this->state($upload), $upload->wasRecentlyCreated ? 201 : 200);
    }

    public function status(Request $request, string $token, Upload $upload): JsonResponse
    {
        $this->own($this->dropbox($token), $upload);

        return $this->report($upload);
    }

    public function append(Request $request, string $token, Upload $upload, AppendChunk $append, FailUpload $fail): JsonResponse
    {
        $this->own($this->dropbox($token), $upload);

        return $this->receive($request, $upload, $append, $fail);
    }

    public function cancel(Request $request, string $token, Upload $upload): JsonResponse
    {
        $share = $this->dropbox($token);
        $this->own($share, $upload);

        $response = $this->discard($upload);

        if ($response->getStatusCode() === 204) {
            session()->put($this->key($share), array_values(array_diff($this->sessionIds($share), [$upload->id])));
        }

        return $response;
    }

    /**
     * An usable drop-box link whose password, if any, this session has entered.
     */
    private function dropbox(string $token): Share
    {
        $share = $this->access->find($token);

        abort_unless($share->isDropbox(), 404);
        abort_unless($this->access->isUnlocked($share), 403);

        return $share;
    }

    private function own(Share $share, Upload $upload): void
    {
        abort_unless($upload->share_id === $share->id && in_array($upload->id, $this->sessionIds($share), true), 404);
    }

    /**
     * @return list<string>
     */
    private function sessionIds(Share $share): array
    {
        return array_values((array) session($this->key($share), []));
    }

    private function remember(Share $share, Upload $upload): void
    {
        session()->put($this->key($share), array_values(array_unique([...$this->sessionIds($share), $upload->id])));
    }

    private function key(Share $share): string
    {
        return "dropbox_uploads.{$share->id}";
    }
}
