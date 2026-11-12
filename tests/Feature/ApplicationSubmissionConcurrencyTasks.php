<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicationApprovalService;
use App\Services\ApplicationPreparationService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

final class ApplicationSubmissionConcurrencyTasks
{
    /**
     * @param  array{database: array{driver: string, url?: string|null, host: string, port: string|int, database: string, username: string, password: string, charset: string, prefix: string, prefix_indexes: bool, search_path: string, sslmode: string}, artifact_root: string, barrier: string}  $settings
     * @param  array{kind: 'revoke', user_id: int, approval_id: string}|array{kind: 'prepare', user_id: int, input: array{job_posting_id: int, expected_opportunity_version: int, expected_profile_confirmed_version: int, destination: array{type: string, email: string}}}|array{kind: 'http', token: string, tool: string, input: array{approval_id?: string, expected_fingerprint?: string, idempotency_key?: string, attempt_id?: string, expected_revision?: int, event_key?: string, outcome?: 'succeeded'|'failed'|'unknown', evidence?: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool, occurred_at?: string}}  $task
     * @return array<string, mixed>
     */
    public static function run(array $settings, array $task, string $name): array
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => $settings['database'],
            'filesystems.disks.local.root' => $settings['artifact_root']]);
        DB::purge('pgsql');
        Storage::forgetDisk('local');
        file_put_contents($settings['barrier'].'/'.$name.'.ready', 'ready');
        $deadline = microtime(true) + 15;
        while (count(glob($settings['barrier'].'/*.ready')) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('O segundo processo não chegou à barreira.');
            }
            usleep(10000);
        }

        if ($task['kind'] === 'revoke') {
            app(ApplicationApprovalService::class)->revoke(User::query()->findOrFail($task['user_id']), $task['approval_id']);

            return ['ok' => true];
        }
        if ($task['kind'] === 'prepare') {
            Pdf::swap(new class extends FakePdfBuilder
            {
                public function save(string $path): self
                {
                    parent::save($path);
                    file_put_contents($path, '%PDF-test '.$this->getHtml());

                    return $this;
                }
            });

            return app(ApplicationPreparationService::class)->prepare(User::query()->findOrFail($task['user_id']), $task['input']);
        }
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$task['token'], 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(Helpers::toolCall($task['tool'], $task['input']), JSON_THROW_ON_ERROR));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $result = Helpers::decodeToolResult($response->getContent());
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('A chamada MCP concorrente retornou HTTP '.$response->getStatusCode());
        }

        return $result;
    }
}
