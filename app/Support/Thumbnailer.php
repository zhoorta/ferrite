<?php

namespace App\Support;

use App\Models\Node;
use Throwable;

/**
 * Makes small JPEG thumbnails of images with GD and caches them next to the blob.
 * Large or corrupt images get none, so an upload cannot exhaust memory here.
 */
class Thumbnailer
{
    public const SIZE = 256;

    private const MAX_SOURCE_BYTES = 30 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;

    public function __construct(private StorageManager $storage) {}

    public function supports(Node $node): bool
    {
        return $node->isFile()
            && $node->disk_id !== null
            && $node->path !== null
            && $node->size <= self::MAX_SOURCE_BYTES
            && FileKind::isThumbnailable($node->mime);
    }

    /**
     * JPEG bytes of the thumbnail, or null when there is none.
     */
    public function get(Node $node): ?string
    {
        if (! $this->supports($node) || $node->disk === null || $node->path === null) {
            return null;
        }

        $filesystem = $this->storage->filesystem($node->disk);
        $cacheKey = "thumbnails/{$node->path}.jpg";

        if ($filesystem->exists($cacheKey)) {
            return $filesystem->get($cacheKey);
        }

        try {
            $source = $filesystem->get($node->path);
            $thumbnail = is_string($source) ? $this->render($source) : null;
        } catch (Throwable) {
            return null;
        }

        if ($thumbnail !== null) {
            $filesystem->put($cacheKey, $thumbnail);
        }

        return $thumbnail;
    }

    private function render(string $source): ?string
    {
        $info = @getimagesizefromstring($source);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        // GD keeps about 4 bytes per pixel, and the resize needs a second, smaller copy.
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS || ! $this->hasMemoryFor($width * $height * 5)) {
            return null;
        }

        $image = @imagecreatefromstring($source);

        if ($image === false) {
            return null;
        }

        $image = $this->orient($image, $source);
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(1, self::SIZE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 80);

        return ob_get_clean() ?: null;
    }

    private function orient(\GdImage $image, string $source): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($source, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($source));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function hasMemoryFor(int $bytes): bool
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return true;
        }

        return $bytes < ini_parse_quantity($limit) - memory_get_usage();
    }
}
