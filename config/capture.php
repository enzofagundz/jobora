<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuração da API de captura de vagas
    |--------------------------------------------------------------------------
    |
    | api_token: token Bearer único exigido pelo middleware `auth.capture-token`
    | nas rotas de captura (routes/api.php). O UserScript envia esse token no
    | header Authorization — ele NUNCA deve ser embutido no script; é
    | configurado pelo usuário via menu do Violentmonkey.
    |
    */

    'api_token' => env('VAGAS_API_TOKEN', ''),

];
