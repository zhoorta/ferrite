<?php

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use App\Support\NodeSearch;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function names($results): array
{
    return $results->map(fn ($r) => $r['node']->name)->all();
}

function search(string $term, ?User $user = null): Collection
{
    return app(NodeSearch::class)->search($user ?? test()->user, $term);
}

it('finds files and folders by part of the name, ignoring case', function () {
    Node::factory()->for($this->user, 'owner')->create(['name' => 'Tax Returns']);
    storedFile($this->user, 'invoice-TAX-2026.pdf', 'x', mime: 'application/pdf');
    storedFile($this->user, 'holiday.jpg', 'x', mime: 'image/jpeg');

    expect(names(search('tax')))->toEqualCanonicalizing(['Tax Returns', 'invoice-TAX-2026.pdf']);
});

it('puts exact matches first, then sorts by name', function () {
    foreach (['b-report', 'report', 'a-report'] as $name) {
        Node::factory()->for($this->user, 'owner')->create(['name' => $name]);
    }

    expect(names(search('report')))->toBe(['report', 'a-report', 'b-report']);
});

it('treats wildcards in the search as plain characters', function () {
    foreach (['100%', 'a_b', 'axb', '50!'] as $name) {
        Node::factory()->for($this->user, 'owner')->create(['name' => $name]);
    }

    expect(names(search('%')))->toBe(['100%'])
        ->and(names(search('a_b')))->toBe(['a_b'])
        ->and(names(search('!')))->toBe(['50!']);
});

it('ignores an empty search', function () {
    Node::factory()->for($this->user, 'owner')->create();

    expect(search('   '))->toBeEmpty();
});

it('leaves out other people\'s files and trashed items', function () {
    Node::factory()->create(['name' => 'secret plans']);
    Node::factory()->for($this->user, 'owner')->trashed()->create(['name' => 'binned plans']);
    $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'old']);
    Node::factory()->inside($folder)->create(['name' => 'inside old plans']);
    Node::factory()->for($this->user, 'owner')->create(['name' => 'my plans']);
    $folder->forceFill(['trashed_at' => now()])->save();

    expect(names(search('plans')))->toBe(['my plans']);
});

it('includes things inside folders shared with the user, with a path from the shared folder', function () {
    $other = User::factory()->create();
    $top = Node::factory()->for($other, 'owner')->create(['name' => 'Company']);
    $shared = Node::factory()->inside($top)->create(['name' => 'Team']);
    $deep = Node::factory()->inside($shared)->create(['name' => 'Budgets']);
    storedFile($other, 'budget-2026.xlsx', 'x', $deep);
    storedFile($other, 'budget-private.xlsx', 'x', $top);
    $shared->sharedWith()->attach($this->user, ['permission' => Permission::View->value]);

    $results = search('budget');

    expect(names($results))->toBe(['Budgets', 'budget-2026.xlsx'])
        ->and($results[1]['path'])->toBe('Shared with me / Team / Budgets')
        ->and($results[1]['folder_id'])->toBe($deep->id)
        ->and($results[0]['path'])->toBe('Shared with me / Team');
});

it('shows the path of own files', function () {
    $a = Node::factory()->for($this->user, 'owner')->create(['name' => 'Work']);
    $b = Node::factory()->inside($a)->create(['name' => '2026']);
    storedFile($this->user, 'plan.txt', 'x', $b);
    storedFile($this->user, 'plan-root.txt', 'x');

    $results = search('plan')->keyBy(fn ($r) => $r['node']->name);

    expect($results['plan.txt']['path'])->toBe('My files / Work / 2026')
        ->and($results['plan.txt']['folder_id'])->toBe($b->id)
        ->and($results['plan-root.txt']['path'])->toBe('My files')
        ->and($results['plan-root.txt']['folder_id'])->toBeNull();
});

it('limits the number of results', function () {
    Node::factory()->count(5)->for($this->user, 'owner')->sequence(fn ($s) => ['name' => "match {$s->index}"])->create();

    expect(app(NodeSearch::class)->search($this->user, 'match', 3))->toHaveCount(3);
});

describe('page', function () {
    it('requires login', function () {
        auth()->logout();

        $this->get(route('search', ['q' => 'x']))->assertRedirect(route('login'));
    });

    it('searches from the URL and live', function () {
        Node::factory()->for($this->user, 'owner')->create(['name' => 'Mortgage']);

        $this->get(route('search', ['q' => 'mort']))->assertOk()->assertSee('Mortgage');

        Livewire::test('pages::files.search')
            ->assertSee('Type part of a file or folder name')
            ->set('q', 'mort')
            ->assertSee('Mortgage')
            ->set('q', 'zzz')
            ->assertSee('No files or folders match')
            ->assertDontSee('Mortgage');
    });

    it('has a search link in the sidebar', function () {
        $this->get(route('files'))->assertSee('href="'.route('search').'"', false);
    });
});
