<?php

use App\Models\PageCapture;
use App\Services\Discovery\CaptureRetentionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function retentionFailedCapture(): PageCapture
{
    return PageCapture::factory()->create([
        'html_path' => 'capture-envelopes/1/expirada.html',
        'raw_expires_at' => now()->subDay(),
        'raw_deleted_at' => null,
    ]);
}

it('preserva metadados quando o disco retorna falha ao apagar o bruto', function () {
    $capture = retentionFailedCapture();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->with('capture-envelopes/1/expirada.html')->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    Log::spy();

    $pruned = app(CaptureRetentionService::class)->pruneRaw();

    expect($pruned)->toBe(0)
        ->and($capture->refresh()->html_path)->toBe('capture-envelopes/1/expirada.html')
        ->and($capture->raw_deleted_at)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['page_capture_id'] === $capture->id
            && $context['code'] === 'delete_failed',
    )->once();
});

it('preserva metadados quando o disco lança exceção ao apagar o bruto', function () {
    $capture = retentionFailedCapture();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('disco indisponível'));
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    Log::spy();

    $pruned = app(CaptureRetentionService::class)->pruneRaw();

    expect($pruned)->toBe(0)
        ->and($capture->refresh()->html_path)->toBe('capture-envelopes/1/expirada.html')
        ->and($capture->raw_deleted_at)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['page_capture_id'] === $capture->id
            && $context['code'] === 'delete_exception'
            && $context['exception'] === RuntimeException::class,
    )->once();
});

it('reporta no comando apenas as capturas efetivamente podadas', function () {
    retentionFailedCapture();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->artisan('captures:prune-raw')
        ->expectsOutput('Capturas expiradas: 0')
        ->assertSuccessful();
});

it('limpa metadados expirados quando o objeto já está ausente', function () {
    Storage::fake('local');

    $capture = retentionFailedCapture();

    $pruned = app(CaptureRetentionService::class)->pruneRaw();

    expect($pruned)->toBe(1)
        ->and($capture->refresh()->html_path)->toBeNull()
        ->and($capture->raw_deleted_at)->not->toBeNull();
});

it('does not count an orphan object whose delete fails', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->once()->andReturn(['capture-envelopes/orphan.html']);
    $disk->shouldReceive('lastModified')->once()->andReturn(now()->subDays(2)->getTimestamp());
    $disk->shouldReceive('delete')->once()->with('capture-envelopes/orphan.html')->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    Log::spy();

    $result = app(CaptureRetentionService::class)->reconcileOrphans();

    expect($result['objects'])->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['file'] === 'capture-envelopes/orphan.html'
            && $context['code'] === 'delete_failed',
    )->once();
});

it('keeps reconciling when the disk throws for an orphan object', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->once()->andReturn(['capture-envelopes/orphan.html']);
    $disk->shouldReceive('lastModified')->once()->andReturn(now()->subDays(2)->getTimestamp());
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('disco indisponível'));
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    Log::spy();

    $result = app(CaptureRetentionService::class)->reconcileOrphans();

    expect($result['objects'])->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['code'] === 'delete_exception'
            && $context['exception'] === RuntimeException::class,
    )->once();
});
