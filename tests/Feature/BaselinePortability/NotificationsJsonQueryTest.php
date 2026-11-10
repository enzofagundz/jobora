<?php

use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function baselinePortabilityNotification(User $user, string $title): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\JobPostingNotification',
        'data' => [
            'format' => 'filament',
            'title' => $title,
            'body' => 'Corpo da notificação.',
            'duration' => 'persistent',
            'status' => 'danger',
        ],
    ]);
}

it('filtra notificações por chave do payload JSON', function () {
    baselinePortabilityNotification($this->user, 'Falha na análise da vaga');
    baselinePortabilityNotification($this->user, 'Análise de vaga concluída');

    expect(
        $this->user->unreadNotifications()
            ->where('data->title', 'Falha na análise da vaga')
            ->count()
    )->toBe(1);
});

it('marca como lida a notificação de falha ao abrir a vaga no painel', function () {
    baselinePortabilityNotification($this->user, 'Falha na análise da vaga');

    $posting = JobPosting::factory()->for($this->user, 'user')->failed()->create();

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful();

    expect($this->user->unreadNotifications()->count())->toBe(0);
});
