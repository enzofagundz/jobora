<?php

return [

    'redirect_domains' => [
        'http://localhost',
        'http://127.0.0.1',
        'http://[::1]',
    ],

    'custom_schemes' => [],

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
