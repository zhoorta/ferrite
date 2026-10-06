<?php

use App\Actions\Storage\DeleteStorageDisk;
use App\Actions\Storage\SaveStorageDisk;
use App\Actions\Storage\SetDefaultStorageDisk;
use App\Actions\Storage\TestStorageDisk;
use App\Models\Node;
use App\Models\StorageDisk;
use App\Models\User;
use App\Support\StorageManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->extraRoot = $this->storageBase.'/extra';
});

function s3Data(array $override = []): array
{
    return [
        'name' => 'archive',
        'driver' => 's3',
        'config' => [...['bucket' => 'b', 'region' => 'eu-west-1', 'key' => 'AKIA', 'secret' => 's3cr3t'], ...$override],
    ];
}

describe('access', function () {
    it('is for admins only', function () {
        $this->get(route('admin.storage'))->assertOk()->assertSee('Local folder');

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.storage'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.storage'))->assertRedirect(route('login'));
    });

    it('refuses every action to non-admins', function () {
        $user = User::factory()->create();
        $disk = app(StorageManager::class)->default();

        expect(fn () => app(SaveStorageDisk::class)->handle($user, null, s3Data()))->toThrow(AuthorizationException::class)
            ->and(fn () => app(TestStorageDisk::class)->handle($user, $disk))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetDefaultStorageDisk::class)->handle($user, $disk))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteStorageDisk::class)->handle($user, $disk))->toThrow(AuthorizationException::class);
    });

    it('shows the Storage link only to admins', function () {
        $this->get(route('files'))->assertSee(route('admin.storage'));

        $this->actingAs(User::factory()->create());
        $this->get(route('files'))->assertDontSee(route('admin.storage'));
    });
});

describe('saving', function () {
    it('creates an S3 disk, casting and dropping empty settings', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, s3Data(['endpoint' => '', 'use_path_style_endpoint' => true, 'root' => '']));

        expect($disk->driver)->toBe('s3')
            ->and($disk->config)->toBe(['bucket' => 'b', 'region' => 'eu-west-1', 'key' => 'AKIA', 'secret' => 's3cr3t', 'use_path_style_endpoint' => true])
            ->and($disk->is_default)->toBeTrue(); // the first disk
    });

    it('stores settings encrypted and never exposes them in arrays', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, s3Data());

        expect(DB::table('storage_disks')->where('id', $disk->id)->value('config'))->not->toContain('s3cr3t')
            ->and($disk->toArray())->not->toHaveKey('config');
    });

    it('creates an SFTP disk with a key and a numeric port', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, [
            'name' => 'nas', 'driver' => 'sftp',
            'config' => ['host' => 'nas.local', 'port' => '2222', 'username' => 'shed', 'privateKey' => 'PEM', 'root' => '/srv/shed'],
        ]);

        expect($disk->config['port'])->toBe(2222)->and($disk->config['privateKey'])->toBe('PEM');
    });

    it('requires a password or key for SFTP', function () {
        app(SaveStorageDisk::class)->handle($this->admin, null, [
            'name' => 'nas', 'driver' => 'sftp',
            'config' => ['host' => 'nas.local', 'username' => 'shed', 'root' => '/srv/shed'],
        ]);
    })->throws(ValidationException::class);

    it('validates required settings, paths, names and drivers', function (string $case) {
        $data = match ($case) {
            'missing bucket' => s3Data(['bucket' => '']),
            'relative local path' => ['name' => 'x', 'driver' => 'local', 'config' => ['root' => 'storage/files']],
            'bad endpoint' => s3Data(['endpoint' => 'ftp://nope']),
            'no name' => ['name' => ''] + s3Data(),
            'unknown driver' => ['name' => 'x', 'driver' => 'dropbox', 'config' => []],
        };

        expect(fn () => app(SaveStorageDisk::class)->handle($this->admin, null, $data))->toThrow(ValidationException::class);
    })->with(['missing bucket', 'relative local path', 'bad endpoint', 'no name', 'unknown driver']);

    it('rejects a duplicate name', function () {
        app(SaveStorageDisk::class)->handle($this->admin, null, s3Data());
        app(SaveStorageDisk::class)->handle($this->admin, null, s3Data());
    })->throws(ValidationException::class);

    it('keeps blank secrets when editing and cannot change the driver', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, s3Data());

        app(SaveStorageDisk::class)->handle($this->admin, $disk, ['name' => 'renamed', 'driver' => 'local'] + ['config' => ['bucket' => 'b2', 'region' => 'eu-west-1', 'key' => 'AKIA', 'secret' => '']]);

        $disk->refresh();
        expect($disk->name)->toBe('renamed')
            ->and($disk->driver)->toBe('s3')
            ->and($disk->config['bucket'])->toBe('b2')
            ->and($disk->config['secret'])->toBe('s3cr3t');

        app(SaveStorageDisk::class)->handle($this->admin, $disk, ['name' => 'renamed', 'config' => ['bucket' => 'b2', 'region' => 'eu-west-1', 'key' => 'AKIA', 'secret' => 'new']]);
        expect($disk->fresh()->config['secret'])->toBe('new');
    });
});

describe('manager', function () {
    it('builds the right adapters without connecting', function () {
        $s3 = app(SaveStorageDisk::class)->handle($this->admin, null, s3Data(['endpoint' => 'http://127.0.0.1:9']));
        $sftp = app(SaveStorageDisk::class)->handle($this->admin, null, [
            'name' => 'nas', 'driver' => 'sftp',
            'config' => ['host' => 'nas.local', 'username' => 'shed', 'password' => 'pw', 'root' => '/srv/shed'],
        ]);

        $manager = app(StorageManager::class);

        expect($manager->filesystem($s3)->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class)
            ->and($manager->filesystem($sftp)->getAdapter())->toBeInstanceOf(SftpAdapter::class);
    });
});

describe('testing a disk', function () {
    it('passes for a writable folder and leaves no probe behind', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, ['name' => 'extra', 'driver' => 'local', 'config' => ['root' => $this->extraRoot]]);

        expect(app(TestStorageDisk::class)->handle($this->admin, $disk))->toBeNull()
            ->and(File::files($this->extraRoot))->toBeEmpty();
    });

    it('reports a folder that cannot be written', function () {
        $disk = new StorageDisk(['name' => 'bad', 'driver' => 'local', 'config' => ['root' => '/proc/shed-nope']]);

        expect(app(TestStorageDisk::class)->handle($this->admin, $disk))->toBeString()->not->toBeEmpty();
    });

    it('reports unreachable S3 and SFTP servers', function () {
        $s3 = new StorageDisk(['name' => 's3', 'driver' => 's3', 'config' => s3Data(['endpoint' => 'http://127.0.0.1:1', 'use_path_style_endpoint' => true])['config']]);
        $sftp = new StorageDisk(['name' => 'sftp', 'driver' => 'sftp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p', 'root' => '/x']]);

        expect(app(TestStorageDisk::class)->handle($this->admin, $s3))->toBeString()->not->toBeEmpty()
            ->and(app(TestStorageDisk::class)->handle($this->admin, $sftp))->toBeString()->not->toBeEmpty();
    });
});

describe('default and removal', function () {
    beforeEach(function () {
        $this->first = app(StorageManager::class)->default();
        $this->second = app(SaveStorageDisk::class)->handle($this->admin, null, ['name' => 'extra', 'driver' => 'local', 'config' => ['root' => $this->extraRoot]]);
    });

    it('keeps exactly one default', function () {
        expect($this->second->is_default)->toBeFalse();

        app(SetDefaultStorageDisk::class)->handle($this->admin, $this->second);

        expect(StorageDisk::where('is_default', true)->pluck('id')->all())->toBe([$this->second->id])
            ->and(app(StorageManager::class)->default()->id)->toBe($this->second->id);
    });

    it('stores new uploads on the new default and still serves old files', function () {
        $old = storedFile($this->admin, 'old.txt', 'old content');

        app(SetDefaultStorageDisk::class)->handle($this->admin, $this->second);

        $id = test()->postJson(route('uploads.store'), ['path' => 'new.txt', 'size' => 3])->json('id');
        test()->call('PATCH', route('uploads.update', $id), [], [], [], ['HTTP_UPLOAD_OFFSET' => 0, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], 'new')->assertOk();

        $new = Node::firstWhere('name', 'new.txt');

        expect($new->disk_id)->toBe($this->second->id)
            ->and(File::exists("{$this->extraRoot}/{$new->path}"))->toBeTrue()
            ->and(test()->get(route('nodes.download', $new))->streamedContent())->toBe('new')
            ->and(test()->get(route('nodes.download', $old))->streamedContent())->toBe('old content');
    });

    it('refuses to remove the default or a disk with files, and removes an empty one', function () {
        expect(fn () => app(DeleteStorageDisk::class)->handle($this->admin, $this->first))->toThrow(ValidationException::class);

        $node = Node::factory()->file()->create(['disk_id' => $this->second->id]);
        expect(fn () => app(DeleteStorageDisk::class)->handle($this->admin, $this->second))->toThrow(ValidationException::class);

        $node->delete();
        app(DeleteStorageDisk::class)->handle($this->admin, $this->second);

        expect(StorageDisk::count())->toBe(1);
    });
});

describe('page', function () {
    it('lists disks with usage, tests them and adds new ones', function () {
        storedFile($this->admin, 'a.txt', '12345');

        $component = Livewire::test('pages::admin.disks')
            ->assertSee('local')
            ->assertSee('1 file')
            ->assertSee('Default');

        $id = StorageDisk::first()->id;
        $component->call('test', $id)->assertSee('Connection works.');

        $component->call('add')
            ->set('name', 'Offsite')
            ->set('driver', 's3')
            ->set('config', ['bucket' => 'b', 'region' => 'eu', 'key' => 'k', 'secret' => 's'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Offsite');

        expect(StorageDisk::count())->toBe(2);
    });

    it('shows validation errors per field', function () {
        Livewire::test('pages::admin.disks')
            ->call('add')
            ->set('name', 'x')
            ->set('driver', 's3')
            ->call('save')
            ->assertHasErrors(['config.bucket', 'config.secret']);
    });

    it('never sends secrets to the browser when editing', function () {
        $disk = app(SaveStorageDisk::class)->handle($this->admin, null, s3Data());

        Livewire::test('pages::admin.disks')
            ->call('edit', $disk->id)
            ->assertSet('config.bucket', 'b')
            ->assertSet('config.secret', null)
            ->assertDontSee('s3cr3t')
            ->set('name', 'archive 2')
            ->call('save')
            ->assertHasNoErrors();

        expect($disk->fresh()->config['secret'])->toBe('s3cr3t');
    });

    it('is closed to non-admins', function () {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::admin.disks')->assertForbidden();
    });
});
