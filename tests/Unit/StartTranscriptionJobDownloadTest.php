<?php

use App\Jobs\StartTranscriptionJob;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

it('downloads using a temporary url for s3 disks', function () {
    Http::fake([
        '*' => Http::response('payload', 200),
    ]);

    $disk = \Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->andReturn('http://example.com/file');

    $job = new class(1) extends StartTranscriptionJob
    {
        public function downloadTemp(FilesystemAdapter $disk, string $path, string $localPath): void
        {
            $this->downloadViaTemporaryUrl($disk, $path, $localPath);
        }
    };

    $localPath = storage_path('app/testing/temp-download.txt');
    File::ensureDirectoryExists(dirname($localPath));
    File::delete($localPath);

    $job->downloadTemp($disk, 'transcriptions/example.mp4', $localPath);

    expect(File::exists($localPath))->toBeTrue()
        ->and(File::get($localPath))->toBe('payload');
});
