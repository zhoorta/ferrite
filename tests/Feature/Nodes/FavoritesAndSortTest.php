<?php

use App\Models\Node;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('sorting', function () {
    beforeEach(function () {
        Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'b.txt', 'size' => 10, 'updated_at' => now()->subDay()]);
        Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt', 'size' => 300, 'updated_at' => now()->subDays(3)]);
        Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'c.txt', 'size' => 20, 'updated_at' => now()]);
        Node::factory()->for($this->user, 'owner')->create(['name' => 'Zeta']);
    });

    it('sorts by name by default, folders first', function () {
        Livewire::test('pages::files.browser')->assertSeeInOrder(['Zeta', 'a.txt', 'b.txt', 'c.txt']);
    });

    it('sorts by size and flips the direction', function () {
        Livewire::test('pages::files.browser')
            ->call('sortBy', 'size', 'asc')
            ->assertSeeInOrder(['Zeta', 'b.txt', 'c.txt', 'a.txt'])
            ->call('sortBy', 'size', 'desc')
            ->assertSeeInOrder(['Zeta', 'a.txt', 'c.txt', 'b.txt']);
    });

    it('sorts by modified date', function () {
        Livewire::test('pages::files.browser')
            ->call('sortBy', 'modified', 'desc')
            ->assertSeeInOrder(['Zeta', 'c.txt', 'b.txt', 'a.txt']);
    });

    it('toggles the direction when the same column is chosen again', function () {
        Livewire::test('pages::files.browser')
            ->call('sortBy', 'name')
            ->assertSet('direction', 'desc')
            ->call('sortBy', 'name')
            ->assertSet('direction', 'asc');
    });

    it('ignores an unknown column', function () {
        Livewire::test('pages::files.browser')->call('sortBy', 'nonsense; drop table nodes')->assertSet('sort', 'name');
    });
});

describe('favorites', function () {
    it('stars and unstars from the browser', function () {
        $file = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);

        Livewire::test('pages::files.browser')
            ->call('toggleFavorite', $file->id)
            ->assertSeeHtml('data-test="favorite-mark"')
            ->assertSee('Remove from favorites')
            ->call('toggleFavorite', $file->id)
            ->assertDontSeeHtml('data-test="favorite-mark"');

        expect($this->user->favorites()->count())->toBe(0);
    });

    it('lists favorites, newest of nothing hidden: trashed and unreachable ones are left out', function () {
        $kept = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'kept.txt']);
        $binned = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'binned.txt']);
        $this->user->favorites()->attach([$kept->id, $binned->id]);
        $binned->forceFill(['trashed_at' => now()])->save();

        $this->get(route('favorites'))->assertOk()->assertSee('kept.txt')->assertDontSee('binned.txt');
    });

    it('can star a shared node but not a stranger\'s', function () {
        $owner = User::factory()->create();
        $shared = Node::factory()->file()->for($owner, 'owner')->create(['name' => 'shared.txt']);
        $private = Node::factory()->file()->for($owner, 'owner')->create(['name' => 'private.txt']);
        $shared->sharedWith()->attach($this->user, ['permission' => 'view']);

        Livewire::test('pages::files.favorites');
        Livewire::test('pages::files.browser')->call('toggleFavorite', $private->id)->assertForbidden();
        Livewire::test('pages::files.browser')->call('toggleFavorite', $shared->id);

        expect($this->user->favorites()->pluck('nodes.id')->all())->toBe([$shared->id]);
        $this->get(route('favorites'))->assertSee('shared.txt');
    });

    it('removes a favorite from the favorites page', function () {
        $file = Node::factory()->file()->for($this->user, 'owner')->create(['name' => 'a.txt']);
        $this->user->favorites()->attach($file->id);

        Livewire::test('pages::files.favorites')->call('remove', $file->id)->assertSee('No favorites yet');
    });

    it('goes away with the node', function () {
        $file = Node::factory()->file()->for($this->user, 'owner')->create();
        $this->user->favorites()->attach($file->id);

        $file->delete();

        expect(DB::table('favorites')->count())->toBe(0);
    });
});
