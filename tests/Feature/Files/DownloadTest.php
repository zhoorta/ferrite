<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->file = storedFile($this->user, 'hello.txt', 'hello world');
});

function body($response): string
{
    return $response->baseResponse instanceof StreamedResponse
        ? $response->streamedContent()
        : (string) $response->getContent();
}

describe('download', function () {
    it('streams the file as an attachment with safe headers', function () {
        $response = $this->get(route('nodes.download', $this->file))->assertOk();

        expect(body($response))->toBe('hello world');

        $response
            ->assertHeader('Content-Length', '11')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('ETag', '"'.hash('sha256', 'hello world').'"');

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
    });

    it('requires login and view permission', function () {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        $this->get(route('nodes.download', $this->file))->assertForbidden();

        $this->file->sharedWith()->attach($stranger, ['permission' => Permission::View->value]);
        $this->get(route('nodes.download', $this->file))->assertOk();

        auth()->logout();
        $this->get(route('nodes.download', $this->file))->assertRedirect(route('login'));
    });

    it('does not serve trashed files, folders or files with a trashed parent', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create();
        $inside = storedFile($this->user, 'in.txt', 'x', $folder);
        $folder->forceFill(['trashed_at' => now()])->save();
        $this->file->forceFill(['trashed_at' => now()])->save();

        $this->get(route('nodes.download', $this->file))->assertNotFound();
        $this->get(route('nodes.download', $inside))->assertNotFound();
        $this->get(route('nodes.download', $folder))->assertNotFound();
    });

    it('keeps odd file names out of the header syntax', function () {
        $node = storedFile($this->user, 're"po%rt é.txt', 'x');

        $header = $this->get(route('nodes.download', $node))->headers->get('Content-Disposition');

        expect($header)->toContain("filename*=utf-8''re%22po%25rt%20%C3%A9.txt")
            ->and($header)->not->toContain("\n")
            ->and($header)->toContain('filename="re_po_rt e.txt"');
    });

    it('answers 304 when the ETag matches', function () {
        $etag = '"'.hash('sha256', 'hello world').'"';

        $this->get(route('nodes.download', $this->file), ['If-None-Match' => $etag])->assertStatus(304);
        $this->get(route('nodes.download', $this->file), ['If-None-Match' => '"other"'])->assertOk();
    });
});

describe('ranges', function () {
    it('serves a byte range', function (string $header, string $expected, string $contentRange) {
        $response = $this->get(route('nodes.download', $this->file), ['Range' => $header])->assertStatus(206);

        expect(body($response))->toBe($expected)
            ->and($response->headers->get('Content-Range'))->toBe($contentRange)
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($expected));
    })->with([
        'start and end' => ['bytes=0-4', 'hello', 'bytes 0-4/11'],
        'open ended' => ['bytes=6-', 'world', 'bytes 6-10/11'],
        'suffix' => ['bytes=-5', 'world', 'bytes 6-10/11'],
        'end past the size' => ['bytes=6-999', 'world', 'bytes 6-10/11'],
        'one byte' => ['bytes=3-3', 'l', 'bytes 3-3/11'],
        'suffix longer than file' => ['bytes=-999', 'hello world', 'bytes 0-10/11'],
    ]);

    it('rejects unsatisfiable ranges', function (string $header) {
        $this->get(route('nodes.download', $this->file), ['Range' => $header])
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */11');
    })->with(['bytes=11-', 'bytes=20-30', 'bytes=5-2', 'bytes=-0']);

    it('ignores multiple or malformed ranges', function (string $header) {
        $response = $this->get(route('nodes.download', $this->file), ['Range' => $header])->assertOk();

        expect(body($response))->toBe('hello world');
    })->with(['bytes=0-1,4-5', 'items=0-1', 'bytes=abc', 'bytes=-']);

    it('serves ranges across the chunk size', function () {
        $content = random_bytes(2_500_000);
        $node = storedFile($this->user, 'big.bin', $content, mime: 'application/octet-stream');

        $response = $this->get(route('nodes.download', $node), ['Range' => 'bytes=1048000-2200000'])->assertStatus(206);

        expect(body($response))->toBe(substr($content, 1048000, 2200000 - 1048000 + 1));
    });

    it('serves an empty file', function () {
        $node = storedFile($this->user, 'empty.txt', '');

        $response = $this->get(route('nodes.download', $node), ['Range' => 'bytes=0-'])->assertOk();

        expect(body($response))->toBe('')->and($response->headers->get('Content-Length'))->toBe('0');
    });
});

describe('preview', function () {
    it('shows allowlisted types inline', function (string $mime, string $sent) {
        $node = storedFile($this->user, 'f.bin', 'data', mime: $mime);

        $response = $this->get(route('nodes.preview', $node))->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
            ->and($response->headers->get('Content-Type'))->toBe($sent)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    })->with([
        ['image/png', 'image/png'],
        ['image/jpeg', 'image/jpeg'],
        ['video/mp4', 'video/mp4'],
        ['audio/mpeg', 'audio/mpeg'],
        ['application/pdf', 'application/pdf'],
        ['text/plain', 'text/plain; charset=UTF-8'],
        ['application/json', 'text/plain; charset=UTF-8'],
        ['text/x-php', 'text/plain; charset=UTF-8'],
        'html is shown as plain text' => ['text/html', 'text/plain; charset=UTF-8'],
    ]);

    it('forces a download for everything else', function (string $mime) {
        $node = storedFile($this->user, 'f.bin', '<script>alert(1)</script>', mime: $mime);

        $response = $this->get(route('nodes.preview', $node))->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
    })->with(['image/svg+xml', 'application/xhtml+xml', 'application/octet-stream', 'application/x-msdownload', '']);

    it('sandboxes inline documents except PDFs', function () {
        $text = storedFile($this->user, 'a.txt', 'x');
        $image = storedFile($this->user, 'a.png', 'x', mime: 'image/png');
        $pdf = storedFile($this->user, 'a.pdf', 'x', mime: 'application/pdf');

        foreach ([$text, $image] as $node) {
            expect($this->get(route('nodes.preview', $node))->headers->get('Content-Security-Policy'))->toContain('sandbox');
        }

        expect($this->get(route('nodes.preview', $pdf))->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('never sends the download route inline', function () {
        $node = storedFile($this->user, 'a.png', 'x', mime: 'image/png');

        expect($this->get(route('nodes.download', $node))->headers->get('Content-Disposition'))->toStartWith('attachment;');
    });
});
