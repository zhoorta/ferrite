<?php

use App\Enums\Permission;
use App\Jobs\ExtractContent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs"]);
    $this->base = $base;

    $this->user = User::factory()->create();
    $this->music = Node::factory()->for($this->user, 'owner')->create(['name' => 'Music']);
    $this->album = Node::factory()->for($this->user, 'owner')->create(['name' => 'Blue Album', 'parent_id' => $this->music->id]);
    $this->inside = storedFile($this->user, 'blue notes.txt', 'the quick brown fox', $this->album, 'text/plain');
    $this->outside = storedFile($this->user, 'blue secret.txt', 'the quick brown fox hides here', null, 'text/plain');
    $this->plain = apiToken($this->user, $this->music);
});

afterEach(fn () => File::deleteDirectory($this->base));

function apiSearch(string $plain, string $query)
{
    return apiGet('/api/v1/files?'.$query, $plain);
}

it('searches names below the token folder, files and folders, with paths relative to it', function () {
    $items = collect(apiSearch($this->plain, 'q=blue')->assertOk()->json('data'))->keyBy('name');

    expect($items->keys()->sort()->values()->all())->toBe(['Blue Album', 'blue notes.txt'])
        ->and($items['Blue Album'])->toMatchArray(['id' => $this->album->id, 'type' => 'folder', 'path' => 'Blue Album', 'size' => null])
        ->and($items['blue notes.txt'])->toMatchArray(['id' => $this->inside->id, 'type' => 'file', 'path' => 'Blue Album/blue notes.txt', 'sha256' => hash('sha256', 'the quick brown fox')])
        ->and(apiSearch($this->plain, 'q=blue')->json('next_cursor'))->toBeNull();
});

it('never shows matches outside the token folder, and a whole-drive token sees everything', function () {
    $names = collect(apiSearch($this->plain, 'q=secret')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe([]);

    $all = collect(apiSearch(apiToken($this->user, null), 'q=blue')->json('data'))->pluck('name')->sort()->values()->all();
    expect($all)->toBe(['Blue Album', 'blue notes.txt', 'blue secret.txt']);
});

it('is not fooled by the limit: matches outside the folder do not push inside ones out', function () {
    foreach (range(1, 60) as $i) {
        storedFile($this->user, sprintf('zz match %02d.txt', $i), 'x');
    }
    storedFile($this->user, 'zz match inside.txt', 'x', $this->album);

    expect(collect(apiSearch($this->plain, 'q=zz match')->json('data'))->pluck('name')->all())->toBe(['zz match inside.txt']);
});

it('leaves out the token folder itself, trashed items, items in trashed folders, shared-with-me nodes and other accounts', function () {
    $this->inside->forceFill(['trashed_at' => now()])->save();
    $other = User::factory()->create();
    $theirs = Node::factory()->for($other, 'owner')->create(['name' => 'Music blue']);
    $theirs->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);
    $trashedFolder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Old', 'parent_id' => $this->music->id, 'trashed_at' => now()]);
    storedFile($this->user, 'blue in trash.txt', 'x', $trashedFolder);

    expect(collect(apiSearch($this->plain, 'q=blue')->json('data'))->pluck('name')->all())->toBe(['Blue Album'])
        ->and(collect(apiSearch($this->plain, 'q=music')->json('data'))->pluck('name')->all())->toBe([]);
});

it('treats like wildcards in the term as text', function () {
    storedFile($this->user, '100% done.txt', 'x', $this->music);
    storedFile($this->user, '100 done.txt', 'x', $this->music);

    expect(collect(apiSearch($this->plain, 'q=100%25')->json('data'))->pluck('name')->all())->toBe(['100% done.txt']);
});

it('validates the query and the scope, and ignores an empty q', function () {
    apiSearch($this->plain, 'q='.str_repeat('x', 201))->assertUnprocessable();
    apiSearch($this->plain, 'q=blue&scope=everything')->assertUnprocessable();

    expect(apiSearch($this->plain, 'q=')->assertOk()->json('data.*.path'))->toContain('Blue Album/blue notes.txt');
});

it('needs a valid token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/files?q=blue')->assertUnauthorized();
});

it('searches the text of files with a plain snippet, only inside the token folder', function () {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('An InnoDB FULLTEXT index only sees committed rows; the driver is covered in the content search tests.');
    }

    config(['ferrite.search_contents' => true]);
    ExtractContent::dispatchSync($this->inside->id);
    ExtractContent::dispatchSync($this->outside->id);

    $items = apiSearch($this->plain, 'q=fox&scope=content')->assertOk()->json('data');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['id' => $this->inside->id, 'path' => 'Blue Album/blue notes.txt'])
        ->and($items[0]['snippet'])->toContain('fox')->not->toContain("\x01")->not->toContain("\x02");
});

it('answers an empty content search when content search is off', function () {
    config(['ferrite.search_contents' => false]);

    expect(apiSearch($this->plain, 'q=fox&scope=content')->assertOk()->json('data'))->toBe([]);
});
