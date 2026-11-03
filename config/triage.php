<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pré-filtro de triagem (Cloudflare Workers AI)
    |--------------------------------------------------------------------------
    |
    | Antes da triagem completa por IA, cada captura passa por uma pergunta
    | binária no Clef Flash. A captura abaixo do corte é concluída como
    | "não é uma vaga" sem chegar ao agente; falhas do pré-filtro seguem
    | para o LLM (fail-open).
    |
    */

    'prefilter' => [
        'enabled' => (bool) env('TRIAGE_PREFILTER_ENABLED', true),
        'min_job_probability' => (float) env('TRIAGE_PREFILTER_MIN_JOB_PROBABILITY', 0.50),
        'concurrency' => (int) env('TRIAGE_PREFILTER_CONCURRENCY', 5),
        'timeout' => (int) env('TRIAGE_PREFILTER_TIMEOUT', 15),
        'account_id' => env('TRIAGE_CLOUDFLARE_ACCOUNT_ID', env('CLOUDFLARE_ACCOUNT_ID')),
        'api_token' => env('TRIAGE_CLOUDFLARE_API_TOKEN', env('CLOUDFLARE_API_TOKEN')),
        'model' => env('TRIAGE_CLOUDFLARE_MODEL', '@cf/cloudflare/clef-flash'),
        'base_url' => env('TRIAGE_CLOUDFLARE_AI_BASE_URL', 'https://api.cloudflare.com/client/v4'),
    ],

    'questions' => [
        'is_job' => 'Esta publicação anuncia uma vaga de trabalho real e aberta a candidaturas, com cargo, empresa e/ou requisitos?',
        'non_job_kind' => 'Se a publicação NÃO anuncia uma vaga, qual é o tipo principal do conteúdo? Se anuncia, responda other.',
    ],

    'non_job_kinds' => [
        'candidate_offering' => 'Pessoa se oferecendo para trabalhar / buscando oportunidade',
        'job_curation' => 'Lista ou agregação de vagas de terceiros, com link externo',
        'editorial_content' => 'Artigo, dica, tutorial, curso, evento ou novidade',
        'promotion' => 'Anúncio comercial, promocional ou de produto',
        'other' => 'Outro conteúdo que não anuncia vaga',
    ],

];
