<?php

use App\Models\StorageDisk;
use App\Support\SftpStream;
use App\Support\StorageManager;
use phpseclib3\Net\SFTP;

/** A fake SFTP connection serving $data, counting how much was asked for. */
function fakeSftp(string $data): SFTP
{
    $sftp = Mockery::mock(SFTP::class);
    $sftp->requests = [];
    $sftp->shouldReceive('get')->andReturnUsing(function ($remote, $local, $offset, $length) use ($data, $sftp) {
        $sftp->requests[] = [$offset, $length];

        return substr($data, $offset, $length);
    });

    return $sftp;
}

describe('SFTP stream', function () {
    it('reads the whole file in order', function () {
        $data = random_bytes(20000);
        $stream = SftpStream::open(fakeSftp($data), '/x/blob', strlen($data));

        expect(stream_get_contents($stream))->toBe($data)
            ->and(feof($stream))->toBeTrue();
        fclose($stream);
    });

    it('starts at an offset without reading what comes before', function () {
        $data = random_bytes(50000);
        $sftp = fakeSftp($data);

        $stream = SftpStream::open($sftp, '/x/blob', strlen($data), 40000);

        expect(stream_get_contents($stream))->toBe(substr($data, 40000))
            ->and($sftp->requests)->toBe([[40000, 10000]]);
        fclose($stream);
    });

    it('seeks forwards and backwards, and from the end', function () {
        $data = random_bytes(30000);
        $stream = SftpStream::open(fakeSftp($data), '/x/blob', strlen($data));

        fseek($stream, 12345);
        expect(fread($stream, 100))->toBe(substr($data, 12345, 100))
            ->and(ftell($stream))->toBe(12445);

        fseek($stream, 10, SEEK_CUR);
        expect(fread($stream, 5))->toBe(substr($data, 12455, 5));

        fseek($stream, 0);
        expect(fread($stream, 4))->toBe(substr($data, 0, 4));

        fseek($stream, -50, SEEK_END);
        expect(stream_get_contents($stream))->toBe(substr($data, -50));
        fclose($stream);
    });

    it('fetches in blocks, not per read', function () {
        $data = str_repeat('a', 100000);
        $sftp = fakeSftp($data);
        $stream = SftpStream::open($sftp, '/x/blob', strlen($data));

        for ($i = 0; $i < 10; $i++) {
            fread($stream, 8192);
        }

        expect($sftp->requests)->toHaveCount(1);
        fclose($stream);
    });

    it('handles an empty file', function () {
        $stream = SftpStream::open(fakeSftp(''), '/x/blob', 0);

        expect(stream_get_contents($stream))->toBe('')->and(feof($stream))->toBeTrue();
        fclose($stream);
    });
});

describe('StorageManager::openStream', function () {
    it('opens a local blob at an offset', function () {
        $disk = StorageDisk::factory()->default()->create(['config' => ['root' => config('ferrite.local_root')]]);
        $storage = app(StorageManager::class);
        $storage->filesystem($disk)->put('ab/cdef', '0123456789');

        $stream = $storage->openStream($disk, 'ab/cdef', 10, 4);

        expect(stream_get_contents($stream))->toBe('456789');
        fclose($stream);
    });

    it('asks S3 for the byte range instead of reading up to it', function () {
        $disk = new StorageDisk(['name' => 's3', 'driver' => 's3', 'config' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1']]);

        $storage = Mockery::mock(StorageManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('s3Range')->once()->with($disk, 'ab/cdef', 500)->andReturn(fopen('php://memory', 'rb'));

        fclose($storage->openStream($disk, 'ab/cdef', 1000, 500));
    });
});
