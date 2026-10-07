<?php

use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\StorageUsage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['quota_bytes' => 1000, 'used_bytes' => 600]);
    $this->actingAs($this->user);
});

function usageFile(User $user, array $attributes = [], ?Node $parent = null): Node
{
    $factory = Node::factory()->file()->for($user, 'owner');

    return ($parent ? $factory->inside($parent) : $factory)->create($attributes);
}

it('classifies mime types', function () {
    expect(StorageUsage::kind('image/svg+xml'))->toBe('image')
        ->and(StorageUsage::kind('video/mp4'))->toBe('video')
        ->and(StorageUsage::kind('audio/mpeg'))->toBe('audio')
        ->and(StorageUsage::kind('application/pdf'))->toBe('document')
        ->and(StorageUsage::kind('text/plain; charset=utf-8'))->toBe('document')
        ->and(StorageUsage::kind('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBe('document')
        ->and(StorageUsage::kind('application/zip'))->toBe('archive')
        ->and(StorageUsage::kind('application/octet-stream'))->toBe('other')
        ->and(StorageUsage::kind(null))->toBe('other');
});

it('breaks usage down by type, folder and trash', function () {
    $docs = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
    $deep = Node::factory()->for($this->user, 'owner')->inside($docs)->create(['name' => 'Deep']);
    usageFile($this->user, ['mime' => 'image/png', 'size' => 100], $deep);
    usageFile($this->user, ['mime' => 'application/pdf', 'size' => 50], $docs);
    usageFile($this->user, ['mime' => 'video/mp4', 'size' => 300]);

    $trashedFolder = Node::factory()->for($this->user, 'owner')->trashed()->create(['name' => 'Old']);
    usageFile($this->user, ['mime' => 'image/png', 'size' => 70], $trashedFolder);

    $usage = app(StorageUsage::class)->forUser($this->user);

    expect($usage['files'])->toBe(3)
        ->and($usage['active'])->toBe(450)
        ->and($usage['trash'])->toBe(70)
        ->and($usage['kinds']['image'])->toBe(['bytes' => 100, 'count' => 1])
        ->and($usage['kinds']['document'])->toBe(['bytes' => 50, 'count' => 1])
        ->and($usage['kinds']['video'])->toBe(['bytes' => 300, 'count' => 1])
        ->and($usage['loose'])->toBe(300)
        ->and($usage['folders'])->toBe([['id' => $docs->id, 'name' => 'Docs', 'bytes' => 150]])
        ->and($usage['largest'][0]['size'])->toBe(300);
});

it('counts shared blobs once as physical and reports what deduplication saves', function () {
    $disk = StorageDisk::factory()->create();
    usageFile($this->user, ['disk_id' => $disk->id, 'path' => 'blob-1', 'size' => 200]);
    usageFile($this->user, ['disk_id' => $disk->id, 'path' => 'blob-1', 'size' => 200]);
    usageFile($this->user, ['disk_id' => $disk->id, 'path' => 'blob-2', 'size' => 50]);

    $usage = app(StorageUsage::class);

    expect($usage->forUser($this->user)['saved'])->toBe(200)
        ->and($usage->physicalByDisk())->toBe([$disk->id => 250]);
});

it('ignores other people\'s files', function () {
    usageFile(User::factory()->create(), ['size' => 999]);

    expect(app(StorageUsage::class)->forUser($this->user)['files'])->toBe(0);
});

it('shows the usage page with the quota', function () {
    usageFile($this->user, ['name' => 'big.mp4', 'mime' => 'video/mp4', 'size' => 500]);

    $this->get(route('usage'))
        ->assertOk()
        ->assertSee('big.mp4')
        ->assertSee('of 1 KB')
        ->assertSeeHtml('data-test="usage-kind-video"');
});

it('shows a hint when there are no files', function () {
    Livewire::test('pages::files.usage')->assertSee('Nothing here yet');
});

it('requires a login', function () {
    auth()->logout();

    $this->get(route('usage'))->assertRedirect(route('login'));
});
