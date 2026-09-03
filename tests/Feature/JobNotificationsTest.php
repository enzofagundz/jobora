<?php

use App\Agents\JobExtractionAgent;
use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use App\Services\JobTriageService;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function jobNotificationsDescription(): string
{
    return 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco relacional.'.str_repeat(' ', 20);
}

it('notifica análise concluída apenas ao destinatário com evento broadcast', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
        'skills' => ['PHP', 'Laravel'],
    ]]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $posting = JobPosting::factory()->for($userA, 'user')->create(['description' => jobNotificationsDescription()]);

    (new AnalyzeJobPosting($posting, $userA->id))->handle(app(JobAnalysisService::class));

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview);

    $notification = $userA->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['format'])->toBe('filament')
        ->and($notification->data['title'])->toBe('Análise de vaga concluída')
        ->and($notification->data['body'])->toBe('A vaga foi analisada e está aguardando revisão.')
        ->and($notification->read_at)->toBeNull();

    expect($userB->notifications()->count())->toBe(0);

    Event::assertDispatched(DatabaseNotificationsSent::class, 1);
});

it('notifica triagem concluída com contagem correta apenas ao destinatário', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $c1 = JobCapture::factory()->for($userA, 'user')->create(['description' => str_repeat('Vaga PHP com Laravel. ', 5)]);
    $c2 = JobCapture::factory()->for($userA, 'user')->create(['description' => str_repeat('Vaga PHP com Docker. ', 5)]);

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de PHP.'],
            ['capture_id' => $c2->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de PHP.'],
        ],
    ]]);

    (new TriageJobCaptures([$c1->id, $c2->id], $userA->id))->handle(app(JobTriageService::class));

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed);

    $notification = $userA->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['format'])->toBe('filament')
        ->and($notification->data['title'])->toBe('Triagem concluída')
        ->and($notification->data['body'])->toBe('2 captura(s) processada(s) com sucesso.')
        ->and($notification->read_at)->toBeNull();

    expect($userB->notifications()->count())->toBe(0);

    Event::assertDispatched(DatabaseNotificationsSent::class, 1);
});

it('notifica falha terminal de análise sem criar sucesso', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake(fn () => throw new RuntimeException('Serviço indisponível'));

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $posting = JobPosting::factory()->for($userA, 'user')->create(['description' => jobNotificationsDescription()]);

    (new AnalyzeJobPosting($posting, $userA->id))->handle(app(JobAnalysisService::class));

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Failed);

    $notification = $userA->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['format'])->toBe('filament')
        ->and($notification->data['title'])->toBe('Falha na análise da vaga')
        ->and($notification->data['body'])->toContain('Não foi possível concluir')
        ->and($notification->data['body'])->toContain('Tentativa');

    $titles = $userA->notifications()->pluck('data')->map(fn ($d) => $d['title'] ?? null)->all();
    expect($titles)->not->toContain('Análise de vaga concluída');

    expect($userB->notifications()->count())->toBe(0);

    Event::assertDispatched(DatabaseNotificationsSent::class, 1);
});

it('AnalyzeJobPosting failed() persiste falha e notifica erro apenas uma vez', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => jobNotificationsDescription(), 'status' => JobPostingStatus::Pending]);

    (new AnalyzeJobPosting($posting, $user->id))->failed(new RuntimeException('timeout worker'));

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('demorou demais')
        ->and($posting->attempts()->first()?->error_message)->toContain('timeout');

    $notification = $user->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Falha na análise da vaga')
        ->and($notification->data['format'])->toBe('filament');

    Event::fake([DatabaseNotificationsSent::class]);
    $user2 = User::factory()->create();
    $posting2 = JobPosting::factory()->for($user2, 'user')->needsReview()->create(['description' => jobNotificationsDescription()]);
    (new AnalyzeJobPosting($posting2, $user2->id))->failed(new RuntimeException('timeout'));

    expect($posting2->refresh()->status)->toBe(JobPostingStatus::NeedsReview);
    expect($user2->notifications()->count())->toBe(0);
    Event::assertNotDispatched(DatabaseNotificationsSent::class);
});

it('TriageJobCaptures failed() marca processing como failed e notifica erro', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $c1 = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Processing]);
    $c2 = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Completed]);

    (new TriageJobCaptures([$c1->id, $c2->id], $user->id))->failed(new RuntimeException('timeout triage'));

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed);

    $notification = $user->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Falha na triagem')
        ->and($notification->data['format'])->toBe('filament')
        ->and($notification->data['body'])->toBe('Nenhuma captura foi processada com sucesso.');

    $titles = $user->notifications()->pluck('data')->map(fn ($d) => $d['title'] ?? null)->all();
    expect($titles)->not->toContain('Triagem concluída');
});

it('isolamento entre destinatarios e formato persistido', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['PHP'],
    ]]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $posting = JobPosting::factory()->for($userA, 'user')->create(['description' => jobNotificationsDescription()]);

    (new AnalyzeJobPosting($posting, $userA->id))->handle(app(JobAnalysisService::class));

    $viaRelation = $userA->notifications;
    $viaModel = DatabaseNotification::where('notifiable_type', User::class)
        ->where('notifiable_id', $userA->id)
        ->get();

    expect($viaRelation)->toHaveCount(1)
        ->and($viaModel)->toHaveCount(1)
        ->and($viaRelation->first()->data['format'])->toBe('filament')
        ->and($viaRelation->first()->read_at)->toBeNull()
        ->and($viaModel->first()->data['title'])->toBe('Análise de vaga concluída');

    expect($userB->notifications()->count())->toBe(0);
    expect(DatabaseNotification::where('notifiable_id', $userB->id)->count())->toBe(0);
});

it('não notifica quando usuario não existe mais', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['PHP'],
    ]]);

    // Posting pertence a um usuário existente, mas o job é despachado para um usuário inexistente.
    $owner = User::factory()->create();
    $posting = JobPosting::factory()->for($owner, 'user')->create(['description' => jobNotificationsDescription()]);
    $nonExistentUserId = 999999;

    (new AnalyzeJobPosting($posting, $nonExistentUserId))->handle(app(JobAnalysisService::class));

    // Com isolamento multiusuário, job com owner inexistente não muta posting nem notifica.
    expect($posting->refresh()->status)->toBe(JobPostingStatus::Pending);
    expect(DatabaseNotification::count())->toBe(0);
    Event::assertNotDispatched(DatabaseNotificationsSent::class);
});

it('triagem mista gera warning com contagem correta', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $c1 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP. ', 5)]);
    $c2 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP. ', 5)]);

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Ok'],
        ],
    ]]);

    (new TriageJobCaptures([$c1->id, $c2->id], $user->id))->handle(app(JobTriageService::class));

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed);

    $notification = $user->notifications()->first();
    expect($notification->data['title'])->toBe('Triagem concluída com erros')
        ->and($notification->data['body'])->toBe('1 captura(s) processada(s); 1 falha(s).');
});

it('renderiza o listener de broadcast mesmo com estado vazio', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect($user->notifications()->count())->toBe(0);

    $html = Livewire::test(DatabaseNotifications::class)->html();

    expect($html)->toContain('.database-notifications.sent')
        ->and($html)->toContain('EchoLoaded');
});

it('mantém polling de fallback para notificações persistentes', function () {
    expect(Filament::getPanel('admin')->getDatabaseNotificationsPollingInterval())->toBe('30s');
});
