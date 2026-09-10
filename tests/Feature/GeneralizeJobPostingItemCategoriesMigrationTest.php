<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('remapeia categorias antigas e preserva o item do usuário ao generalizar categorias', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'vagas_mig_');
    if ($tmp === false) {
        throw new RuntimeException('Falha ao criar arquivo temporário para o teste de migration.');
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

        // Sobe o schema anterior à generalização de categorias.
        $priorMigrations = collect(glob(database_path('migrations/*.php')))
            ->filter(fn (string $path): bool => basename($path) < '2026_08_20_000001')
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $priorMigrations,
            '--realpath' => true,
            '--force' => true,
            '--database' => 'sqlite',
        ]);

        $now = now()->toDateTimeString();

        $postingId = DB::table('job_postings')->insertGetId([
            'description' => 'Vaga de teste',
            'collected_at' => $now,
            'status' => 'confirmed',
            'suggested_data' => json_encode([
                'items' => [
                    ['category' => 'framework', 'name' => 'Laravel'],
                    ['category' => 'tool', 'name' => 'Docker'],
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Após o remap, 'technology' e 'skill' colidem em (vaga, skill, PHP): a linha do usuário vence.
        DB::table('job_posting_items')->insert([
            ['job_posting_id' => $postingId, 'category' => 'technology', 'name' => 'PHP', 'source' => 'ai', 'created_at' => $now, 'updated_at' => $now],
            ['job_posting_id' => $postingId, 'category' => 'skill', 'name' => 'PHP', 'source' => 'user', 'created_at' => $now, 'updated_at' => $now],
            ['job_posting_id' => $postingId, 'category' => 'framework', 'name' => 'Laravel', 'source' => 'ai', 'created_at' => $now, 'updated_at' => $now],
            ['job_posting_id' => $postingId, 'category' => 'methodology', 'name' => 'Scrum', 'source' => 'ai', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/2026_08_20_000001_generalize_job_posting_item_categories.php')],
            '--realpath' => true,
            '--force' => true,
            '--database' => 'sqlite',
        ]);

        $items = DB::table('job_posting_items')->where('job_posting_id', $postingId)->get()->keyBy('name');

        expect($items)->toHaveCount(3)
            ->and($items['PHP']->category)->toBe('skill')
            ->and($items['PHP']->source)->toBe('user')
            ->and($items['Laravel']->category)->toBe('skill')
            ->and($items['Scrum']->category)->toBe('methodology');

        expect(DB::table('job_posting_items')->whereIn('category', ['technology', 'language', 'framework', 'database', 'cloud'])->count())->toBe(0);

        $suggested = json_decode(DB::table('job_postings')->where('id', $postingId)->value('suggested_data'), true);
        expect($suggested['items'][0]['category'])->toBe('skill')
            ->and($suggested['items'][1]['category'])->toBe('tool');
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
});
