<?php

namespace Tests\Feature;

use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobCaptureService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobCaptureConcurrencyTest extends TestCase
{
    public function test_garante_idempotencia_sob_dez_processos_concorrentes_no_caminho_fingerprint(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vagas_conc_');
        if ($tmp === false) {
            throw new \RuntimeException('Falha ao criar arquivo temporário para teste concorrente.');
        }
        @unlink($tmp);
        $tmp .= '.sqlite';
        touch($tmp);

        $originalDatabase = config('database.connections.sqlite.database');
        $originalDefault = config('database.default');
        $originalEnv = getenv('DB_DATABASE');
        $originalServer = $_SERVER['DB_DATABASE'] ?? null;
        $originalEnvArray = $_ENV['DB_DATABASE'] ?? null;

        try {
            putenv("DB_DATABASE={$tmp}");
            $_ENV['DB_DATABASE'] = $tmp;
            $_SERVER['DB_DATABASE'] = $tmp;
            config(['database.connections.sqlite.database' => $tmp]);
            config(['database.default' => 'sqlite']);

            DB::purge('sqlite');
            DB::connection('sqlite')->statement('PRAGMA busy_timeout = 5000');
            DB::connection('sqlite')->statement('PRAGMA journal_mode = WAL');
            DB::purge('sqlite');

            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'sqlite']);

            DB::connection('sqlite')->statement('PRAGMA busy_timeout = 5000');
            DB::connection('sqlite')->statement('PRAGMA journal_mode = WAL');
            DB::purge('sqlite');

            $payload = [
                'source' => 'linkedin',
                'external_id' => null,
                'url' => null,
                'search_url' => null,
                'title' => null,
                'description' => 'Vaga concorrente — teste de corrida fingerprint sem external_id nem url válida (mesma identidade canônica).',
                'company' => null,
                'location' => null,
                'author_name' => null,
                'author_profile_url' => 'https://www.linkedin.com/in/concorrencia-teste',
                'captured_at' => now()->toDateTimeString(),
            ];

            $user = User::factory()->create();
            $userId = $user->id;

            $tasks = [];
            for ($i = 0; $i < 10; $i++) {
                $tasks[] = static function () use ($payload, $tmp, $userId) {
                    putenv("DB_DATABASE={$tmp}");
                    $_ENV['DB_DATABASE'] = $tmp;
                    $_SERVER['DB_DATABASE'] = $tmp;
                    config(['database.connections.sqlite.database' => $tmp]);
                    config(['database.default' => 'sqlite']);
                    DB::purge('sqlite');
                    DB::connection('sqlite')->statement('PRAGMA busy_timeout = 5000');
                    DB::connection('sqlite')->statement('PRAGMA journal_mode = WAL');
                    DB::purge('sqlite');

                    $service = app(JobCaptureService::class);
                    $user = User::findOrFail($userId);
                    [$capture, $duplicate] = $service->store($payload, $user);

                    return [
                        'id' => $capture->id,
                        'duplicate' => $duplicate,
                        'dedup_key' => $capture->dedup_key,
                    ];
                };
            }

            $results = Concurrency::run($tasks);

            $this->assertCount(10, $results);

            $duplicates = array_column($results, 'duplicate');
            $ids = array_column($results, 'id');
            $keys = array_column($results, 'dedup_key');

            $this->assertEquals(1, JobCapture::query()->count());
            $this->assertCount(1, array_filter($duplicates, fn ($v) => $v === false));
            $this->assertCount(9, array_filter($duplicates, fn ($v) => $v === true));
            $this->assertCount(1, array_unique($ids));
            $this->assertCount(1, array_unique($keys));
            $this->assertStringStartsWith('fingerprint:', $keys[0]);
        } finally {
            if ($originalEnv === false) {
                putenv('DB_DATABASE');
            } else {
                putenv("DB_DATABASE={$originalEnv}");
            }
            if ($originalEnvArray === null) {
                unset($_ENV['DB_DATABASE']);
            } else {
                $_ENV['DB_DATABASE'] = $originalEnvArray;
            }
            if ($originalServer === null) {
                unset($_SERVER['DB_DATABASE']);
            } else {
                $_SERVER['DB_DATABASE'] = $originalServer;
            }
            config(['database.connections.sqlite.database' => $originalDatabase]);
            config(['database.default' => $originalDefault]);
            DB::purge('sqlite');

            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            foreach (["{$tmp}-wal", "{$tmp}-shm"] as $extra) {
                if (file_exists($extra)) {
                    @unlink($extra);
                }
            }
        }
    }
}
