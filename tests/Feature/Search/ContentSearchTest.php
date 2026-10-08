<?php

use App\Jobs\ExtractContent;
use App\Models\Node;
use App\Models\NodeContent;
use App\Models\User;
use App\Support\ContentExtractor;
use App\Support\NodeSearch;
use App\Support\Search\ContentSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;

// Runs on whichever database the suite uses: SQLite by default, MySQL or MariaDB when
// DB_CONNECTION and DB_DATABASE point at an empty test database (see docs/content-search.md).

beforeEach(function () {
    $base = storage_path('framework/testing/ferrite-'.bin2hex(random_bytes(4)));
    config(['ferrite.tmp_path' => "{$base}/tmp", 'ferrite.local_root' => "{$base}/blobs"]);
    $this->base = $base;

    // An InnoDB FULLTEXT index only sees committed rows, so on MySQL and MariaDB these tests commit
    // the transaction the suite wraps them in and empty the tables afterwards instead.
    $this->commits = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

    if ($this->commits) {
        DB::commit();
    }

    $this->user = User::factory()->create();
});

afterEach(function () {
    File::deleteDirectory($this->base);

    if ($this->commits) {
        Schema::disableForeignKeyConstraints();

        foreach (Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }
});

/** Store a text file for $owner and index it, as an upload would. */
function indexed(User $owner, string $name, string $content, ?Node $parent = null, ?string $mime = 'text/plain'): Node
{
    $node = storedFile($owner, $name, $content, $parent, $mime);
    ExtractContent::queueFor($node);

    return $node;
}

/** Like indexed(), but reads the file right now, as a queue worker would (PDFs are not read inline under the sync queue). */
function indexedByWorker(User $owner, string $name, string $content, ?string $mime): Node
{
    $node = storedFile($owner, $name, $content, null, $mime);
    ExtractContent::dispatchSync($node->id);

    return $node;
}

function found(User $user, string $term): array
{
    return app(NodeSearch::class)->contents($user, $term)->map(fn (array $r) => $r['node']->name)->all();
}

function minimalPdf(string $text): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 100]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        ."4 0 obj<</Length 60>>stream\nBT /F1 18 Tf 10 50 Td ({$text}) Tj ET\nendstream endobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R/Size 6>>\n%%EOF\n";
}

describe('extraction', function () {
    it('indexes a text file when it is stored', function () {
        $node = indexed($this->user, 'notes.txt', "Meeting   about the\nrenovation budget");

        $content = NodeContent::sole();

        expect($content->sha256)->toBe($node->sha256)
            ->and($content->status)->toBe('indexed')
            ->and($content->text)->toBe('Meeting about the renovation budget');
    });

    it('reads Markdown and code whose type is generic, by extension', function () {
        indexed($this->user, 'readme.md', '# Zeppelin plans', null, 'application/octet-stream');
        indexed($this->user, 'data.xyz', 'Zeppelin binary-ish name', null, 'application/octet-stream');

        expect(NodeContent::query()->where('status', 'indexed')->count())->toBe(1)
            ->and(NodeContent::query()->where('status', 'skipped')->count())->toBe(1);
    });

    it('skips binary files, other encodings and unsupported types', function () {
        indexed($this->user, 'a.txt', "text\0with a nul byte");
        indexed($this->user, 'b.txt', "caf\xE9 in latin-1 and more text");
        indexed($this->user, 'c.png', 'not really an image', null, 'image/png');
        indexed($this->user, 'd.txt', "   \n  ");

        expect(NodeContent::query()->pluck('status')->unique()->all())->toBe(['skipped'])
            ->and(NodeContent::count())->toBe(4)
            ->and(NodeContent::query()->pluck('reason')->all())->toContain('binary', 'not UTF-8 text', 'not a text file or PDF', 'no text');
    });

    it('indexes only the start of a long file and forgives a cut in a character', function () {
        config(['ferrite.search_max_text_kb' => 1]);

        // 1023 bytes, then a two-byte character that the 1 KiB limit cuts in half.
        indexed($this->user, 'long.txt', 'alpha '.str_repeat('b', 1017).'é omega');

        $text = NodeContent::sole()->text;

        expect($text)->toContain('alpha')->not->toContain('omega')->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
    });

    it('reads the text of a PDF', function () {
        if ((new ExecutableFinder)->find('pdftotext') === null) {
            $this->markTestSkipped('pdftotext is not installed.');
        }

        indexedByWorker($this->user, 'invoice.pdf', minimalPdf('Quarterly invoice zebra'), 'application/pdf');

        expect(NodeContent::sole()->text)->toContain('Quarterly invoice zebra');
    });

    it('records a failure when pdftotext is missing, and retries it', function () {
        config(['ferrite.pdftotext' => 'no-such-pdftotext']);

        $node = indexedByWorker($this->user, 'invoice.pdf', minimalPdf('hello'), 'application/pdf');

        expect(NodeContent::sole()->status)->toBe('failed');

        $this->artisan('search:index', ['--retry' => true])->assertSuccessful();

        expect(NodeContent::sole()->status)->toBe('failed')->and(NodeContent::sole()->sha256)->toBe($node->sha256);
    });

    it('skips a PDF that is too big', function () {
        config(['ferrite.search_max_pdf_mb' => 1]);

        indexedByWorker($this->user, 'big.pdf', minimalPdf('x').str_repeat('%', 1_100_000), 'application/pdf');

        expect(NodeContent::sole()->reason)->toBe('PDF too big');
    });

    it('leaves PDFs to the scheduled index when there is no queue worker', function () {
        if ((new ExecutableFinder)->find('pdftotext') === null) {
            $this->markTestSkipped('pdftotext is not installed.');
        }

        expect(config('queue.default'))->toBe('sync');

        indexed($this->user, 'invoice.pdf', minimalPdf('Quarterly invoice zebra'), null, 'application/pdf');
        indexed($this->user, 'notes.txt', 'plain text is read at once');

        expect(NodeContent::query()->pluck('status', 'sha256')->count())->toBe(1);

        $this->artisan('search:index')->expectsOutputToContain('Queued 1 files')->assertSuccessful();

        expect(found($this->user, 'zebra'))->toBe(['invoice.pdf']);
    });

    it('reads identical files once', function () {
        indexed($this->user, 'one.txt', 'same words here');
        indexed($this->user, 'two.txt', 'same words here');

        expect(NodeContent::count())->toBe(1)->and(found($this->user, 'words'))->toHaveCount(2);
    });

    it('does nothing when content search is off', function () {
        config(['ferrite.search_contents' => false]);

        indexed($this->user, 'notes.txt', 'hello world');

        expect(NodeContent::count())->toBe(0)->and(found($this->user, 'hello'))->toBe([]);
    });
});

describe('searching', function () {
    it('finds words inside files with a snippet around them', function () {
        indexed($this->user, 'notes.txt', str_repeat('lorem ipsum ', 30).'the renovation budget is approved '.str_repeat('dolor sit ', 30));

        $result = app(NodeSearch::class)->contents($this->user, 'renovation')->sole();

        expect($result['node']->name)->toBe('notes.txt')
            ->and($result['snippet'])->toContain("\x01renovation\x02")
            ->and($result['path'])->toBe('My files');
    });

    it('requires every word and matches word starts', function () {
        indexed($this->user, 'a.txt', 'the quarterly renovation budget');
        indexed($this->user, 'b.txt', 'only the budget');

        expect(found($this->user, 'renov budget'))->toBe(['a.txt'])
            ->and(found($this->user, 'budget'))->toHaveCount(2)
            ->and(found($this->user, 'renovation garden'))->toBe([]);
    });

    it('ignores accents and case', function () {
        indexed($this->user, 'a.txt', 'Reunião de Coração no Café');

        expect(found($this->user, 'reuniao'))->toBe(['a.txt'])
            ->and(found($this->user, 'CAFE'))->toBe(['a.txt']);
    });

    it('treats search syntax as plain words', function () {
        indexed($this->user, 'a.txt', 'plain words only');

        foreach (['"plain', 'plain OR (', 'plain* -words', "plain' --", 'NEAR(plain words)', 'col:plain', '*', '"', 'a b'] as $term) {
            expect(fn () => found($this->user, $term))->not->toThrow(Throwable::class);
        }

        expect(found($this->user, '"plain" words'))->toBe(['a.txt']);
    });

    it('escapes the snippet when it is shown', function () {
        indexed($this->user, 'a.txt', 'before <script>alert(1)</script> findme after');

        $snippet = app(NodeSearch::class)->contents($this->user, 'findme')->sole()['snippet'];

        expect(ContentSearch::html($snippet))->toContain('<mark>findme</mark>')->not->toContain('<script>')->toContain('&lt;script&gt;');
    });

    it('follows changes to the extracted text', function () {
        $node = indexed($this->user, 'a.txt', 'first version mentions oranges');

        NodeContent::sole()->update(['text' => 'second version mentions lemons']);

        expect(found($this->user, 'oranges'))->toBe([])->and(found($this->user, 'lemons'))->toBe(['a.txt']);

        NodeContent::sole()->delete();

        expect(found($this->user, 'lemons'))->toBe([])->and($node->fresh())->not->toBeNull();
    });
});

describe('who sees what', function () {
    it('never returns other people\'s files', function () {
        $other = User::factory()->create();
        indexed($other, 'private.txt', 'secret zeppelin plans');
        indexed($this->user, 'mine.txt', 'my zeppelin notes');

        expect(found($this->user, 'zeppelin'))->toBe(['mine.txt'])
            ->and(found($other, 'zeppelin'))->toBe(['private.txt']);
    });

    it('returns a file shared directly, or inside a shared folder, with a path starting there', function () {
        $other = User::factory()->create();
        $folder = Node::factory()->for($other, 'owner')->create(['name' => 'Team']);
        $sub = Node::factory()->inside($folder)->create(['name' => 'Plans']);
        indexed($other, 'deep.txt', 'zeppelin in a subfolder', $sub);
        indexed($other, 'direct.txt', 'zeppelin shared on its own');
        indexed($other, 'hidden.txt', 'zeppelin not shared');

        $folder->sharedWith()->attach($this->user, ['permission' => 'view']);
        Node::query()->where('name', 'direct.txt')->first()->sharedWith()->attach($this->user, ['permission' => 'view']);

        $results = app(NodeSearch::class)->contents($this->user, 'zeppelin');

        expect($results->map(fn ($r) => $r['node']->name)->sort()->values()->all())->toBe(['deep.txt', 'direct.txt'])
            ->and($results->firstWhere(fn ($r) => $r['node']->name === 'deep.txt')['path'])->toBe('Shared with me / Team / Plans');
    });

    it('leaves out trashed files and files in trashed folders', function () {
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Old']);
        indexed($this->user, 'inside.txt', 'zeppelin in a folder', $folder);
        $gone = indexed($this->user, 'gone.txt', 'zeppelin trashed');
        indexed($this->user, 'kept.txt', 'zeppelin kept');

        $gone->forceFill(['trashed_at' => now()])->save();
        $folder->forceFill(['trashed_at' => now()])->save();

        expect(found($this->user, 'zeppelin'))->toBe(['kept.txt']);
    });

    it('does not hand out text through a node the user cannot see, even for identical content', function () {
        $other = User::factory()->create();
        indexed($other, 'theirs.txt', 'identical text about zeppelins');
        indexed($this->user, 'mine.txt', 'identical text about zeppelins');

        expect(found($this->user, 'zeppelins'))->toBe(['mine.txt']);
    });
});

describe('housekeeping', function () {
    it('queues files that were never indexed, once per content', function () {
        config(['ferrite.search_contents' => false]);
        storedFile($this->user, 'a.txt', 'alpha beta');
        storedFile($this->user, 'b.txt', 'alpha beta');
        storedFile($this->user, 'c.txt', 'gamma delta');
        config(['ferrite.search_contents' => true]);

        $this->artisan('search:index')->expectsOutputToContain('Queued 2 files')->assertSuccessful();

        expect(NodeContent::count())->toBe(2)->and(found($this->user, 'alpha'))->toHaveCount(2);

        $this->artisan('search:index')->expectsOutputToContain('Queued 0 files')->assertSuccessful();
    });

    it('refuses to run when content search is off', function () {
        config(['ferrite.search_contents' => false]);

        $this->artisan('search:index')->assertFailed();
    });

    it('prunes text no file uses any more', function () {
        $node = indexed($this->user, 'a.txt', 'alpha beta');
        indexed($this->user, 'b.txt', 'gamma delta');

        $node->delete();
        $this->artisan('search:prune')->assertSuccessful();

        expect(NodeContent::pluck('text')->all())->toBe(['gamma delta']);
    });
});

describe('the search page', function () {
    it('lists matches inside files after the name matches, without repeating a file', function () {
        indexed($this->user, 'budget.txt', 'the budget lives here');
        indexed($this->user, 'notes.txt', 'remember the budget');

        $this->actingAs($this->user);

        Livewire::test('pages::files.search')
            ->set('q', 'budget')
            ->assertSee('Found inside files')
            ->assertSeeHtml('<mark>budget</mark>')
            ->assertSeeHtmlInOrder(['data-test="search-row"', 'data-test="content-row"'])
            ->assertSee('notes.txt')
            ->assertSeeHtml('placeholder="Search names and contents"');

        $component = Livewire::test('pages::files.search')->set('q', 'budget');

        expect($component->instance()->contentResults->map(fn ($r) => $r['node']->name)->all())->toBe(['notes.txt']);
    });

    it('previews a result in the dialog and steps through the results', function () {
        $named = indexed($this->user, 'budget.txt', 'the budget lives here');
        $inside = indexed($this->user, 'notes.txt', 'remember the budget');

        $this->actingAs($this->user);

        $component = Livewire::test('pages::files.search')->set('q', 'budget')
            ->call('preview', $inside->id)
            ->assertSet('previewId', $inside->id)
            ->assertSee('remember the budget')
            ->assertSee('2 / 2');

        $component->call('previewStep', -1)->assertSet('previewId', $named->id)->assertSee('the budget lives here');
        $component->call('previewStep', -1)->assertSet('previewId', $named->id);
        $component->call('closePreview')->assertSet('previewId', null);
    });

    it('refuses to preview what the user may not see, folders and trashed files', function () {
        $other = User::factory()->create();
        $theirs = storedFile($other, 'theirs.txt', 'private');
        $folder = Node::factory()->for($this->user, 'owner')->create(['name' => 'Docs']);
        $gone = storedFile($this->user, 'gone.txt', 'bin');
        $gone->forceFill(['trashed_at' => now()])->save();

        $this->actingAs($this->user);
        Livewire::test('pages::files.search')->call('preview', $theirs->id)->assertForbidden();
        Livewire::test('pages::files.search')->call('preview', $folder->id)->assertNotFound();
        Livewire::test('pages::files.search')->call('preview', $gone->id)->assertNotFound();
    });

    it('says nothing matches when neither names nor contents do', function () {
        $this->actingAs($this->user);

        Livewire::test('pages::files.search')->set('q', 'nothing-here')->assertSee('No files or folders match');
    });
});

it('keeps the extractor free of the request (unit check of cleaning)', function () {
    $node = storedFile($this->user, 'bom.txt', "\xEF\xBB\xBFhello\x01 world");

    $content = app(ContentExtractor::class)->extract($node);

    expect($content->text)->toBe('hello world');
});
