<?php

use App\Models\Node;
use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->folder = Node::factory()->for($this->owner, 'owner')->create(['name' => 'Holiday']);
    $this->photo = storedFile($this->owner, 'beach.txt', 'sand and sun', $this->folder);
    $this->sub = Node::factory()->inside($this->folder)->create(['name' => 'Day two']);
    $this->nested = storedFile($this->owner, 'hike.txt', 'up the hill', $this->sub);
    $this->outside = storedFile($this->owner, 'private.txt', 'not shared');
    $this->share = Share::factory()->create(['node_id' => $this->folder->id]);
    $this->token = $this->share->token;
});

function unlocked(Share $share): array
{
    return ['share_unlocks' => [$share->id => hash('sha256', (string) $share->password_hash)]];
}

describe('landing page', function () {
    it('shows a shared folder to guests', function () {
        $this->get(route('share.show', $this->token))
            ->assertOk()
            ->assertSee('Holiday')
            ->assertSee('beach.txt')
            ->assertSee('Day two')
            ->assertDontSee('private.txt')
            ->assertSee(route('share.zip', [$this->token, $this->folder->id]))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    it('opens subfolders and files inside the share', function () {
        $this->get(route('share.show', [$this->token, $this->sub->id]))->assertOk()->assertSee('hike.txt');
        $this->get(route('share.show', [$this->token, $this->nested->id]))->assertOk()->assertSee('up the hill');
    });

    it('shows a single shared file', function () {
        $share = Share::factory()->create(['node_id' => $this->outside->id]);

        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertSee('private.txt')
            ->assertSee('not shared')
            ->assertSee(route('share.download', [$share->token, $this->outside->id]));
    });

    it('does not reach nodes outside the shared one', function () {
        $this->get(route('share.show', [$this->token, $this->outside->id]))->assertNotFound();
        $this->get(route('share.show', [$this->token, $this->sub->id]))->assertOk();

        $deeper = Share::factory()->create(['node_id' => $this->sub->id]);
        // The parent folder is above this share.
        $this->get(route('share.show', [$deeper->token, $this->folder->id]))->assertNotFound();
    });

    it('is not available once revoked, expired, unknown or trashed', function () {
        $this->get(route('share.show', 'nonsense'))->assertNotFound();

        Share::factory()->revoked()->create(['node_id' => $this->folder->id, 'token' => 'revoked-token'])
            ->token;
        Share::factory()->expired()->create(['node_id' => $this->folder->id, 'token' => 'expired-token']);
        $this->get(route('share.show', 'revoked-token'))->assertNotFound();
        $this->get(route('share.show', 'expired-token'))->assertNotFound();

        $this->share->forceFill(['revoked_at' => now()])->save();
        $this->get(route('share.show', $this->token))->assertNotFound();
        $this->get(route('share.download', [$this->token, $this->photo->id]))->assertNotFound();
    });

    it('stops working when the shared node or an ancestor is trashed', function () {
        $this->folder->forceFill(['trashed_at' => now()])->save();

        $this->get(route('share.show', $this->token))->assertNotFound();

        $inner = Share::factory()->create(['node_id' => $this->sub->id]);
        $this->get(route('share.show', $inner->token))->assertNotFound();
    });

    it('hides trashed items inside the share', function () {
        $this->photo->forceFill(['trashed_at' => now()])->save();

        $this->get(route('share.show', $this->token))->assertDontSee('beach.txt');
        $this->get(route('share.download', [$this->token, $this->photo->id]))->assertNotFound();
        $this->get(route('share.show', [$this->token, $this->photo->id]))->assertNotFound();
    });
});

describe('files', function () {
    it('downloads files inside the share as attachments', function () {
        $response = $this->get(route('share.download', [$this->token, $this->nested->id]))->assertOk();

        expect($response->streamedContent())->toBe('up the hill')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('serves ranges and previews', function () {
        $this->get(route('share.download', [$this->token, $this->photo->id]), ['Range' => 'bytes=0-3'])
            ->assertStatus(206);

        $this->get(route('share.preview', [$this->token, $this->photo->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    });

    it('refuses files outside the share', function () {
        $this->get(route('share.download', [$this->token, $this->outside->id]))->assertNotFound();
        $this->get(route('share.preview', [$this->token, $this->outside->id]))->assertNotFound();
        $this->get(route('share.thumbnail', [$this->token, $this->outside->id]))->assertNotFound();
    });

    it('does not download a folder as a file', function () {
        $this->get(route('share.download', [$this->token, $this->sub->id]))->assertNotFound();
    });

    it('zips the shared folder and subfolders, but nothing outside', function () {
        $root = $this->get(route('share.zip', $this->token))->assertOk();
        $sub = $this->get(route('share.zip', [$this->token, $this->sub->id]))->assertOk();

        expect(zipEntries($root))->toBe(['Day two/' => null, 'Day two/hike.txt' => 'up the hill', 'beach.txt' => 'sand and sun'])
            ->and(zipEntries($sub))->toBe(['hike.txt' => 'up the hill']);

        $this->get(route('share.zip', [$this->token, Node::factory()->create()->id]))->assertNotFound();
    });

    it('serves thumbnails of shared images', function () {
        $image = imagecreatetruecolor(500, 500);
        ob_start();
        imagepng($image);
        $png = storedFile($this->owner, 'pic.png', ob_get_clean(), $this->folder, 'image/png');

        $this->get(route('share.thumbnail', [$this->token, $png->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
});

describe('view-only links', function () {
    beforeEach(function () {
        $this->share->forceFill(['allow_download' => false])->save();
    });

    it('blocks downloads and ZIPs and hides the buttons', function () {
        $this->get(route('share.download', [$this->token, $this->photo->id]))->assertForbidden();
        $this->get(route('share.zip', $this->token))->assertForbidden();
        $this->get(route('share.show', $this->token))->assertOk()->assertDontSee('Download');
    });

    it('still previews types the browser can show, and refuses the rest', function () {
        $exe = storedFile($this->owner, 'setup.exe', 'MZ', $this->folder, 'application/x-msdownload');

        $this->get(route('share.preview', [$this->token, $this->photo->id]))->assertOk();
        $this->get(route('share.preview', [$this->token, $exe->id]))->assertForbidden();
    });
});

describe('password', function () {
    beforeEach(function () {
        $this->share = Share::factory()->password('hunter2')->create(['node_id' => $this->folder->id]);
        $this->token = $this->share->token;
    });

    it('shows only the password form until unlocked', function () {
        $this->get(route('share.show', $this->token))
            ->assertOk()
            ->assertSee('This link is protected')
            ->assertDontSee('Holiday')
            ->assertDontSee('beach.txt');

        $this->get(route('share.download', [$this->token, $this->photo->id]))->assertForbidden();
        $this->get(route('share.preview', [$this->token, $this->photo->id]))->assertForbidden();
        $this->get(route('share.zip', $this->token))->assertForbidden();
    });

    it('unlocks with the right password', function () {
        Livewire::test('pages::share.show', ['token' => $this->token])
            ->set('password', 'wrong')
            ->call('unlock')
            ->assertHasErrors('password')
            ->assertDontSee('beach.txt')
            ->set('password', 'hunter2')
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertSee('beach.txt');

        expect(session('share_unlocks'))->toHaveKey($this->share->id);
    });

    it('serves files once unlocked', function () {
        $this->withSession(unlocked($this->share))
            ->get(route('share.download', [$this->token, $this->photo->id]))
            ->assertOk();
    });

    it('locks again when the password changes', function () {
        $session = unlocked($this->share);
        $this->share->forceFill(['password_hash' => Hash::make('new password')])->save();

        $this->withSession($session)->get(route('share.download', [$this->token, $this->photo->id]))->assertForbidden();
    });

    it('does not accept an unlock meant for another link', function () {
        $other = Share::factory()->password('hunter2')->create(['node_id' => $this->folder->id]);

        $this->withSession(unlocked($other))
            ->get(route('share.download', [$this->token, $this->photo->id]))
            ->assertForbidden();
    });

    it('limits password guesses, even the right one afterwards', function () {
        $component = Livewire::test('pages::share.show', ['token' => $this->token]);

        foreach (range(1, 5) as $_) {
            $component->set('password', 'wrong')->call('unlock')->assertHasErrors('password');
        }

        $component->set('password', 'hunter2')->call('unlock')->assertHasErrors('password')->assertDontSee('beach.txt');
        expect(session('share_unlocks'))->toBeNull();
    });
});
