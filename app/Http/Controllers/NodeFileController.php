<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Support\ActivityLog;
use App\Support\NodeResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class NodeFileController extends Controller
{
    public function __construct(private NodeResponder $responder) {}

    public function download(Request $request, Node $node): Response
    {
        $this->authorizeFile($node);
        $this->logDownload($request, $node);

        return $this->responder->file($request, $node);
    }

    /**
     * Inline for the types FileKind allows, a download for everything else.
     */
    public function preview(Request $request, Node $node): Response
    {
        $this->authorizeFile($node);

        return $this->responder->file($request, $node, inline: true);
    }

    public function thumbnail(Request $request, Node $node): Response
    {
        $this->authorizeFile($node);

        return $this->responder->thumbnail($request, $node) ?? abort(404);
    }

    public function zip(Request $request, Node $node): Response
    {
        Gate::authorize('view', $node);
        abort_unless($node->isFolder() && ! $node->isTrashed(), 404);
        $this->logDownload($request, $node);

        return $this->responder->zip($node);
    }

    /**
     * Owners downloading their own files is not worth a log line; collaborators doing it is.
     * Only the start of a download counts, not each Range request of a media player.
     */
    private function logDownload(Request $request, Node $node): void
    {
        $user = $request->user();

        if ($user !== null && $user->id !== $node->owner_id && NodeResponder::startsDownload($request)) {
            ActivityLog::record(ActivityAction::Downloaded, $node, $user);
        }
    }

    private function authorizeFile(Node $node): void
    {
        Gate::authorize('view', $node);
        abort_unless($node->isFile() && ! $node->isTrashed(), 404);
    }
}
