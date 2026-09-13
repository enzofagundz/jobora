<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Servidor MCP (jobora-mcp)
    |--------------------------------------------------------------------------
    |
    | Identidade usada pelo servidor MCP local exposto ao Hermes Agent.
    | Aceita o e-mail ou o id numérico do usuário dono dos dados. Sem esse
    | valor, as tools do MCP falham com o código de erro "configuration".
    |
    */

    'mcp' => [
        'user' => env('JOBORA_MCP_USER'),
    ],

];
