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

];
