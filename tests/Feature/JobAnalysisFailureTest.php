<?php

use App\Agents\JobExtractionAgent;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobIaAttempt;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('records permanent failure with friendly message and attempt history when IA returns non-JSON', function () {
    JobExtractionAgent::fake(['isto não é um JSON válido']);

    $posting = JobPosting::factory()->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco de dados relacional.'.str_repeat(' ', 20),
    ]);

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não conseguimos analisar esta vaga porque a IA devolveu um formato inválido. Tente novamente — se persistir, revise a descrição.')
        ->and($posting->suggested_data)->toBeNull();

    $attempt = JobIaAttempt::query()->whereBelongsTo($posting, 'jobPosting')->sole();

    expect($attempt->operation)->toBe('job_extraction')
        ->and($attempt->transient)->toBeFalse()
        ->and($attempt->status)->toBe('permanent_error')
        ->and($attempt->error_class)->toBe(InvalidArgumentException::class)
        ->and($attempt->error_message)->toContain('JSON válido')
        ->and($attempt->attempt_no)->toBe(1);
});

it('records transient error as attempt and keeps pending without friendly failure', function () {
    $response = new Response(new PsrResponse(429, [], 'rate limited'));

    JobExtractionAgent::fake(fn () => throw new RequestException($response));

    $posting = JobPosting::factory()->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco de dados relacional.'.str_repeat(' ', 20),
    ]);

    try {
        app(JobAnalysisService::class)->analyze($posting->user, $posting);
        expect(false)->toBeTrue('Expected RequestException not thrown');
    } catch (RequestException) {
        expect(true)->toBeTrue();
    }

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->error_message)->toBeNull();

    $attempt = JobIaAttempt::query()->whereBelongsTo($posting, 'jobPosting')->sole();

    expect($attempt->transient)->toBeTrue()
        ->and($attempt->status)->toBe('transient_error')
        ->and($attempt->attempt_no)->toBe(1);
});

it('notifies permanent failure with friendly body and retry actions via job', function () {
    Event::fake([DatabaseNotificationsSent::class]);
    JobExtractionAgent::fake(['isto não é um JSON válido']);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco de dados relacional.'.str_repeat(' ', 20),
    ]);

    (new AnalyzeJobPosting($posting, $user->id))->handle(app(JobAnalysisService::class));

    $notification = $user->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Falha na análise da vaga')
        ->and($notification->data['body'])->toContain('formato inválido')
        ->and($notification->data['body'])->toContain('Tentativa 1');
});

it('opens failed posting detail with friendly error instead of redirecting', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    JobExtractionAgent::fake(['isto não é um JSON válido']);

    $posting = JobPosting::factory()->for($user, 'user')->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco de dados relacional.'.str_repeat(' ', 20),
    ]);

    app(JobAnalysisService::class)->analyze($user, $posting);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('formato inválido');
});
