<?php

use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Policy de negação usada apenas para provar que a fronteira de autorização
 * (Resource, ações, rota PDF) está ligada às policies do Laravel.
 */
class DenyingJobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, JobPosting $posting): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JobPosting $posting): bool
    {
        return false;
    }

    public function delete(User $user, JobPosting $posting): bool
    {
        return false;
    }
}

it('permite com a policy single-user (fronteira explícita)', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create([
        'description' => 'Vaga para desenvolvedor Python com React.',
    ]);

    expect(JobPostingResource::canViewAny())->toBeTrue()
        ->and(JobPostingResource::canCreate())->toBeTrue()
        ->and(JobPostingResource::canView($posting))->toBeTrue()
        ->and(JobPostingResource::canEdit($posting))->toBeTrue()
        ->and(JobPostingResource::canDelete($posting))->toBeTrue();
});

it('nega acesso quando a policy nega (prova do wiring de autorização)', function () {
    Gate::policy(JobPosting::class, DenyingJobPostingPolicy::class);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create([
        'description' => 'Vaga para desenvolvedor Python com React.',
    ]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado.',
    ]);

    expect(JobPostingResource::canViewAny())->toBeFalse()
        ->and(JobPostingResource::canCreate())->toBeFalse()
        ->and(JobPostingResource::canView($posting))->toBeFalse()
        ->and(JobPostingResource::canEdit($posting))->toBeFalse()
        ->and(JobPostingResource::canDelete($posting))->toBeFalse();

    // A ApplicationPage (candidatura) é protegida pela mesma fronteira de view.
    $this->get(JobPostingResource::getUrl('application', ['record' => $posting]))
        ->assertForbidden();

    // A rota de download do PDF é protegida pela mesma fronteira.
    $this->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertForbidden();
});

/**
 * Policy de negação do JobCapture: prova que o Resource do JobCapture e as
 * ações custom (triagem/promoção) delegam às policies do Laravel.
 */
class DenyingJobCapturePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, JobCapture $capture): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JobCapture $capture): bool
    {
        return false;
    }

    public function delete(User $user, JobCapture $capture): bool
    {
        return false;
    }

    public function triage(User $user, JobCapture $capture): bool
    {
        return false;
    }

    public function promote(User $user, JobCapture $capture): bool
    {
        return false;
    }
}

it('permite com a policy single-user do JobCapture (fronteira explícita)', function () {
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create();

    expect(JobCaptureResource::canViewAny())->toBeTrue()
        ->and(JobCaptureResource::canCreate())->toBeTrue()
        ->and(JobCaptureResource::canView($capture))->toBeTrue()
        ->and(JobCaptureResource::canEdit($capture))->toBeTrue()
        ->and(JobCaptureResource::canDelete($capture))->toBeTrue()
        ->and(Gate::allows('triage', $capture))->toBeTrue()
        ->and(Gate::allows('promote', $capture))->toBeTrue();
});

it('nega acesso ao JobCapture quando a policy nega (prova do wiring)', function () {
    Gate::policy(JobCapture::class, DenyingJobCapturePolicy::class);

    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create();

    expect(JobCaptureResource::canViewAny())->toBeFalse()
        ->and(JobCaptureResource::canCreate())->toBeFalse()
        ->and(JobCaptureResource::canView($capture))->toBeFalse()
        ->and(JobCaptureResource::canEdit($capture))->toBeFalse()
        ->and(JobCaptureResource::canDelete($capture))->toBeFalse()
        ->and(Gate::allows('triage', $capture))->toBeFalse()
        ->and(Gate::allows('promote', $capture))->toBeFalse();
});
