<?php

namespace App\Support;

/**
 * What the browser may be allowed to show inline. Everything not listed here is served as a
 * download, because files come from users and are served from the app's own origin: an HTML or
 * SVG file rendered inline could run scripts with the viewer's session.
 */
class FileKind
{
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    private const VIDEOS = ['video/mp4', 'video/webm', 'video/ogg'];

    private const AUDIO = [
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm',
        'audio/flac', 'audio/x-flac', 'audio/mp4', 'audio/aac', 'audio/x-m4a',
    ];

    private const TEXT_APPLICATIONS = [
        'application/json', 'application/xml', 'application/x-yaml', 'application/x-httpd-php',
        'application/javascript', 'application/x-sh', 'application/sql',
    ];

    /**
     * 'image', 'pdf', 'video', 'audio' or 'text' when the type may be previewed, otherwise null.
     */
    public static function of(?string $mime): ?string
    {
        $mime = self::base($mime);

        return match (true) {
            $mime === null => null,
            in_array($mime, self::IMAGES, true) => 'image',
            $mime === 'application/pdf' => 'pdf',
            in_array($mime, self::VIDEOS, true) => 'video',
            in_array($mime, self::AUDIO, true) => 'audio',
            str_starts_with($mime, 'text/'), in_array($mime, self::TEXT_APPLICATIONS, true) => 'text',
            default => null,
        };
    }

    /**
     * The Content-Type to send when showing the file inline, or null if it must be downloaded.
     * Text-like files, HTML included, are always sent as plain text.
     */
    public static function inlineType(?string $mime): ?string
    {
        return match (self::of($mime)) {
            null => null,
            'text' => 'text/plain; charset=UTF-8',
            default => self::base($mime),
        };
    }

    public static function isThumbnailable(?string $mime): bool
    {
        return in_array(self::base($mime), self::IMAGES, true);
    }

    private static function base(?string $mime): ?string
    {
        return $mime === null ? null : strtolower(trim(explode(';', $mime)[0]));
    }
}
