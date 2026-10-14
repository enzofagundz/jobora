<?php

use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\AtsPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds prompt with present keywords', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
        'description' => 'Vaga PHP Laravel Docker',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Docker', 'source' => 'ai'],
    ]);

    $resume = 'Experiência com PHP e Laravel.';

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, $resume);

    expect($prompt)->toContain('PHP')
        ->and($prompt)->toContain('Laravel')
        ->and($prompt)->not->toContain('Docker')
        ->and($prompt)->toContain('Desenvolvedor PHP')
        ->and($prompt)->toContain('Acme')
        ->and(mb_strlen($prompt))->toBeLessThanOrEqual(300);
});

it('respects 15 keywords limit', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => 'Dev',
        'company' => 'Co',
    ]);

    $names = [];
    for ($i = 1; $i <= 20; $i++) {
        $names[] = "Skill{$i}";
    }

    $items = array_map(fn ($n) => ['category' => 'skill', 'name' => $n, 'source' => 'ai'], $names);
    $posting->items()->createMany($items);

    $resume = implode(' ', $names);

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, $resume);

    $count = 0;
    foreach ($names as $name) {
        if (str_contains($prompt, $name)) {
            $count++;
        }
    }

    expect($count)->toBeLessThanOrEqual(15)
        ->and($count)->toBe(15);
});

it('respects 300 chars limit even with long role and many keywords', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => str_repeat('A', 100),
        'company' => str_repeat('B', 100),
    ]);

    $names = ['PHP', 'Laravel', 'Docker', 'AWS', 'Redis', 'MySQL', 'PostgreSQL', 'Vue.js', 'React', 'Node.js', 'Python', 'Kubernetes', 'Git', 'Linux', 'Agile'];
    $posting->items()->createMany(array_map(fn ($n) => ['category' => 'skill', 'name' => $n, 'source' => 'ai'], $names));

    $resume = implode(' ', $names);

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, $resume);

    expect(mb_strlen($prompt))->toBeLessThanOrEqual(300);
});

it('returns empty when no keywords present', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => 'Dev',
        'company' => 'Co',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
    ]);

    $resume = 'Experiência com Python e Ruby.';

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, $resume);

    expect($prompt)->toBe('');
});

it('handles missing role and company', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => null,
        'company' => null,
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
    ]);

    $resume = 'PHP';

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, $resume);

    expect($prompt)->toContain('PHP')
        ->and($prompt)->toContain('Perfil altamente compatível');
});

it('gera o bloco em inglês quando o idioma é EN', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create([
        'role' => 'Backend Engineer',
        'company' => 'Acme',
        'description' => 'PHP Laravel',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
    ]);

    $builder = app(AtsPromptBuilder::class);
    $prompt = $builder->build($posting, 'Experienced with PHP and Laravel.', ResumeLanguage::En);

    expect($prompt)->toContain('Highly compatible profile')
        ->and($prompt)->toContain('PHP')
        ->and($prompt)->not->toContain('Perfil altamente compatível')
        ->and(mb_strlen($prompt))->toBeLessThanOrEqual(300);
});
