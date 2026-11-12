<?php

use App\Models\DocumentArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->artifact = DocumentArtifact::query()->create([
        'user_id' => $this->owner->id, 'type' => 'resume', 'language' => 'pt-BR',
        'path' => 'documents/'.$this->owner->id.'/session.pdf', 'sha256' => hash('sha256', 'pdf'),
        'mime_type' => 'application/pdf', 'bytes' => 3, 'expires_at' => now()->addHour(),
    ]);
    Storage::disk('local')->put($this->artifact->path, 'pdf');
    $this->url = URL::temporarySignedRoute('documents.session.download', now()->addMinutes(15), ['artifact' => $this->artifact->id]);
});

it('redirects guests with download links to the panel login', function () {
    $this->get($this->url)->assertRedirect(route('filament.admin.auth.login'));
});

it('requires panel email verification before downloading', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->owner)->get($this->url)
        ->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
});

it('allows verified owners but not other users or unsigned requests', function () {
    $this->actingAs($this->owner)->get($this->url)->assertOk()->assertDownload('resume.pdf');
    $this->get(route('documents.session.download', ['artifact' => $this->artifact->id]))->assertForbidden();
    $this->actingAs(User::factory()->create())->get($this->url)->assertNotFound();
});
