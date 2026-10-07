<?php

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Keep blobs and temporary uploads of every feature test out of the real storage.
        $this->storageBase = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
        config(['ferrite.tmp_path' => "{$this->storageBase}/tmp", 'ferrite.local_root' => "{$this->storageBase}/blobs"]);
    })
    ->afterEach(fn () => File::deleteDirectory($this->storageBase))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A file node whose blob really exists on the default disk.
 */
function storedFile(User $owner, string $name, string $content, ?Node $parent = null, ?string $mime = 'text/plain'): Node
{
    $storage = app(StorageManager::class);
    $disk = $storage->default();
    $key = $storage->newKey();
    $storage->filesystem($disk)->put($key, $content);

    $node = new Node([
        'parent_id' => $parent?->id,
        'type' => NodeType::File,
        'name' => $name,
        'disk_id' => $disk->id,
        'path' => $key,
        'size' => strlen($content),
        'mime' => $mime,
        'sha256' => hash('sha256', $content),
    ]);
    $node->owner_id = $owner->id;
    $node->save();

    return $node;
}

/**
 * @return array<string, string|null> entry name => contents (null for directories)
 */
function zipEntries($response): array
{
    $path = tempnam(sys_get_temp_dir(), 'ferrite-zip');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entries[$name] = str_ends_with($name, '/') ? null : $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($path);
    ksort($entries);

    return $entries;
}
