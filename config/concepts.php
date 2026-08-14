<?php

/*
|--------------------------------------------------------------------------
| Conceitos canônicos
|--------------------------------------------------------------------------
|
| Mapa de aliases → nome canônico, usado pelo ConceptNormalizer para
| normalizar nomes extraídos da IA e pelos keywords para casar conceitos
| no currículo (ResumeAnalyzer). O currículo usa o MESMO normalizador
| para garantir consistência entre as duas pontas.
|
| Nomes canônicos em Title Case. Cresce conforme o uso.
*/

return [
    'PostgreSQL' => [
        'aliases' => ['postgresql', 'postgres', 'pgsql'],
        'keywords' => ['postgres'],
    ],
    'MySQL' => [
        'aliases' => ['mysql'],
        'keywords' => ['mysql'],
    ],
    'SQL Server' => [
        'aliases' => ['sql server', 'mssql'],
        'keywords' => ['sql server', 'mssql'],
    ],
    'MongoDB' => [
        'aliases' => ['mongodb', 'mongo'],
        'keywords' => ['mongodb', 'mongo'],
    ],
    'Redis' => [
        'aliases' => ['redis'],
        'keywords' => ['redis'],
    ],
    'PHP' => [
        'aliases' => ['php'],
        'keywords' => ['php'],
    ],
    'Python' => [
        'aliases' => ['python'],
        'keywords' => ['python'],
    ],
    'JavaScript' => [
        'aliases' => ['javascript', 'js'],
        'keywords' => ['javascript'],
    ],
    'TypeScript' => [
        'aliases' => ['typescript', 'ts'],
        'keywords' => ['typescript'],
    ],
    'Node.js' => [
        'aliases' => ['node.js', 'nodejs', 'node'],
        'keywords' => ['node.js', 'nodejs'],
    ],
    'React' => [
        'aliases' => ['react', 'react.js', 'reactjs'],
        'keywords' => ['react'],
    ],
    'Vue.js' => [
        'aliases' => ['vue.js', 'vuejs', 'vue'],
        'keywords' => ['vue.js', 'vuejs'],
    ],
    'Laravel' => [
        'aliases' => ['laravel framework', 'laravel'],
        'keywords' => ['laravel'],
    ],
    'Symfony' => [
        'aliases' => ['symfony'],
        'keywords' => ['symfony'],
    ],
    'Docker' => [
        'aliases' => ['docker'],
        'keywords' => ['docker'],
    ],
    'Kubernetes' => [
        'aliases' => ['kubernetes', 'k8s'],
        'keywords' => ['kubernetes', 'k8s'],
    ],
    'Git' => [
        'aliases' => ['git'],
        'keywords' => ['git'],
    ],
    'AWS' => [
        'aliases' => ['amazon web services', 'aws'],
        'keywords' => ['aws', 'amazon web services'],
    ],
    'Azure' => [
        'aliases' => ['azure', 'microsoft azure'],
        'keywords' => ['azure'],
    ],
    'Google Cloud' => [
        'aliases' => ['google cloud platform', 'gcp', 'google cloud'],
        'keywords' => ['google cloud', 'gcp'],
    ],
    'Linux' => [
        'aliases' => ['linux', 'gnu/linux'],
        'keywords' => ['linux'],
    ],
    'Agile' => [
        'aliases' => ['agile', 'metodologia agil'],
        'keywords' => ['agile', 'agil'],
    ],
    'Scrum' => [
        'aliases' => ['scrum'],
        'keywords' => ['scrum'],
    ],
    'Kanban' => [
        'aliases' => ['kanban'],
        'keywords' => ['kanban'],
    ],
    'TDD' => [
        'aliases' => ['tdd', 'test driven development'],
        'keywords' => ['tdd'],
    ],
];
