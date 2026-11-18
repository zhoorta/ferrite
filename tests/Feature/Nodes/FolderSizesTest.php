<?php

use App\Actions\Nodes\CopyNode;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\RestoreNode;
use App\Actions\Nodes\TrashNode;
use App\Models\Node;
use App\Models\User;
use App\Support\FolderSizes;
use Illuminate\Support\Number;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['quota_bytes' => null]);
    $this->actingAs($this->user);

    $this->docs = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
    $this->sub = Node::factory()->inside($this->docs)->create(['name' => 'Sub']);
    $this->other = Node::factory()->for($this->user, 'owner')->create(['name' => 'Other']);

    $this->a = storedFile($this->user, 'a.txt', 'aaaa', $this->docs);        // 4
    $this->b = storedFile($this->user, 'b.txt', 'bbbbbb', $this->sub);       // 6
    app(FolderSizes::class)->recount();
    foreach (['docs', 'sub', 'other', 'a', 'b'] as $name) {
        $this->$name->refresh();
    }
});

function folderSize(Node $folder): int
{
    return $folder->fresh()->size;
}

function sizesAreRight(): bool
{
    return app(FolderSizes::class)->recount(fix: false)['wrong'] === 0;
}

it('counts files and subfolders, and leaves the trash out', function () {
    storedFile($this->user, 'gone.txt', 'xxxxxxxx', $this->docs)->forceFill(['trashed_at' => now()])->save();
    app(FolderSizes::class)->recount();

    expect(folderSize($this->docs))->toBe(10)->and(folderSize($this->sub))->toBe(6)->and(folderSize($this->other))->toBe(0);
});

it('moves a file between folders up and down the tree', function () {
    app(MoveNode::class)->handle($this->user, $this->b, $this->other);

    expect(folderSize($this->docs))->toBe(4)->and(folderSize($this->sub))->toBe(0)->and(folderSize($this->other))->toBe(6)
        ->and(sizesAreRight())->toBeTrue();
});

it('moves a folder with what is in it', function () {
    app(MoveNode::class)->handle($this->user, $this->sub, $this->other);

    expect(folderSize($this->docs))->toBe(4)->and(folderSize($this->other))->toBe(6)->and(sizesAreRight())->toBeTrue();
});

it('takes a trashed file or folder out and puts it back', function () {
    app(TrashNode::class)->handle($this->user, $this->sub);

    expect(folderSize($this->docs))->toBe(4)->and(folderSize($this->sub))->toBe(6)->and(sizesAreRight())->toBeTrue();

    app(RestoreNode::class)->handle($this->user, $this->sub->fresh());

    expect(folderSize($this->docs))->toBe(10)->and(sizesAreRight())->toBeTrue();
});

it('restores to the top when the folder it was in is gone, without touching the others', function () {
    app(TrashNode::class)->handle($this->user, $this->b);
    app(TrashNode::class)->handle($this->user, $this->sub->fresh());
    app(RestoreNode::class)->handle($this->user, $this->b->fresh());

    expect($this->b->fresh()->parent_id)->toBeNull()->and(folderSize($this->docs))->toBe(4)->and(sizesAreRight())->toBeTrue();
});

it('gives a copied folder its own total and adds it to the destination', function () {
    storedFile($this->user, 'gone.txt', 'xxxxxxxx', $this->docs)->forceFill(['trashed_at' => now()])->save();

    $copy = app(CopyNode::class)->handle($this->user, $this->docs, $this->other);

    expect(folderSize($copy))->toBe(10)->and(folderSize($this->other))->toBe(10)->and(folderSize($this->docs))->toBe(10)
        ->and(sizesAreRight())->toBeTrue();
});

it('reports and repairs a wrong size', function () {
    Node::query()->whereKey($this->docs->id)->update(['size' => 999]);

    $this->artisan('ferrite:folders:recount', ['--check' => true])->assertFailed();
    expect(folderSize($this->docs))->toBe(999);

    $this->artisan('ferrite:folders:recount')->assertSuccessful();
    expect(folderSize($this->docs))->toBe(10);

    $this->artisan('ferrite:folders:recount', ['--check' => true])->assertSuccessful();
});

it('shows a folder size in the API listing', function () {
    $token = apiToken($this->user, null, ['read']);

    $rows = collect(apiGet('/api/v1/files?parent=root', $token)->assertOk()->json('data'));

    expect($rows->firstWhere('name', 'Docs')['size'])->toBe(10);
});

it('shows the size of a folder in the file list', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::files.browser')->assertSee('Docs')->assertSee(Number::fileSize(10));
});
