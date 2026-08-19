<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Critérios de triagem de capturas
    |--------------------------------------------------------------------------
    |
    | Listas de texto livre editadas diretamente aqui. O agente de triagem
    | recebe as duas listas no payload e decide, por captura, se ela é uma
    | vaga e se interessa ao usuário. Rejeições são exclusões explícitas e
    | têm prioridade sobre preferências.
    |
    */

    'preferences' => [
        'PHP',
        'Laravel',
        'remoto',
        'pleno',
        'home office',
    ],

    'rejections' => [
        'Tech Lead',
        'posições de liderança',
        'presencial',
        'estágio',
        'wordpress',
        'sênior',
        'híbrido',
    ],

];
