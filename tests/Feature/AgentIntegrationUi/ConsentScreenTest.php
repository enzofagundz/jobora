<?php

use App\Enums\AgentIntegrationScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('lists the OAuth scope and every internal capability in separate sections on the consent screen', function () {
    $user = User::factory()->create();
    $client = Helpers::client('Hermes');

    $html = view('oauth.authorize', [
        'client' => $client,
        'user' => $user,
        'scopes' => [new Scope('mcp:use', 'Usar o servidor MCP')],
        'authToken' => 'auth-token',
    ])->render();

    expect($html)
        ->toContain('Permissões OAuth')
        ->toContain('Capacidades internas do Jobora')
        ->toContain('Usar o servidor MCP');

    foreach (AgentIntegrationScope::labels() as $label) {
        expect($html)->toContain(e($label));
    }
});

it('registers only mcp:use as an OAuth scope', function () {
    expect(Passport::scopes()->pluck('id')->all())->toBe(['mcp:use']);
});
