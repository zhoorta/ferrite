<?php

use App\Models\Node;
use App\Models\User;
use App\Support\FolderSizes;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(['quota_bytes' => 1000, 'used_bytes' => 400]);

    $this->docs = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
    $this->deep = Node::factory()->inside($this->docs)->create(['name' => 'Deep']);
    $this->photos = Node::factory()->for($this->user, 'owner')->create(['name' => 'Photos']);

    $this->report = storedFile($this->user, 'report.pdf', str_repeat('r', 50), $this->docs, 'application/pdf');
    $this->movie = storedFile($this->user, 'movie.mp4', str_repeat('m', 300), $this->deep, 'video/mp4');
    $this->pic = storedFile($this->user, 'pic.jpg', str_repeat('p', 100), $this->photos, 'image/jpeg');
    $this->loose = storedFile($this->user, 'note.txt', 'hello', null, 'text/plain');
    $this->gone = storedFile($this->user, 'old.zip', str_repeat('z', 70), $this->photos, 'application/zip');
    $this->gone->forceFill(['trashed_at' => now()])->save();

    DB::table('node_versions')->insert([
        'node_id' => $this->report->id, 'disk_id' => $this->report->disk_id, 'path' => 'v-old', 'size' => 40,
        'created_at' => now(), 'content_at' => now(),
    ]);

    app(FolderSizes::class)->recount();
});

it('shows where the space goes for a whole-drive token', function () {
    $data = apiGet('/api/v1/usage', apiToken($this->user, null, ['read']))->assertOk()->json();

    expect($data['scope'])->toMatchArray(['name' => 'Whole drive', 'files' => 4, 'bytes' => 455, 'loose' => 5, 'trash' => 70, 'versions' => 40])
        ->and($data['quota'])->toBe(['used' => 400, 'limit' => 1000])
        ->and($data['kinds']['video'])->toBe(['bytes' => 300, 'count' => 1])
        ->and($data['kinds']['image'])->toBe(['bytes' => 100, 'count' => 1])
        ->and(collect($data['folders'])->pluck('name')->all())->toBe(['Docs', 'Photos'])
        ->and(collect($data['largest_folders'])->pluck('path')->all())->toBe(['Docs', 'Docs/Deep', 'Photos'])   // at any depth, the trash left out
        ->and(collect($data['largest_files'])->pluck('path')->all())->toBe(['Docs/Deep/movie.mp4', 'Photos/pic.jpg', 'Docs/report.pdf', 'note.txt']);
});

it('is limited to the folder of a folder token', function () {
    $data = apiGet('/api/v1/usage', apiToken($this->user, $this->docs, ['read']))->assertOk()->json();

    expect($data['scope'])->toMatchArray(['name' => 'Docs', 'files' => 2, 'bytes' => 350, 'loose' => 50, 'trash' => 0, 'versions' => 40])
        ->and($data['kinds']['image'])->toBe(['bytes' => 0, 'count' => 0])
        ->and(collect($data['folders'])->pluck('name')->all())->toBe(['Deep'])
        ->and(collect($data['largest_folders'])->pluck('path')->all())->toBe(['Deep'])
        ->and(collect($data['largest_files'])->pluck('path')->all())->toBe(['Deep/movie.mp4', 'report.pdf']);
});

it('needs a token', function () {
    apiGet('/api/v1/usage')->assertUnauthorized();
});
