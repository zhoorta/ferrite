<?php

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\NodeName;
use App\Actions\Uploads\AppendChunk;
use App\Actions\Uploads\FailUpload;
use App\Actions\Uploads\StartUpload;
use App\Http\Controllers\UploadController as BaseUploadController;
use App\Models\ApiToken;
use App\Models\Node;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chunked uploads through a write token (`/api/v1/uploads`). Same protocol as the browser's
 * UploadController, with two differences: paths are relative to the token's folder, and a name
 * that is taken is refused (409), never replaced or renamed. An upload belongs to the token that
 * started it; any other token gets 404.
 */
class UploadController extends BaseUploadController
{
    public function start(Request $request, StartUpload $start): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'size' => ['required', 'integer', 'min:0'],
            'fingerprint' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $token = $this->token($request);
        $root = $token->rootFolder();

        // Refuse before anything is created or received; the real check is made again when the file is stored.
        $taken = $this->nodeAt($user->id, $root, explode('/', $data['path']));
        if ($taken !== null) {
            return response()->json(['error' => 'exists', 'id' => $taken->id, 'sha256' => $taken->sha256], 409);
        }

        $upload = $start->handle($user, $root, $data['path'], $data['size'], $data['fingerprint'] ?? null, false, $token);
        $created = $upload->wasRecentlyCreated;

        // Fills in the column defaults (status) that a freshly inserted model does not have yet.
        $upload->refresh();

        return response()->json($this->state($upload), $created ? 201 : 200);
    }

    public function status(Request $request, Upload $upload): JsonResponse
    {
        $this->own($request, $upload);

        return $this->report($upload);
    }

    public function append(Request $request, Upload $upload, AppendChunk $append, FailUpload $fail): JsonResponse
    {
        $this->own($request, $upload);

        return $this->receive($request, $upload, $append, $fail);
    }

    public function cancel(Request $request, Upload $upload): JsonResponse
    {
        $this->own($request, $upload);

        return $this->discard($upload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(Upload $upload): array
    {
        $state = parent::state($upload);

        if ($upload->status === Upload::DONE) {
            $state['node'] = ['id' => $upload->node_id, 'sha256' => $upload->node?->sha256];
        }

        return $state;
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        return $token;
    }

    private function own(Request $request, Upload $upload): void
    {
        abort_unless($upload->api_token_id === $this->token($request)->id && $upload->user_id === $request->user()->id, 404);
    }

    /**
     * The active file or folder at $segments below $root, if there is one; missing folders on the way mean nothing is.
     *
     * @param  list<string>  $segments
     */
    private function nodeAt(int $ownerId, ?Node $root, array $segments): ?Node
    {
        $segments = array_map(NodeName::normalize(...), $segments);
        $parentId = $root?->id;
        $node = null;

        foreach ($segments as $segment) {
            $node = Node::query()
                ->where('owner_id', $ownerId)
                ->where('parent_id', $parentId)
                ->whereNull('trashed_at')
                ->whereRaw('lower(name) = ?', [mb_strtolower($segment)])
                ->first();

            if ($node === null) {
                return null;
            }

            $parentId = $node->id;
        }

        return $node;
    }
}
