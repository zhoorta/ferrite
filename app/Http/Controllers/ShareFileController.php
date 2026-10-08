<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\Share;
use App\Support\ActivityLog;
use App\Support\FileKind;
use App\Support\NodeResponder;
use App\Support\ShareAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files and ZIPs for guests with a share link. Same responses as for signed-in users, behind the
 * link checks: usable link, password unlocked, node inside the shared node.
 */
class ShareFileController extends Controller
{
    public function __construct(private ShareAccess $access, private NodeResponder $responder) {}

    public function download(Request $request, string $token, ?Node $node = null): Response
    {
        $share = $this->authorizeShare($token);
        abort_unless($share->allow_download, 403);

        $file = $this->file($share, $node);

        if (NodeResponder::startsDownload($request)) {
            ActivityLog::record(ActivityAction::LinkDownloaded, $file, null, ['share_id' => $share->id]);
        }

        return $this->responder->file($request, $file);
    }

    /**
     * Inline for allowed types. For a view-only link, types that could only be downloaded are refused.
     */
    public function preview(Request $request, string $token, Node $node): Response
    {
        $share = $this->authorizeShare($token);
        $file = $this->file($share, $node);

        abort_unless($share->allow_download || FileKind::inlineType($file->mime) !== null, 403);

        return $this->responder->file($request, $file, inline: true);
    }

    public function thumbnail(Request $request, string $token, Node $node): Response
    {
        $share = $this->authorizeShare($token);

        return $this->responder->thumbnail($request, $this->file($share, $node)) ?? abort(404);
    }

    public function zip(Request $request, string $token, ?Node $node = null): Response
    {
        $share = $this->authorizeShare($token);
        abort_unless($share->allow_download, 403);

        $folder = $node ?? $share->node;
        abort_unless($folder->isFolder() && $this->access->reaches($share, $folder), 404);

        ActivityLog::record(ActivityAction::LinkDownloaded, $folder, null, ['share_id' => $share->id]);

        return $this->responder->zip($folder);
    }

    private function authorizeShare(string $token): Share
    {
        $share = $this->access->find($token);

        // An upload link shows and serves nothing.
        abort_if($share->isDropbox(), 404);
        abort_unless($this->access->isUnlocked($share), 403);

        return $share;
    }

    private function file(Share $share, ?Node $node): Node
    {
        $node ??= $share->node;

        abort_unless($node->isFile() && $this->access->reaches($share, $node), 404);

        return $node;
    }
}
