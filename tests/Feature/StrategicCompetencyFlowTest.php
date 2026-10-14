<?php

use App\Agents\StrategicCompetencyAgent;
use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\User;
use App\Services\ResumeService;
use App\Services\StrategicCompetencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function competencyUser(): User
{
    return User::factory()->create();
}

function competencyResume(User $user, string $text): void
{
    app(ResumeService::class)->save($user, $text, ResumeLanguage::PtBr);
}

function confirmedMarket(User $user, array $names): void
{
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    foreach ($names as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }
}

/**
 * Register a prompt-capturing fake, run classify() and return
 * [captured prompt JSON|null, persisted count].
 *
 * @return array{0: string|null, 1: int}
 */
function captureClassificationPrompt(User $user, callable $respond): array
{
    $captured = null;

    StrategicCompetencyAgent::fake(function (string $prompt) use (&$captured, $respond): array {
        $captured = $prompt;

        return $respond();
    });

    $count = app(StrategicCompetencyService::class)->classify($user);

    return [$captured, $count];
}

it('classifica com IA enviando currículo, candidatos e papéis no prompt', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP e Laravel.');
    confirmedMarket($user, ['PHP', 'Laravel', 'Docker']);

    [$prompt, $count] = captureClassificationPrompt($user, fn (): array => [
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'Laravel', 'strategic_role' => 'expansion'],
            ['concept' => 'Docker', 'strategic_role' => 'differentiation'],
        ],
    ]);

    $payload = json_decode($prompt, true);

    expect($count)->toBe(3)
        ->and($payload['resume'])->toBe('Currículo com PHP e Laravel.')
        ->and($payload)->not->toHaveKey('resume_concepts')
        ->and($payload['candidate_concepts'])->toContain('PHP')
        ->and($payload['candidate_concepts'])->toContain('Laravel')
        ->and($payload['candidate_concepts'])->toContain('Docker')
        ->and(collect($payload['market_items'])->firstWhere('name', 'PHP'))->toMatchArray([
            'category' => 'skill',
            'name' => 'PHP',
            'total' => 1,
            'present_in_resume' => true,
        ])
        ->and(collect($payload['market_items'])->firstWhere('name', 'Docker'))->toMatchArray([
            'present_in_resume' => false,
        ]);

    expect(ProfessionalCompetency::where('user_id', $user->id)->count())->toBe(3)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'PHP')->first())
        ->strategic_role->toBe(StrategicRole::Core)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'PHP')->first())
        ->source->toBe(ProfessionalCompetencySource::Ai)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'Laravel')->first())
        ->strategic_role->toBe(StrategicRole::Expansion)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'Docker')->first())
        ->strategic_role->toBe(StrategicRole::Differentiation);
});

it('isola classificações entre usuários para o mesmo conceito', function () {
    $alice = competencyUser();
    $bob = competencyUser();
    competencyResume($alice, 'Currículo com PHP.');
    competencyResume($bob, 'Currículo com PHP.');
    confirmedMarket($alice, ['PHP']);
    confirmedMarket($bob, ['PHP']);

    captureClassificationPrompt($alice, fn (): array => [
        'competencies' => [['concept' => 'PHP', 'strategic_role' => 'core']],
    ]);

    captureClassificationPrompt($bob, fn (): array => [
        'competencies' => [['concept' => 'PHP', 'strategic_role' => 'differentiation']],
    ]);

    $aliceRows = app(StrategicCompetencyService::class)->rows($alice);
    $bobRows = app(StrategicCompetencyService::class)->rows($bob);

    expect($aliceRows)->toHaveCount(1)
        ->and($aliceRows[0]['strategic_role'])->toBe(StrategicRole::Core)
        ->and($bobRows)->toHaveCount(1)
        ->and($bobRows[0]['strategic_role'])->toBe(StrategicRole::Differentiation)
        ->and(ProfessionalCompetency::count())->toBe(2);
});

it('reclassificação remove IA omitida, preserva manual e não sobrescreve manual homônima', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP, Laravel e Docker.');
    confirmedMarket($user, ['PHP', 'Laravel', 'Docker', 'React']);

    captureClassificationPrompt($user, fn (): array => [
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'Laravel', 'strategic_role' => 'expansion'],
            ['concept' => 'Docker', 'strategic_role' => 'differentiation'],
        ],
    ]);

    app(StrategicCompetencyService::class)->saveManual($user, 'Laravel', StrategicRole::Core);

    [, $count] = captureClassificationPrompt($user, fn (): array => [
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'expansion'],
            ['concept' => 'React', 'strategic_role' => 'core'],
        ],
    ]);

    expect($count)->toBe(2)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->count())->toBe(3);

    $laravel = ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'Laravel')->first();

    expect($laravel->strategic_role)->toBe(StrategicRole::Core)
        ->and($laravel->source)->toBe(ProfessionalCompetencySource::Manual);

    $php = ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'PHP')->first();

    expect($php->strategic_role)->toBe(StrategicRole::Expansion)
        ->and($php->source)->toBe(ProfessionalCompetencySource::Ai);

    expect(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'Docker')->exists())->toBeFalse()
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'React')->first())
        ->strategic_role->toBe(StrategicRole::Core);
});

it('ignora conceito da resposta que não pertence ao conjunto de candidatos', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP.');
    confirmedMarket($user, ['PHP']);

    [, $count] = captureClassificationPrompt($user, fn (): array => [
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'Invented Skill', 'strategic_role' => 'differentiation'],
        ],
    ]);

    expect($count)->toBe(1)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->count())->toBe(1)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'Invented Skill')->exists())->toBeFalse();
});

it('sem vagas confirmadas classifica conceitos canônicos do currículo apenas', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP e Laravel.');

    [, $count] = captureClassificationPrompt($user, fn (): array => [
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'MySQL', 'strategic_role' => 'expansion'],
        ],
    ]);

    expect($count)->toBe(1)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'PHP')->exists())->toBeTrue()
        ->and(ProfessionalCompetency::where('user_id', $user->id)->where('concept', 'MySQL')->exists())->toBeFalse();
});

it('retorna zero sem candidatos e não chama o provider', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo sem nenhum conceito conhecido pelo vocabulário.');

    StrategicCompetencyAgent::fake([]);

    $count = app(StrategicCompetencyService::class)->classify($user);

    expect($count)->toBe(0)
        ->and(ProfessionalCompetency::count())->toBe(0);

    StrategicCompetencyAgent::assertNeverPrompted();
});

it('exige currículo antes de classificar', function () {
    StrategicCompetencyAgent::fake([]);

    app(StrategicCompetencyService::class)->classify(competencyUser());
})->throws(LogicException::class, 'Cadastre o currículo');

it('falha sem persistir nada quando a resposta não é JSON válido', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP.');
    confirmedMarket($user, ['PHP']);

    StrategicCompetencyAgent::fake(fn (): string => 'não é json');

    app(StrategicCompetencyService::class)->classify($user);
})->throws(RuntimeException::class, 'JSON válido');

it('falha sem persistir nada em saída estruturalmente inválida', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP.');
    confirmedMarket($user, ['PHP']);

    StrategicCompetencyAgent::fake([['competencies' => [['concept' => 'PHP', 'strategic_role' => 'nucleo']]]]);

    app(StrategicCompetencyService::class)->classify($user);
})->throws(RuntimeException::class, 'strategic_role');

it('preserva classificações anteriores quando a IA falha', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP.');
    confirmedMarket($user, ['PHP']);

    captureClassificationPrompt($user, fn (): array => [
        'competencies' => [['concept' => 'PHP', 'strategic_role' => 'core']],
    ]);

    StrategicCompetencyAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    expect(fn () => app(StrategicCompetencyService::class)->classify($user))
        ->toThrow(RuntimeException::class, 'Provider indisponível')
        ->and(ProfessionalCompetency::where('user_id', $user->id)->count())->toBe(1)
        ->and(ProfessionalCompetency::where('user_id', $user->id)->first()->strategic_role)->toBe(StrategicRole::Core);
});

it('saveManual normaliza aliases, faz upsert e converte IA em manual', function () {
    $user = competencyUser();
    ProfessionalCompetency::factory()->create([
        'user_id' => $user->id,
        'concept' => 'PostgreSQL',
        'strategic_role' => StrategicRole::Expansion,
        'source' => ProfessionalCompetencySource::Ai,
    ]);

    $saved = app(StrategicCompetencyService::class)->saveManual($user, '  postgres ', StrategicRole::Core);

    expect(ProfessionalCompetency::where('user_id', $user->id)->count())->toBe(1)
        ->and($saved->concept)->toBe('PostgreSQL')
        ->and($saved->strategic_role)->toBe(StrategicRole::Core)
        ->and($saved->source)->toBe(ProfessionalCompetencySource::Manual);
});

it('saveManual rejeita nome vazio após normalização', function () {
    app(StrategicCompetencyService::class)->saveManual(competencyUser(), '   ', StrategicRole::Core);
})->throws(InvalidArgumentException::class, 'Informe um nome');

it('remove somente a classificação do usuário informado', function () {
    $user = competencyUser();
    $other = competencyUser();

    $mine = ProfessionalCompetency::factory()->create(['user_id' => $user->id]);
    $theirs = ProfessionalCompetency::factory()->create(['user_id' => $other->id]);

    app(StrategicCompetencyService::class)->remove($user, $mine->id);

    expect(ProfessionalCompetency::find($mine->id))->toBeNull()
        ->and(ProfessionalCompetency::find($theirs->id))->not->toBeNull();

    app(StrategicCompetencyService::class)->remove($user, $theirs->id);

    expect(ProfessionalCompetency::find($theirs->id))->not->toBeNull();
});

it('present e is_gap acompanham o currículo sem mudar o papel', function () {
    $user = competencyUser();
    competencyResume($user, 'Currículo com PHP.');
    confirmedMarket($user, ['PHP']);

    captureClassificationPrompt($user, fn (): array => [
        'competencies' => [['concept' => 'PHP', 'strategic_role' => 'core']],
    ]);

    $present = app(StrategicCompetencyService::class)->rows($user);

    expect($present[0]['present'])->toBeTrue()
        ->and($present[0]['is_gap'])->toBeFalse()
        ->and($present[0]['strategic_role'])->toBe(StrategicRole::Core);

    competencyResume($user, 'Currículo sem tecnologias citadas.');

    $absent = app(StrategicCompetencyService::class)->rows($user);

    expect($absent[0]['present'])->toBeFalse()
        ->and($absent[0]['is_gap'])->toBeTrue()
        ->and($absent[0]['strategic_role'])->toBe(StrategicRole::Core);
});

it('aplica a unique (user_id, concept) no banco', function () {
    $user = competencyUser();
    ProfessionalCompetency::factory()->create(['user_id' => $user->id, 'concept' => 'PHP']);

    ProfessionalCompetency::create([
        'user_id' => $user->id,
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Manual,
    ]);
})->throws(QueryException::class);
