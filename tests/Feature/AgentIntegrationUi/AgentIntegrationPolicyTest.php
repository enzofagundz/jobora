<?php

use App\Models\AgentIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('allows the owner and denies another account for every integration ability', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $integration = AgentIntegration::factory()->for($owner)->create();

    foreach (['view', 'revoke'] as $ability) {
        expect(Gate::forUser($owner)->allows($ability, $integration))->toBeTrue()
            ->and(Gate::forUser($intruder)->allows($ability, $integration))->toBeFalse();
    }
});
