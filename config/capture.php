<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuração da API de captura de vagas
    |--------------------------------------------------------------------------
    |
    | A autenticação da extensão agora é por usuário (capture_token_hash).
    | As chaves globais VAGAS_API_TOKEN / VAGAS_API_USER_ID foram
    | descontinuadas e permanecem aqui apenas para a migração única de
    | compatibilidade (backfill do hash legado). Não são mais lidas pelo
    | middleware EnsureCaptureApiToken.
    |
    */

    'api_token' => env('VAGAS_API_TOKEN', ''),

    'owner_user_id' => env('VAGAS_API_USER_ID', 1),

    'ingestion' => [
        'html_max_bytes' => (int) env('CAPTURE_HTML_MAX_BYTES', 10_485_760),
        'payload_max_bytes' => (int) env('CAPTURE_PAYLOAD_MAX_BYTES', 12_582_912),
        'blocks_max' => (int) env('CAPTURE_BLOCKS_MAX', 1_000),
        'raw_retention_days' => (int) env('CAPTURE_RAW_RETENTION_DAYS', 30),
        'disk' => env('CAPTURE_DISK', 'local'),
        'path' => env('CAPTURE_PATH', 'capture-envelopes'),
    ],

];
