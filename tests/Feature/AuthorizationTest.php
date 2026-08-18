<?php

use App\Filament\Resources\JobPostings\JobPostingResource;
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
    $posting = JobPosting::factory()->confirmed()->create([
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

    $posting = JobPosting::factory()->confirmed()->create([
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

    // A rota de download do PDF é protegida pela mesma fronteira.
    $this->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertForbidden();
});
