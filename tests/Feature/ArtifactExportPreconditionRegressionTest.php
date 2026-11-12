<?php

use App\Mcp\Tools\Ats\ExportAdaptedResumePdfTool;
use App\Models\DocumentArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

it('returns a precondition for unavailable stdio artifacts', function (string $state) {
    Storage::fake('local');
    $user = User::factory()->create();
    config(['jobora.mcp.user' => (string) $user->id]);
    $artifact = DocumentArtifact::query()->create([
        'user_id' => $user->id, 'type' => 'resume', 'language' => 'pt-BR', 'path' => 'documents/test.pdf',
        'sha256' => hash('sha256', 'pdf'), 'mime_type' => 'application/pdf', 'bytes' => 3,
        'expires_at' => $state === 'expired' ? now()->subMinute() : now()->addHour(),
        'revoked_at' => $state === 'revoked' ? now() : null,
    ]);
    if ($state !== 'missing') {
        Storage::disk('local')->put($artifact->path, 'pdf');
    }
    $response = app(ExportAdaptedResumePdfTool::class)->handle(new Request(['artifact_id' => $artifact->id]));
    expect(json_decode((string) $response->content(), true)['error']['code'])->toBe('precondition');
})->with(['expired', 'revoked', 'missing']);
