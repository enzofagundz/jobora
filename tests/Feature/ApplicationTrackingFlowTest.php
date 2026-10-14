<?php

use App\Enums\ApplicationStatus;
use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\ApplicationTrackingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('não cria candidatura ao abrir a página e o início cria exatamente uma', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Nenhum acompanhamento iniciado');

    expect(Application::where('job_posting_id', $posting->id)->exists())->toBeFalse();

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('startTracking')
        ->assertSuccessful();

    $application = Application::where('job_posting_id', $posting->id)->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe(ApplicationStatus::Planned)
        ->and($application->events)->toHaveCount(1)
        ->and($application->events->first()->title)->toBe('Acompanhamento iniciado');
});

it('é idempotente e a unique protege contra duas candidaturas', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $service = app(ApplicationTrackingService::class);
    $user = auth()->user();

    $first = $service->start($user, $posting);
    $second = $service->start($user, $posting);

    expect($first->is($second))->toBeTrue()
        ->and(Application::where('job_posting_id', $posting->id)->count())->toBe(1)
        ->and(ApplicationEvent::count())->toBe(1);

    // Outra vaga pode ter sua própria candidatura.
    $other = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $service->start($user, $other);
    expect(Application::count())->toBe(2);

    // Unique do banco impede duas candidaturas para a mesma vaga.
    expect(fn () => Application::create([
        'job_posting_id' => $posting->id,
        'status' => ApplicationStatus::Planned,
    ]))->toThrow(QueryException::class);
});

it('relaciona candidatura e eventos nos dois sentidos', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $application = app(ApplicationTrackingService::class)->start(auth()->user(), $posting);

    expect($posting->application)->toBeInstanceOf(Application::class)
        ->and($posting->application->is($application))->toBeTrue()
        ->and($application->jobPosting->is($posting))->toBeTrue()
        ->and($application->events)->toHaveCount(1)
        ->and($application->events->first()->application->is($application))->toBeTrue();

    // Sem relação direta com método ou adaptação.
    expect(method_exists($application, 'applicationMethod'))->toBeFalse()
        ->and(method_exists($application, 'resumeAdaptation'))->toBeFalse();
});

it('preserva métodos e currículo ao iniciar e atualizar', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $posting->applicationMethods()->create([
        'type' => 'email',
        'email' => 'vagas@empresa.com',
        'source' => 'user',
        'dedup_key' => str_repeat('a', 64),
    ]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Versão adaptada preservada.',
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('startTracking')
        ->callAction('recordUpdate', [
            'status' => ApplicationStatus::Submitted->value,
            'title' => 'Candidatura enviada por e-mail',
            'notes' => 'Enviada para vagas@empresa.com',
            'contact' => 'vagas@empresa.com',
            'occurred_at' => now()->subDay(),
        ])
        ->assertSuccessful();
    expect($posting->refresh()->applicationMethods)->toHaveCount(1)
        ->and($posting->applicationMethods->first()->email)->toBe('vagas@empresa.com')
        ->and($posting->resumeAdaptation(ResumeLanguage::PtBr)->first()?->adapted_content)->toBe('Versão adaptada preservada.')
        ->and(Application::where('job_posting_id', $posting->id)->count())->toBe(1);
});

it('registra alteração de status com todos os campos e não apaga o anterior', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);

    app(ApplicationTrackingService::class)->recordUpdate(
        $user,
        $application,
        ApplicationStatus::Submitted,
        'Candidatura enviada por e-mail',
        'Detalhes da submissão',
        'recruiter@empresa.com',
        now()->subDays(2),
    );

    $application->refresh();
    expect($application->status)->toBe(ApplicationStatus::Submitted)
        ->and($application->events)->toHaveCount(2);

    $event = $application->events()->latest('id')->first();
    expect($event->status)->toBe(ApplicationStatus::Submitted)
        ->and($event->title)->toBe('Candidatura enviada por e-mail')
        ->and($event->notes)->toBe('Detalhes da submissão')
        ->and($event->contact)->toBe('recruiter@empresa.com');

    // Repetir o mesmo status grava nova linha sem apagar a anterior.
    app(ApplicationTrackingService::class)->recordUpdate(
        $user,
        $application,
        ApplicationStatus::Submitted,
        'Complemento enviado',
        null,
        null,
        now()->subDay(),
    );

    expect($application->refresh()->events)->toHaveCount(3)
        ->and($application->status)->toBe(ApplicationStatus::Submitted);
});

it('ordena o histórico por occurred_at desc e id desc', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);
    $service = app(ApplicationTrackingService::class);

    $future = now()->addDays(5);
    $near = now()->subDays(1);
    $retro = now()->subDays(10);

    // Criação fora de ordem de data.
    $service->recordUpdate($user, $application, ApplicationStatus::Interview, 'Entrevista', null, null, $near);
    $service->recordUpdate($user, $application, ApplicationStatus::Submitted, 'Enviada', null, null, $future);
    $service->recordUpdate($user, $application, ApplicationStatus::Preparing, 'Preparando', null, null, $retro);

    // Desempate: duas movimentações com a mesma occurred_at.
    $service->recordUpdate($user, $application, ApplicationStatus::Waiting, 'Espera', null, null, $near);
    $service->recordUpdate($user, $application, ApplicationStatus::Assessment, 'Avaliação', null, null, $near);

    $ordered = $application->events()->orderByDesc('occurred_at')->orderByDesc('id')->get();

    // occurred_at desc domina: futura primeiro, retroativa por último.
    expect($ordered->first()->status)->toBe(ApplicationStatus::Submitted)
        ->and($ordered->last()->status)->toBe(ApplicationStatus::Preparing);

    // Entre as três com $near (ids 2, 5, 6), o desempate por id desc ordena 6, 5, 2.
    // Comparação por segundo (sem microssegundos) para estabilidade entre DB e memória.
    $nearStatuses = $ordered
        ->filter(fn (ApplicationEvent $e): bool => $e->occurred_at->format('Y-m-d H:i:s') === $near->format('Y-m-d H:i:s'))
        ->pluck('status')
        ->all();
    expect($nearStatuses)->toBe([
        ApplicationStatus::Assessment,
        ApplicationStatus::Waiting,
        ApplicationStatus::Interview,
    ]);

    // A tabela na página reflete a mesma ordem (futura antes da retroativa).
    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSeeInOrder(['Enviada', 'Preparando']);
});

it('registra rejeição e conclusão mantendo eventos anteriores', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);
    $service = app(ApplicationTrackingService::class);

    $service->recordUpdate($user, $application, ApplicationStatus::Submitted, 'Enviada', null, null, now()->subDays(3));
    $service->recordUpdate($user, $application, ApplicationStatus::Rejected, 'Reprovada', 'Perfil não aderente', null, now()->subDay());

    expect($application->refresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->events)->toHaveCount(3)
        ->and(ApplicationStatus::Rejected->isTerminal())->toBeTrue();

    $service->recordUpdate($user, $application, ApplicationStatus::Completed, 'Recontratada', null, null, now());

    expect($application->refresh()->status)->toBe(ApplicationStatus::Completed)
        ->and($application->events)->toHaveCount(4);
});

it('permite retroceder sem forçar avanço monotônico', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);
    $service = app(ApplicationTrackingService::class);

    $service->recordUpdate($user, $application, ApplicationStatus::Interview, 'Entrevista', null, null, now()->subDay());
    $service->recordUpdate($user, $application, ApplicationStatus::Preparing, 'Voltou a preparar', null, null, now());

    expect($application->refresh()->status)->toBe(ApplicationStatus::Preparing)
        ->and($application->events)->toHaveCount(3)
        ->and(ApplicationEvent::where('status', ApplicationStatus::Interview)->exists())->toBeTrue();
});

it('registra observação no mesmo status sem mudar o status', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);
    $service = app(ApplicationTrackingService::class);

    $service->recordUpdate($user, $application, ApplicationStatus::Planned, 'Anotação inicial', 'Lembrar de ajustar CV', 'mentor', now());

    $application->refresh();
    expect($application->status)->toBe(ApplicationStatus::Planned)
        ->and($application->events)->toHaveCount(2)
        ->and($application->events()->latest('id')->first()->notes)->toBe('Lembrar de ajustar CV');

    // Título vazio usa o label do status.
    $service->recordUpdate($user, $application, ApplicationStatus::Planned, null, 'Segunda anotação', null, now());
    expect($application->refresh()->events()->latest('id')->first()->title)
        ->toBe(ApplicationStatus::Planned->getLabel());
});

it('rejeita dados longos no formulário sem gravar parcialmente', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('startTracking');

    $before = ApplicationEvent::count();

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('recordUpdate', [
            'status' => ApplicationStatus::Submitted->value,
            'title' => str_repeat('x', 300),
            'notes' => str_repeat('y', 3000),
            'contact' => str_repeat('z', 300),
            'occurred_at' => now(),
        ])
        ->assertHasActionErrors();

    expect(ApplicationEvent::count())->toBe($before)
        ->and(Application::first()->status)->toBe(ApplicationStatus::Planned);
});

it('renderiza a página com e sem acompanhamento e após atualização', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();

    // Sem acompanhamento: mostra o botão de início.
    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Acompanhamento da candidatura')
        ->assertSee('Iniciar acompanhamento')
        ->assertActionVisible('startTracking')
        ->assertActionHidden('recordUpdate');

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('startTracking')
        ->assertSuccessful();

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertActionHidden('startTracking')
        ->assertActionVisible('recordUpdate')
        ->assertSee('Planejada')
        ->assertSee('Próxima etapa sugerida');

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('recordUpdate', [
            'status' => ApplicationStatus::Interview->value,
            'title' => 'Entrevista agendada',
            'notes' => 'Entrevista com o hiring manager',
            'contact' => 'hiring@empresa.com',
            'occurred_at' => now()->addDays(3),
        ])
        ->assertHasNoActionErrors();

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSee('Entrevista agendada')
        ->assertSee('Entrevista com o hiring manager')
        ->assertSee('hiring@empresa.com')
        ->assertSee(ApplicationStatus::Interview->getLabel());
});

it('esconde as ações de tracking quando a policy nega update', function () {
    Gate::policy(JobPosting::class, DenyingUpdateJobPostingPolicy::class);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();

    // View permitida, update negado: a página abre mas as mutações ficam ocultas.
    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionHidden('startTracking')
        ->assertActionHidden('recordUpdate');
});

it('registra movimentação por título sem criar status de canal', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();
    $user = auth()->user();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);

    app(ApplicationTrackingService::class)->recordUpdate(
        $user,
        $application,
        ApplicationStatus::Submitted,
        'Candidatura enviada pelo LinkedIn',
        null,
        null,
        now(),
    );

    expect($application->refresh()->status)->toBe(ApplicationStatus::Submitted)
        ->and(ApplicationEvent::where('title', 'Candidatura enviada pelo LinkedIn')->exists())->toBeTrue();

    // Nenhum caso de enum de canal foi acrescentado.
    $cases = array_map(fn (ApplicationStatus $case): string => $case->value, ApplicationStatus::cases());
    expect($cases)->not->toContain('linkedin')
        ->and($cases)->not->toContain('email_channel');
});

class DenyingUpdateJobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobPosting $posting): bool
    {
        return true;
    }

    public function update(User $user, JobPosting $posting): bool
    {
        return false;
    }

    public function delete(User $user, JobPosting $posting): bool
    {
        return true;
    }
}
