<?php

use App\Enums\ResumeLanguage;
use App\Models\Resume;
use App\Models\User;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('salva o currículo pt-BR duas vezes mantendo uma única linha', function () {
    $user = User::factory()->create();
    $service = app(ResumeService::class);

    $service->save($user, 'Versão pt-BR inicial.', ResumeLanguage::PtBr);
    $service->save($user, 'Versão pt-BR atualizada.', ResumeLanguage::PtBr);

    expect(Resume::query()->where('user_id', $user->id)->where('language', 'pt-BR')->count())->toBe(1)
        ->and($service->content($user, ResumeLanguage::PtBr))->toBe('Versão pt-BR atualizada.');
});

it('mantém uma versão por idioma para o mesmo usuário', function () {
    $user = User::factory()->create();
    $service = app(ResumeService::class);

    $service->save($user, 'Versão pt-BR.', ResumeLanguage::PtBr);
    $service->save($user, 'English version.', ResumeLanguage::En);

    expect(Resume::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($service->content($user, ResumeLanguage::PtBr))->toBe('Versão pt-BR.')
        ->and($service->content($user, ResumeLanguage::En))->toBe('English version.');
});

it('devolve vazio ao consultar um idioma inexistente', function () {
    $user = User::factory()->create();
    $service = app(ResumeService::class);

    $service->save($user, 'Versão pt-BR.', ResumeLanguage::PtBr);

    expect($service->current($user, ResumeLanguage::En))->toBeNull()
        ->and($service->content($user, ResumeLanguage::En))->toBeNull();
});

it('trata currículo gravado sem idioma como pt-BR', function () {
    $user = User::factory()->create();

    $resume = Resume::factory()->for($user, 'user')->create(['content' => 'Legado.']);

    expect($resume->fresh()->language)->toBe(ResumeLanguage::PtBr);
});
