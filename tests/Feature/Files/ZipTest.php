<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('zips a folder with its subfolders, empty folders included', function () {
    $root = Node::factory()->for($this->user, 'owner')->create(['name' => 'Project']);
    $docs = Node::factory()->inside($root)->create(['name' => 'docs']);
    Node::factory()->inside($root)->create(['name' => 'empty']);
    storedFile($this->user, 'readme.txt', 'read me', $root);
    storedFile($this->user, 'guide.txt', 'the guide', $docs);
    storedFile($this->user, 'üñí.txt', 'unicode', $docs);

    $response = $this->get(route('nodes.zip', $root))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Disposition'))->toContain('Project.zip')
        ->and(zipEntries($response))->toBe([
            'docs/' => null,
            'docs/guide.txt' => 'the guide',
            'docs/üñí.txt' => 'unicode',
            'empty/' => null,
            'readme.txt' => 'read me',
        ]);
});

it('leaves out trashed files and folders', function () {
    $root = Node::factory()->for($this->user, 'owner')->create();
    $gone = Node::factory()->inside($root)->create(['name' => 'gone']);
    storedFile($this->user, 'inside-gone.txt', 'x', $gone);
    storedFile($this->user, 'kept.txt', 'kept', $root);
    storedFile($this->user, 'binned.txt', 'x', $root)->forceFill(['trashed_at' => now()])->save();
    $gone->forceFill(['trashed_at' => now()])->save();

    expect(zipEntries($this->get(route('nodes.zip', $root))))->toBe(['kept.txt' => 'kept']);
});

it('handles a larger file', function () {
    $root = Node::factory()->for($this->user, 'owner')->create();
    $content = random_bytes(3_000_000);
    storedFile($this->user, 'big.bin', $content, $root, 'application/octet-stream');

    expect(zipEntries($this->get(route('nodes.zip', $root)))['big.bin'])->toBe($content);
});

it('needs view permission, and only folders can be zipped', function () {
    $root = Node::factory()->for($this->user, 'owner')->create();
    $file = storedFile($this->user, 'a.txt', 'x');
    $stranger = User::factory()->create();

    $this->get(route('nodes.zip', $file))->assertNotFound();

    $this->actingAs($stranger);
    $this->get(route('nodes.zip', $root))->assertForbidden();

    $root->sharedWith()->attach($stranger, ['permission' => Permission::View->value]);
    $this->get(route('nodes.zip', $root))->assertOk();

    $root->forceFill(['trashed_at' => now()])->save();
    $this->actingAs($this->user)->get(route('nodes.zip', $root))->assertNotFound();
});
