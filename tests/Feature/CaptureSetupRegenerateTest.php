<?php

use App\Filament\Pages\CaptureSetupPage;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('regenerar pela página expõe o token integral e a máscara na mesma sessão', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(CaptureTokenService::class)->issueToken($user);

    $component = Livewire::test(CaptureSetupPage::class)
        ->callAction('regenerateToken')
        ->assertSuccessful();

    $plain = $component->instance()->fullToken();

    expect($component->instance()->hasPlainToken())->toBeTrue()
        ->and($plain)->toMatch('/^[0-9a-f]{64}$/')
        ->and($component->html())->toContain($plain)
        ->and($component->html())->toContain(substr($plain, -4));
});

it('bloco de credencial usa chave por token para reinicializar o Alpine', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(CaptureTokenService::class)->issueToken($user);

    $component = Livewire::test(CaptureSetupPage::class)
        ->callAction('regenerateToken')
        ->assertSuccessful();

    preg_match('/wire:key="([^"]*credential[^"]*)"/', $component->html(), $first);

    expect($first[1] ?? null)->not->toBeNull();

    $component->callAction('regenerateToken')->assertSuccessful();

    preg_match('/wire:key="([^"]*credential[^"]*)"/', $component->html(), $second);

    expect($second[1] ?? null)->not->toBeNull()
        ->and($second[1])->not->toBe($first[1]);
});
