<?php

use App\Filament\Widgets\TopConceptsWidget;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lista os conceitos mais frequentes de vagas confirmadas', function () {
    $posting = JobPosting::factory()->for($this->user, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    $second = JobPosting::factory()->for($this->user, 'user')->confirmed()->create();
    $second->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    $second->items()->create(['category' => 'tool', 'name' => 'Docker', 'source' => 'ai']);

    Livewire::test(TopConceptsWidget::class)
        ->assertSuccessful()
        ->assertSee('Python')
        ->assertSee('Docker');
});

it('ignora vagas ainda não confirmadas', function () {
    $confirmed = JobPosting::factory()->for($this->user, 'user')->confirmed()->create();
    $confirmed->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    $pending = JobPosting::factory()->for($this->user, 'user')->create();
    $pending->items()->create(['category' => 'skill', 'name' => 'Kotlin', 'source' => 'ai']);

    Livewire::test(TopConceptsWidget::class)
        ->assertSuccessful()
        ->assertSee('Python')
        ->assertDontSee('Kotlin');
});
