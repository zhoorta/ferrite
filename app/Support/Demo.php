<?php

namespace App\Support;

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\PurgeNode;
use App\Enums\NodeType;
use App\Enums\UserRole;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The public try-out instance (FERRITE_DEMO=true): every visitor gets a throwaway account with a
 * few sample files, deleted again after a couple of hours.
 */
class Demo
{
    public const DOMAIN = 'demo.ferrite.invalid';

    /** @var array<string, list<string>> Sample pictures: name => gradient colours, top to bottom. */
    private const PICTURES = [
        'Lisbon rooftops' => ['#ffb347', '#ff5e62', '#2b1055'],
        'Faial sunset' => ['#43cea2', '#185a9d', '#0b132b'],
        'Atlantic swell' => ['#c471f5', '#fa71cd', '#1a0933'],
        'Peak at dawn' => ['#f9d423', '#ff4e50', '#3a1c71'],
        'Green valley' => ['#56ab2f', '#a8e063', '#134e5e'],
        'Harbour night' => ['#00c6ff', '#0072ff', '#001f3f'],
        'Autumn light' => ['#f2994a', '#f2c94c', '#6b3e26'],
        'Hot springs' => ['#ee0979', '#ff6a00', '#2d0a31'],
    ];

    /** Uploads allowed in the demo, by extension and by detected content type: no executables, archives, scripts or HTML. */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'csv', 'json'];

    private const MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json'];

    public function __construct(private StorageManager $storage, private CreateFolder $folders, private PurgeNode $purge) {}

    public static function enabled(): bool
    {
        return (bool) config('ferrite.demo.enabled');
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && str_ends_with($user->email, '@'.self::DOMAIN);
    }

    /**
     * Refuse an upload a demo visitor may not make, before any byte is sent.
     *
     * @throws ValidationException
     */
    public static function assertUploadAllowed(?User $user, string $name, int $size): void
    {
        if (! self::isDemoUser($user)) {
            return;
        }

        if (! in_array(Str::lower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['path' => __('The demo only accepts images, PDF and plain text files (:types).', ['types' => implode(', ', self::EXTENSIONS)])]);
        }

        if ($size > config('ferrite.demo.max_file_mb') * 1024 * 1024) {
            throw ValidationException::withMessages(['size' => __('In the demo a file can be at most :mb MB.', ['mb' => config('ferrite.demo.max_file_mb')])]);
        }
    }

    /**
     * Refuse a finished upload whose content is not one of the allowed types, whatever its name says.
     *
     * @throws ValidationException
     */
    public static function assertContentAllowed(?User $user, string $mime): void
    {
        if (self::isDemoUser($user) && ! in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['path' => __('The demo only accepts images, PDF and plain text files.')]);
        }
    }

    public function full(): bool
    {
        return User::query()->where('email', 'like', '%@'.self::DOMAIN)->count() >= config('ferrite.demo.max_accounts');
    }

    public function createVisitor(): User
    {
        $user = new User(['name' => 'Demo visitor', 'email' => Str::lower(Str::random(12)).'@'.self::DOMAIN, 'password' => Str::random(40)]);
        $user->role = UserRole::User;
        $user->quota_bytes = config('ferrite.demo.quota_mb') * 1024 * 1024;
        $user->email_verified_at = now();
        $user->save();

        $this->seed($user);

        return $user;
    }

    /** Delete demo accounts past their lifetime, with all their files. */
    public function prune(): int
    {
        $users = User::query()
            ->where('email', 'like', '%@'.self::DOMAIN)
            ->where('created_at', '<', now()->subMinutes(config('ferrite.demo.ttl_minutes')))
            ->get();

        foreach ($users as $user) {
            Node::query()->where('owner_id', $user->id)->whereNull('parent_id')->each(fn (Node $node) => $this->purge->purge($node));
            $user->delete();
        }

        return $users->count();
    }

    private function seed(User $user): void
    {
        $disk = $this->storage->default();
        $files = $this->storage->filesystem($disk);

        $store = function (?Node $parent, string $name, string $content, string $mime) use ($user, $disk, $files): void {
            $key = $this->storage->newKey();
            $files->write($key, $content);

            $node = new Node([
                'parent_id' => $parent?->id, 'type' => NodeType::File, 'name' => $name, 'disk_id' => $disk->id,
                'path' => $key, 'size' => strlen($content), 'mime' => $mime, 'sha256' => hash('sha256', $content),
            ]);
            $node->owner_id = $user->id;
            $node->save();

            $user->increment('used_bytes', strlen($content));
        };

        $store(null, 'Welcome to Ferrite.md', $this->welcome(), 'text/markdown');

        $photos = $this->folders->handle($user, null, 'Pictures');
        foreach (self::PICTURES as $name => $colours) {
            $store($photos, "$name.jpg", $this->picture($colours), 'image/jpeg');
        }

        $work = $this->folders->handle($user, null, 'Projects');
        $store($work, 'Budget 2026.csv', "Month,Income,Costs\nJanuary,3200,2100\nFebruary,2900,2050\nMarch,3500,2300\nApril,3100,2150\n", 'text/csv');
        $store($work, 'Notes.txt', "Ideas\n- try dragging a file onto a folder\n- select a few rows and download them as one ZIP\n- share a folder with a link and a password\n", 'text/plain');

        $this->folders->handle($user, null, 'Empty folder, drop files here');
    }

    private function welcome(): string
    {
        $minutes = config('ferrite.demo.ttl_minutes');
        $quota = config('ferrite.demo.quota_mb');

        return <<<MD
        # Welcome to the Ferrite demo

        This is a throwaway account on a public demo. Poke around: upload small images, PDFs and text files
        (up to {$quota} MB in total), make folders, rename, move, search, preview, empty the trash.
        Sharing is switched off in the demo.

        **Everything here, and the account itself, is deleted {$minutes} minutes after you started.**
        Do not upload anything private.

        Ferrite is free software (AGPL-3.0) you can run on your own server. Get it from the project page.
        MD;
    }

    /** A small sunset-like gradient JPEG, so the demo shows real thumbnails (GD is required by Ferrite anyway). */
    /** @param  list<string>  $colours */
    private function picture(array $colours): string
    {
        $image = imagecreatetruecolor(800, 600);

        for ($y = 0; $y < 600; $y++) {
            $position = $y / 600 * (count($colours) - 1);
            $i = min((int) $position, count($colours) - 2);
            $mix = $position - $i;
            [$r1, $g1, $b1] = sscanf($colours[$i], '#%02x%02x%02x');
            [$r2, $g2, $b2] = sscanf($colours[$i + 1], '#%02x%02x%02x');

            $channel = fn (int $from, int $to): int => max(0, min(255, (int) round($from + ($to - $from) * $mix)));

            imageline($image, 0, $y, 800, $y, (int) imagecolorallocate($image, $channel($r1, $r2), $channel($g1, $g2), $channel($b1, $b2)));
        }

        imagefilledellipse($image, 570, 190, 120, 120, (int) imagecolorallocatealpha($image, 255, 255, 255, 70));

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
