<?php

use App\Models\DocumentArtifact;
use App\Models\User;
use App\Services\DocumentArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('reports effective expiry instead of configured lifetime policies', function () {
    $this->freezeTime();
    Storage::fake('local');
    URL::forceRootUrl('https://localhost');
    URL::forceScheme('https');
    config(['application-documents.link_ttl_minutes' => 60, 'application-documents.artifact_ttl_hours' => 0]);
    $user = User::factory()->create();
    $artifact = DocumentArtifact::query()->create([
        'user_id' => $user->id, 'type' => 'resume', 'language' => 'pt-BR', 'path' => 'documents/ttl.pdf',
        'sha256' => hash('sha256', 'pdf'), 'mime_type' => 'application/pdf', 'bytes' => 3,
        'expires_at' => now()->addMinutes(2),
    ]);
    Storage::disk('local')->put($artifact->path, 'pdf');
    $delivery = app(DocumentArtifactService::class)->deliver($user, $artifact, true);
    expect($delivery['expires_at'])->toBe($artifact->expires_at->toISOString())
        ->and($delivery['artifact_expires_at'])->toBe($artifact->expires_at->toISOString())
        ->and($delivery)->not->toHaveKeys(['link_ttl_minutes', 'artifact_ttl_hours']);
    parse_str(parse_url($delivery['download_url'], PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe($artifact->expires_at->timestamp);
});
