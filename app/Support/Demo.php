<?php

namespace App\Support;

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\PurgeNode;
use App\Enums\NodeType;
use App\Enums\UserRole;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The public try-out instance (FERRITE_DEMO=true): every visitor gets a throwaway account with a
 * few sample files, deleted again after a couple of hours.
 */
class Demo
{
    public const DOMAIN = 'demo.ferrite.invalid';

    public function __construct(private StorageManager $storage, private CreateFolder $folders, private PurgeNode $purge) {}

    public static function enabled(): bool
    {
        return (bool) config('ferrite.demo.enabled');
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && str_ends_with($user->email, '@'.self::DOMAIN);
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
        foreach (['Sunrise' => ['#f4a261', '#e76f51'], 'Lagoon' => ['#2a9d8f', '#264653'], 'Plum' => ['#9b5de5', '#f15bb5']] as $name => [$a, $b]) {
            $store($photos, "$name.svg", $this->picture($name, $a, $b), 'image/svg+xml');
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

        This is a throwaway account on a public demo. Poke around: upload files (up to {$quota} MB in total),
        make folders, rename, move, search, preview, share a link, empty the trash.

        **Everything here, and the account itself, is deleted {$minutes} minutes after you started.**
        Do not upload anything private.

        Ferrite is free software (AGPL-3.0) you can run on your own server. Get it from the project page.
        MD;
    }

    private function picture(string $title, string $from, string $to): string
    {
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="640" height="420" viewBox="0 0 640 420">
          <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient></defs>
          <rect width="640" height="420" fill="url(#g)"/>
          <circle cx="470" cy="130" r="64" fill="#fff" fill-opacity=".35"/>
          <text x="40" y="380" font-family="sans-serif" font-size="44" fill="#fff">{$title}</text>
        </svg>
        SVG;
    }
}
