<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The native apps offered for download on the project page.
 *
 * The files live in one folder outside the repository (`FERRITE_DOWNLOADS_PATH`), named
 * `Ferrite-<version>.<ext>`: `.dmg` or `.zip` for the Mac, `.apk` for Android. The newest
 * version of each is offered; a platform with no file shows as not published yet.
 */
class Downloads
{
    /** @var array<string, array{name: string, extensions: list<string>, mime: string}> */
    public const PLATFORMS = [
        'mac' => ['name' => 'macOS', 'extensions' => ['dmg', 'zip'], 'mime' => 'application/octet-stream'],
        'android' => ['name' => 'Android', 'extensions' => ['apk'], 'mime' => 'application/vnd.android.package-archive'],
    ];

    /** @return array<string, array{file: string, path: string, version: string, size: int, sha256: string}|null> by platform */
    public static function all(): array
    {
        $found = array_fill_keys(array_keys(self::PLATFORMS), null);
        $dir = rtrim((string) config('ferrite.downloads_path'), '/');

        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
            $size = is_file("$dir/$file") ? filesize("$dir/$file") : false;

            if ($size === false || ! preg_match('/^Ferrite-(\d+(?:\.\d+){0,3})\.([a-z]+)$/', $file, $m)) {
                continue;
            }

            foreach (self::PLATFORMS as $key => $platform) {
                if (! in_array($m[2], $platform['extensions'], true)) {
                    continue;
                }

                if ($found[$key] === null || version_compare($m[1], $found[$key]['version'], '>')) {
                    $found[$key] = ['file' => $file, 'path' => "$dir/$file", 'version' => $m[1], 'size' => $size, 'sha256' => ''];
                }
            }
        }

        foreach ($found as $key => $item) {
            if ($item === null) {
                continue;
            }

            // Hashing tens of megabytes on every page view is wasteful: keep it until the file changes.
            $hash = Cache::remember('download-sha256:'.$item['file'].':'.$item['size'].':'.filemtime($item['path']), now()->addDays(30), fn () => hash_file('sha256', $item['path']));

            // A file that cannot be read is not offered.
            $found[$key] = is_string($hash) ? ['sha256' => $hash] + $item : null;
        }

        return $found;
    }

    /** @return array{file: string, path: string, version: string, size: int, sha256: string}|null */
    public static function find(string $file): ?array
    {
        foreach (self::all() as $item) {
            if ($item !== null && $item['file'] === $file) {
                return $item;
            }
        }

        return null;
    }
}
