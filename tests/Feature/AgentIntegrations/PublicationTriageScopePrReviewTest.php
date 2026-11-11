<?php

use App\Enums\AgentIntegrationScope;
use App\Models\AiOperation;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('autoriza triagem somente com captures write antes de criar operação', function (AgentIntegrationScope $scope, bool $allowed) {
    Queue::fake();
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [$scope]);
    $token = Helpers::accessToken($user, $client);
    $content = 'Vaga Backend Engineer.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);

    $response = $this->withToken($token)->postJson('/mcp', Helpers::toolCall('request_publication_triage', [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ]))->assertOk();
    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBe($allowed)
        ->and(AiOperation::query()->count())->toBe($allowed ? 1 : 0);

    if (! $allowed) {
        expect($body['error']['code'] ?? null)->toBe('forbidden');
        Queue::assertNothingPushed();
    }
})->with([
    'captures write permite' => [AgentIntegrationScope::CapturesWrite, true],
    'job postings write não permite' => [AgentIntegrationScope::JobPostingsWrite, false],
]);
