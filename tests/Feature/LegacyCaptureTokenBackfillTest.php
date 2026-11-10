<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->legacyEnvironment = [];

    foreach (['VAGAS_API_TOKEN', 'VAGAS_API_USER_ID'] as $key) {
        $this->legacyEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key.'=');
        $_ENV[$key] = '';
        $_SERVER[$key] = '';
    }
});

afterEach(function (): void {
    foreach ($this->legacyEnvironment as $key => [$process, $env, $server]) {
        putenv($process === false ? $key : $key.'='.$process);

        unset($_ENV[$key], $_SERVER[$key]);

        if ($env !== null) {
            $_ENV[$key] = $env;
        }

        if ($server !== null) {
            $_SERVER[$key] = $server;
        }
    }
});

function legacyBackfillMigration(): object
{
    return require database_path('migrations/2026_08_26_160001_backfill_legacy_capture_token.php');
}

it('grava o hash do token legado no owner configurado', function () {
    $user = User::factory()->create();
    config([
        'capture.api_token' => 'token-legado',
        'capture.owner_user_id' => $user->id,
    ]);

    legacyBackfillMigration()->up();

    expect($user->refresh()->capture_token_hash)->toBe(hash('sha256', 'token-legado'))
        ->and($user->capture_token_last_four)->toBe('gado');
});

it('aborta quando o owner configurado não existe', function () {
    config([
        'capture.api_token' => 'token-legado',
        'capture.owner_user_id' => 999999,
    ]);

    expect(fn () => legacyBackfillMigration()->up())
        ->toThrow(RuntimeException::class, 'Backfill do token legado abortado');
});

it('aborta quando o owner já possui hash diferente', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'capture_token_hash' => hash('sha256', 'outro-token'),
        'capture_token_last_four' => 'oken',
    ])->save();

    config([
        'capture.api_token' => 'token-legado',
        'capture.owner_user_id' => $user->id,
    ]);

    expect(fn () => legacyBackfillMigration()->up())
        ->toThrow(RuntimeException::class, 'Backfill abortado: owner já possui capture_token_hash diferente');
});

it('não altera o owner quando o hash já corresponde', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'capture_token_hash' => hash('sha256', 'token-legado'),
        'capture_token_last_four' => 'gado',
    ])->save();

    config([
        'capture.api_token' => 'token-legado',
        'capture.owner_user_id' => $user->id,
    ]);

    legacyBackfillMigration()->up();

    expect($user->refresh()->capture_token_hash)->toBe(hash('sha256', 'token-legado'));
});

it('não faz nada quando não há token legado', function () {
    $user = User::factory()->create();
    config([
        'capture.api_token' => '',
        'capture.owner_user_id' => $user->id,
    ]);

    legacyBackfillMigration()->up();

    expect($user->refresh()->capture_token_hash)->toBeNull();
});
