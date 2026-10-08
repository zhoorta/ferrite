<?php

namespace App\Support;

use App\Models\Node;
use App\Models\NodeContent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reads the text out of a stored file for content search: text-like files directly (the start of
 * them, valid UTF-8 only) and PDFs through `pdftotext`. Everything else is skipped, and so is
 * anything binary, too big or without text. The result is kept per content (sha256), so identical
 * files are read once.
 */
class ContentExtractor
{
    /** Types that count as text when the mime type is generic. */
    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'yml', 'yaml', 'toml', 'ini', 'conf', 'env', 'xml', 'html', 'htm', 'css',
        'js', 'mjs', 'ts', 'jsx', 'tsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'c', 'h', 'cpp', 'cs', 'sh', 'sql', 'tex', 'rst',
    ];

    public function __construct(private StorageManager $storage) {}

    public function extract(Node $node): NodeContent
    {
        [$status, $reason, $text] = $this->read($node);

        return NodeContent::query()->updateOrCreate(
            ['sha256' => (string) $node->sha256],
            ['status' => $status, 'reason' => $reason, 'text' => $text, 'extracted_at' => now()],
        );
    }

    /**
     * @return array{string, string|null, string|null} status, reason and text
     */
    private function read(Node $node): array
    {
        if ($node->disk === null || $node->path === null) {
            return [NodeContent::SKIPPED, 'no stored file', null];
        }

        $kind = $this->kind($node);

        if ($kind === 'pdf') {
            return $this->readPdf($node);
        }

        if ($kind === 'text') {
            return $this->readText($node);
        }

        return [NodeContent::SKIPPED, 'not a text file or PDF', null];
    }

    private function kind(Node $node): ?string
    {
        $kind = FileKind::of($node->mime);

        if ($kind === 'pdf' || $kind === 'text') {
            return $kind;
        }

        $generic = $node->mime === null || in_array($node->mime, ['application/octet-stream', 'binary/octet-stream'], true);

        return $generic && in_array(strtolower(pathinfo($node->name, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true) ? 'text' : null;
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function readText(Node $node): array
    {
        $limit = max(1, (int) config('ferrite.search_max_text_kb')) * 1024;
        $stream = $this->storage->openStream($node->disk, (string) $node->path, $node->size);

        try {
            $raw = (string) stream_get_contents($stream, $limit);
        } finally {
            fclose($stream);
        }

        return $this->clean($raw);
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function readPdf(Node $node): array
    {
        if ($node->size > max(1, (int) config('ferrite.search_max_pdf_mb')) * 1024 * 1024) {
            return [NodeContent::SKIPPED, 'PDF too big', null];
        }

        $binary = (new ExecutableFinder)->find((string) config('ferrite.pdftotext'));

        if ($binary === null) {
            throw new RuntimeException('pdftotext is not installed');
        }

        File::ensureDirectoryExists((string) config('ferrite.tmp_path'));
        $tmp = rtrim((string) config('ferrite.tmp_path'), '/').'/extract-'.Str::random(20).'.pdf';

        try {
            $source = $this->storage->openStream($node->disk, (string) $node->path, $node->size);
            $target = fopen($tmp, 'wb') ?: throw new RuntimeException('Cannot write a temporary file');

            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($source);
                fclose($target);
            }

            $process = new Process([$binary, '-q', '-enc', 'UTF-8', '-nopgbrk', $tmp, '-'], timeout: 120);
            $process->run();

            if (! $process->isSuccessful()) {
                // Encrypted or damaged PDFs: nothing a retry will fix.
                return [NodeContent::SKIPPED, 'PDF could not be read', null];
            }

            return $this->clean(substr($process->getOutput(), 0, max(1, (int) config('ferrite.search_max_text_kb')) * 1024));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Valid UTF-8 without control characters, whitespace collapsed. A cut in the middle of a
     * character at the end is forgiven; anything else invalid, or a NUL byte, means it is not text.
     *
     * @return array{string, string|null, string|null}
     */
    private function clean(string $raw): array
    {
        if (str_contains($raw, "\0")) {
            return [NodeContent::SKIPPED, 'binary', null];
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        for ($cut = 0; $cut <= 3 && ! mb_check_encoding($raw, 'UTF-8'); $cut++) {
            $raw = substr($raw, 0, -1);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return [NodeContent::SKIPPED, 'not UTF-8 text', null];
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $raw)));

        return $text === '' ? [NodeContent::SKIPPED, 'no text', null] : [NodeContent::INDEXED, null, $text];
    }
}
