<?php

use App\Support\AdaptedResumeParser;

it('maps a full adapted resume into display blocks', function () {
    $content = <<<'TXT'
ENZO FAGUNDES
+55 14 99757-2550 | enzofagundz@gmail.com | Lins, SP
linkedin.com/in/enzofagundz

DESENVOLVEDOR FULL STACK | ESPECIALISTA EM BACK-END PHP/LARAVEL

Resumo profissional:
Desenvolvedor com 3 anos de experiência no ecossistema Laravel.

EXPERIÊNCIA PROFISSIONAL

ANALISTA DE DESENVOLVIMENTO | Sonnitech | 2024 - Atual
Desenvolvimento de sistemas web com Laravel, Vue.js, MySQL e Docker.

- Projeto de alta complexidade com Open Route Service.
- Otimização de performance com queries otimizadas.

FORMAÇÃO ACADÊMICA
Tecnólogo em Sistemas para Internet | FATEC Lins (2021-2024)

HABILIDADES TÉCNICAS
- Back-end: PHP, Laravel, APIs REST, MySQL
- Front-end: Vue.js, Livewire, Tailwind CSS
TXT;

    expect(AdaptedResumeParser::parse($content))->toBe([
        ['type' => 'header', 'lines' => [
            'ENZO FAGUNDES',
            '+55 14 99757-2550 | enzofagundz@gmail.com | Lins, SP',
            'linkedin.com/in/enzofagundz',
        ]],
        ['type' => 'heading', 'text' => 'DESENVOLVEDOR FULL STACK | ESPECIALISTA EM BACK-END PHP/LARAVEL'],
        ['type' => 'paragraph', 'text' => 'Resumo profissional:'],
        ['type' => 'paragraph', 'text' => 'Desenvolvedor com 3 anos de experiência no ecossistema Laravel.'],
        ['type' => 'heading', 'text' => 'EXPERIÊNCIA PROFISSIONAL'],
        ['type' => 'entry', 'parts' => ['ANALISTA DE DESENVOLVIMENTO', 'Sonnitech', '2024 - Atual']],
        ['type' => 'paragraph', 'text' => 'Desenvolvimento de sistemas web com Laravel, Vue.js, MySQL e Docker.'],
        ['type' => 'bullets', 'items' => [
            'Projeto de alta complexidade com Open Route Service.',
            'Otimização de performance com queries otimizadas.',
        ]],
        ['type' => 'heading', 'text' => 'FORMAÇÃO ACADÊMICA'],
        ['type' => 'entry', 'parts' => ['Tecnólogo em Sistemas para Internet', 'FATEC Lins (2021-2024)']],
        ['type' => 'heading', 'text' => 'HABILIDADES TÉCNICAS'],
        ['type' => 'bullets', 'items' => [
            'Back-end: PHP, Laravel, APIs REST, MySQL',
            'Front-end: Vue.js, Livewire, Tailwind CSS',
        ]],
    ]);
});

it('does not build a header when the content starts with a section heading', function () {
    $content = "RESUMO PROFISSIONAL\n\nDesenvolvedor com 3 anos de experiência.";

    expect(AdaptedResumeParser::parse($content))->toBe([
        ['type' => 'heading', 'text' => 'RESUMO PROFISSIONAL'],
        ['type' => 'paragraph', 'text' => 'Desenvolvedor com 3 anos de experiência.'],
    ]);
});

it('does not treat punctuation-only lines as headings', function () {
    $content = "ENZO FAGUNDES\n\n(2021-2024)";

    expect(AdaptedResumeParser::parse($content))->toBe([
        ['type' => 'heading', 'text' => 'ENZO FAGUNDES'],
        ['type' => 'paragraph', 'text' => '(2021-2024)'],
    ]);
});
