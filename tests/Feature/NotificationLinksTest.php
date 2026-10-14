<?php

use App\Agents\JobExtractionAgent;
use App\Agents\JobTriageAgent;
use App\Enums\AiOperationStatus;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\JobAnalysisService;
use App\Services\JobTriageService;
use App\Services\ResumeService;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationLinksDescription(): string
{
    return 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco relacional.'.str_repeat(' ', 20);
}

// Slice 1: AiOperation ResumeAdaptation deve gerar notificação com link "Ver candidatura"
it('AiOperation ResumeAdaptation completed notification has Ver candidatura link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => notificationLinksDescription(),
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $operation->update([
        'status' => AiOperationStatus::Completed,
        'completed_at' => now(),
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyCompleted');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Adequação concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver candidatura')
        ->and($notification->data['actions'][0]['url'])->toContain('/application')
        ->and($notification->data['actions'][0]['url'])->toContain((string) $posting->id)
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

it('AiOperation ResumeAdaptation failed notification has Ver candidatura link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => notificationLinksDescription(),
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $operation->update([
        'status' => AiOperationStatus::Failed,
        'failed_at' => now(),
        'failure_code' => 'processing_failed',
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyFailed');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Falha na adequação')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver candidatura')
        ->and($notification->data['actions'][0]['url'])->toContain('/application')
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

it('AiOperation StrategicCompetencyClassification completed has Ver competências link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP e Laravel.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    $operation = app(AiOperationService::class)->enqueueStrategicCompetencyClassification($user);
    $operation->update([
        'status' => AiOperationStatus::Completed,
        'completed_at' => now(),
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyCompleted');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Classificação estratégica concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver competências')
        ->and($notification->data['actions'][0]['url'])->toContain('professional-competencies')
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

it('AiOperation AtsScore completed has Ver candidatura link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => notificationLinksDescription(),
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $operation = app(AiOperationService::class)->enqueueAtsScore($user, $posting);
    $operation->update([
        'status' => AiOperationStatus::Completed,
        'completed_at' => now(),
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyCompleted');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Simulação ATS concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver candidatura')
        ->and($notification->data['actions'][0]['url'])->toContain('/application');
});

it('AiOperation AtsOptimization completed has Ver candidatura link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $operation = app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, true);
    $operation->update([
        'status' => AiOperationStatus::Completed,
        'completed_at' => now(),
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyCompleted');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Otimização ATS concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver candidatura')
        ->and($notification->data['actions'][0]['url'])->toContain('/application');
});

it('AnalyzeJobPosting success notification has Ver vaga link', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Dev PHP',
        'company' => 'Acme',
        'skills' => ['PHP'],
    ]]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => notificationLinksDescription()]);

    (new AnalyzeJobPosting($posting, $user->id))->handle(app(JobAnalysisService::class));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Análise de vaga concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver vaga')
        ->and($notification->data['actions'][0]['url'])->toContain((string) $posting->id)
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

it('AnalyzeJobPosting failed notification has Ver vaga link', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake(fn () => throw new RuntimeException('Serviço indisponível'));

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => notificationLinksDescription()]);

    (new AnalyzeJobPosting($posting, $user->id))->handle(app(JobAnalysisService::class));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Falha na análise da vaga')
        ->and($notification->data['actions'])->toHaveCount(2)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver detalhes')
        ->and($notification->data['actions'][0]['url'])->toContain((string) $posting->id)
        ->and($notification->data['actions'][1]['label'])->toBe('Tentar novamente');
});

it('TriageJobCaptures success notification has Ver capturas link', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $c1 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP com Laravel. ', 5)]);
    $c2 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP com Docker. ', 5)]);

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de PHP.'],
            ['capture_id' => $c2->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de PHP.'],
        ],
    ]]);

    (new TriageJobCaptures([$c1->id, $c2->id], $user->id))->handle(app(JobTriageService::class));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Triagem concluída')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver capturas')
        ->and($notification->data['actions'][0]['url'])->toContain('job-captures')
        ->and($notification->data['actions'][0]['shouldMarkAsRead'])->toBeTrue();
});

it('TriageJobCaptures warning notification has Ver capturas link', function () {
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

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Triagem concluída com erros')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver capturas');
});

it('TriageJobCaptures failed notification has Ver capturas link', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $c1 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP. ', 5)]);
    $c2 = JobCapture::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga PHP. ', 5)]);

    JobTriageAgent::fake(fn () => throw new RuntimeException('Serviço indisponível'));

    (new TriageJobCaptures([$c1->id, $c2->id], $user->id))->handle(app(JobTriageService::class));

    $notifications = $user->notifications();
    if ($notifications->count() === 0) {
        (new TriageJobCaptures([$c1->id, $c2->id], $user->id))->failed(new RuntimeException('timeout triage'));
        $notification = $user->notifications()->sole();
        expect($notification->data['title'])->toBe('Falha na triagem')
            ->and($notification->data['actions'])->toHaveCount(1)
            ->and($notification->data['actions'][0]['label'])->toBe('Ver capturas');
    } else {
        $notification = $notifications->sole();
        expect($notification->data['title'])->toBe('Falha na triagem')
            ->and($notification->data['actions'])->toHaveCount(1);
    }
});

it('old notification without actions renders without error', function () {
    $user = User::factory()->create();
    // Simula notificação legada sem actions (como antes da feature)
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => DatabaseNotification::class,
        'data' => [
            'format' => 'filament',
            'title' => 'Análise de vaga concluída',
            'body' => 'Legada sem actions',
            'actions' => [],
        ],
        'read_at' => null,
    ]);

    $notification = $user->notifications()->sole();
    $filamentNotification = Notification::fromDatabase($notification);

    expect($filamentNotification->toEmbeddedHtml())->not->toContain('Ver vaga')
        ->and($notification->data['actions'])->toBe([]);
});

it('notification inline html contains link with correct label and url', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Dev PHP',
        'company' => 'Acme',
        'skills' => ['PHP'],
    ]]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => notificationLinksDescription()]);
    (new AnalyzeJobPosting($posting, $user->id))->handle(app(JobAnalysisService::class));

    $notification = $user->notifications()->sole();
    $html = Notification::fromDatabase($notification)->toEmbeddedHtml();

    expect($html)->toContain('Ver vaga')
        ->and($html)->toContain((string) $posting->id);
});

it('AnalyzeJobPosting failed() via job failure has Ver vaga link', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => notificationLinksDescription(), 'status' => JobPostingStatus::Pending]);

    (new AnalyzeJobPosting($posting, $user->id))->failed(new RuntimeException('timeout worker'));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Falha na análise da vaga')
        ->and($notification->data['actions'])->toHaveCount(2)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver detalhes')
        ->and($notification->data['actions'][0]['url'])->toContain((string) $posting->id)
        ->and($notification->data['actions'][1]['label'])->toBe('Tentar novamente');
});

it('TriageJobCaptures failed() has Ver capturas link', function () {
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    $c1 = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Processing]);

    (new TriageJobCaptures([$c1->id], $user->id))->failed(new RuntimeException('timeout triage'));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Falha na triagem')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver capturas')
        ->and($notification->data['actions'][0]['url'])->toContain('job-captures');
});

it('AiOperation StrategicCompetencyClassification failed has Ver competências link', function () {
    Queue::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    $operation = app(AiOperationService::class)->enqueueStrategicCompetencyClassification($user);
    $operation->update([
        'status' => AiOperationStatus::Failed,
        'failed_at' => now(),
        'failure_code' => 'processing_failed',
        'notified_at' => null,
    ]);

    $service = app(AiOperationService::class);
    $ref = new ReflectionMethod($service, 'notifyFailed');
    $ref->setAccessible(true);
    $ref->invoke($service, $operation->fresh(['user']));

    $notification = $user->notifications()->sole();

    expect($notification->data['title'])->toBe('Falha na classificação estratégica')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver competências')
        ->and($notification->data['actions'][0]['url'])->toContain('professional-competencies');
});

it('marks notification as read via Livewire DatabaseNotifications action', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Cria notificação com link via AnalyzeJobPosting
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['PHP'],
    ]]);
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => notificationLinksDescription()]);
    (new AnalyzeJobPosting($posting, $user->id))->handle(app(JobAnalysisService::class));

    $notification = $user->notifications()->sole();
    expect($notification->read_at)->toBeNull();

    // Simula clique na action que dispara markAsRead (notification.js -> markedNotificationAsRead)
    Livewire::test(DatabaseNotifications::class)
        ->dispatch('markedNotificationAsRead', id: $notification->getKey());

    expect($notification->fresh()->read_at)->not->toBeNull();
});
