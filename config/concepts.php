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
| 'category' é a categoria dona (skill|tool|methodology) usada para
| resolver duplicatas entre categorias na extração de vagas.
| Nomes canônicos seguem a forma oficial do mercado. Cresce conforme o uso.
*/

return [
    'PostgreSQL' => [
        'category' => 'skill',
        'aliases' => ['postgresql', 'postgres', 'pgsql'],
        'keywords' => ['postgres'],
    ],
    'MySQL' => [
        'category' => 'skill',
        'aliases' => ['mysql'],
        'keywords' => ['mysql'],
    ],
    'SQL Server' => [
        'category' => 'skill',
        'aliases' => ['sql server', 'mssql'],
        'keywords' => ['sql server', 'mssql'],
    ],
    'MongoDB' => [
        'category' => 'skill',
        'aliases' => ['mongodb', 'mongo'],
        'keywords' => ['mongodb', 'mongo'],
    ],
    'Redis' => [
        'category' => 'skill',
        'aliases' => ['redis'],
        'keywords' => ['redis'],
    ],
    'PHP' => [
        'category' => 'skill',
        'aliases' => ['php'],
        'keywords' => ['php'],
    ],
    'Python' => [
        'category' => 'skill',
        'aliases' => ['python'],
        'keywords' => ['python'],
    ],
    'JavaScript' => [
        'category' => 'skill',
        'aliases' => ['javascript', 'js'],
        'keywords' => ['javascript'],
    ],
    'TypeScript' => [
        'category' => 'skill',
        'aliases' => ['typescript', 'ts'],
        'keywords' => ['typescript'],
    ],
    'Node.js' => [
        'category' => 'skill',
        'aliases' => ['node.js', 'nodejs', 'node'],
        'keywords' => ['node.js', 'nodejs'],
    ],
    'React' => [
        'category' => 'skill',
        'aliases' => ['react', 'react.js', 'reactjs'],
        'keywords' => ['react'],
    ],
    'Vue.js' => [
        'category' => 'skill',
        'aliases' => ['vue.js', 'vuejs', 'vue'],
        'keywords' => ['vue.js', 'vuejs'],
    ],
    'Laravel' => [
        'category' => 'skill',
        'aliases' => ['laravel framework', 'laravel'],
        'keywords' => ['laravel'],
    ],
    'Symfony' => [
        'category' => 'skill',
        'aliases' => ['symfony'],
        'keywords' => ['symfony'],
    ],
    'Docker' => [
        'category' => 'tool',
        'aliases' => ['docker'],
        'keywords' => ['docker'],
    ],
    'Kubernetes' => [
        'category' => 'tool',
        'aliases' => ['kubernetes', 'k8s'],
        'keywords' => ['kubernetes', 'k8s'],
    ],
    'Git' => [
        'category' => 'tool',
        'aliases' => ['git'],
        'keywords' => ['git'],
    ],
    'AWS' => [
        'category' => 'tool',
        'aliases' => ['amazon web services', 'aws'],
        'keywords' => ['aws', 'amazon web services'],
    ],
    'Azure' => [
        'category' => 'tool',
        'aliases' => ['azure', 'microsoft azure'],
        'keywords' => ['azure'],
    ],
    'Google Cloud' => [
        'category' => 'tool',
        'aliases' => ['google cloud platform', 'gcp', 'google cloud'],
        'keywords' => ['google cloud', 'gcp'],
    ],
    'Linux' => [
        'category' => 'tool',
        'aliases' => ['linux', 'gnu/linux'],
        'keywords' => ['linux'],
    ],
    'Agile' => [
        'category' => 'methodology',
        'aliases' => ['agile', 'metodologia agil'],
        'keywords' => ['agile', 'agil'],
    ],
    'Scrum' => [
        'category' => 'methodology',
        'aliases' => ['scrum'],
        'keywords' => ['scrum'],
    ],
    'Kanban' => [
        'category' => 'methodology',
        'aliases' => ['kanban'],
        'keywords' => ['kanban'],
    ],
    'TDD' => [
        'category' => 'methodology',
        'aliases' => ['tdd', 'test driven development'],
        'keywords' => ['tdd'],
    ],
    'REST API' => [
        'category' => 'skill',
        'aliases' => ['rest api', 'api rest', 'rest'],
        'keywords' => ['rest api'],
    ],
    'CI/CD' => [
        'category' => 'tool',
        'aliases' => ['ci/cd', 'cicd', 'ci cd'],
        'keywords' => ['ci/cd', 'cicd'],
    ],
    'GraphQL' => [
        'category' => 'skill',
        'aliases' => ['graphql'],
        'keywords' => ['graphql'],
    ],
];
