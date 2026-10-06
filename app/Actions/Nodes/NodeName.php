<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use Illuminate\Validation\ValidationException;

/**
 * Rules for file and folder names. Names only live in the database, so the limits are about
 * keeping them sane for display, downloads and ZIP entries.
 */
class NodeName
{
    /**
     * @throws ValidationException
     */
    public static function normalize(string $name): string
    {
        $name = trim($name);

        $error = match (true) {
            $name === '' => __('Enter a name.'),
            mb_strlen($name) > 255 => __('The name may not be longer than 255 characters.'),
            in_array($name, ['.', '..'], true) => __('That name is not allowed.'),
            (bool) preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $name) => __('The name may not contain slashes or control characters.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['name' => $error]);
        }

        return $name;
    }

    /**
     * Names are unique per folder among non-trashed nodes, compared case-insensitively.
     *
     * @throws ValidationException
     */
    public static function assertAvailable(int $ownerId, ?int $parentId, string $name, ?int $ignoreNodeId = null): void
    {
        if (self::taken($ownerId, $parentId, $name, $ignoreNodeId)) {
            throw ValidationException::withMessages(['name' => __('Something with that name already exists here.')]);
        }
    }

    public static function taken(int $ownerId, ?int $parentId, string $name, ?int $ignoreNodeId = null): bool
    {
        return Node::query()
            ->where('owner_id', $ownerId)
            ->where('parent_id', $parentId)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreNodeId, fn ($query) => $query->whereKeyNot($ignoreNodeId))
            ->exists();
    }

    /**
     * The name itself if free, otherwise the first free "name (2)", "name (3)"... For files the
     * suffix goes before the extension.
     */
    public static function available(int $ownerId, ?int $parentId, string $name, bool $isFile, ?int $ignoreNodeId = null): string
    {
        if (! self::taken($ownerId, $parentId, $name, $ignoreNodeId)) {
            return $name;
        }

        [$base, $extension] = $isFile && str_contains($name, '.') && ! str_starts_with($name, '.')
            ? [pathinfo($name, PATHINFO_FILENAME), '.'.pathinfo($name, PATHINFO_EXTENSION)]
            : [$name, ''];

        for ($i = 2; ; $i++) {
            $candidate = "{$base} ({$i}){$extension}";

            if (! self::taken($ownerId, $parentId, $candidate, $ignoreNodeId)) {
                return $candidate;
            }
        }
    }
}
