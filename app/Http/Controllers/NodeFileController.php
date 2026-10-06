<?php

namespace App\Http\Controllers;

use App\Models\Node;
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

    public function zip(Node $node): Response
    {
        Gate::authorize('view', $node);
        abort_unless($node->isFolder() && ! $node->isTrashed(), 404);

        return $this->responder->zip($node);
    }

    private function authorizeFile(Node $node): void
    {
        Gate::authorize('view', $node);
        abort_unless($node->isFile() && ! $node->isTrashed(), 404);
    }
}
