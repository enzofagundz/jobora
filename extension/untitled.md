Quero iniciar o desenvolvimento de uma extensão oficial do projeto [Jobora](/home/enzo/Projects/vagas) para substituir os [scripts](/home/enzo/Projects/vagas/userscripts) atualmente executados através do Violentmonkey.

A extensão deve ser desenvolvida utilizando WXT + Vue 3, partindo do template oficial:

https://github.com/wxt-dev/wxt/tree/main/templates/vue
https://wxt.dev/guide/installation.html

==================================================
OBJETIVO
==================================================

Criar uma extensão própria do projeto Jobora que substitua os scripts atuais de captura de vagas executados pelo Violentmonkey.

Atualmente existem scripts de captura para:

- LinkedIn;
- Indeed.

Os scripts atuais já foram analisados e não apresentam dependência evidente de `unsafeWindow`, interceptação de `fetch`/XHR da página ou acesso a variáveis internas do JavaScript da página.

Portanto, a implementação inicial deve utilizar content scripts normalmente.

NÃO introduzir main-world injection sem necessidade.

Se durante a implementação surgir alguma funcionalidade que realmente dependa do contexto JavaScript da página, documentar o motivo e utilizar o mecanismo apropriado do WXT para esse caso.

==================================================
FONTE DE VERDADE
==================================================

Os scripts atuais do Violentmonkey são a referência funcional da nova extensão.

Preservar o comportamento existente.

Não alterar regras de negócio da captura durante a migração sem justificativa.

Antes de implementar, analise os scripts existentes integralmente e faça um mapeamento de:

- sites;
- URLs;
- seletores;
- mecanismos de detecção;
- MutationObserver;
- navegação SPA;
- extração;
- abertura/expansão de conteúdo;
- montagem de payload;
- autenticação;
- chamadas à API;
- armazenamento;
- feedback visual;
- tratamento de erros;
- recursos específicos do Violentmonkey.

==================================================
STACK
==================================================

Utilizar:

- WXT;
- Vue 3;
- TypeScript;
- Vite através do WXT.

Partir do template oficial Vue do WXT.

Não substituir Vue por React, Svelte ou outra tecnologia.

Não criar uma aplicação Vue convencional separada da extensão.

Vue deve ser utilizado nas interfaces da extensão e nas interfaces visuais injetadas na página quando fizer sentido.

TypeScript deve ser utilizado para a lógica da extensão.

==================================================
ESTRUTURA DO PROJETO
==================================================

A extensão deve ser um projeto independente da aplicação Laravel/Filament.

Não misturar o build da extensão com o build PHP/Laravel.

A estrutura pode ser:

├── components/
├── entrypoints/
├── public/
├── assets/
├── package.json
├── tsconfig.json
├── wxt.config.ts
└── ...

Utilizar a estrutura recomendada pelo próprio WXT.

Não criar abstrações excessivas no início.

==================================================
FASE 1 — AUDITORIA
==================================================

Antes de escrever a implementação, analise os scripts atuais.

Produza um diagnóstico contendo:

1. O que o script do LinkedIn faz.
2. O que o script do Indeed faz.
3. Quais APIs do Violentmonkey são utilizadas.
4. Como cada API será substituída no WXT.
5. Quais partes precisam ser content scripts.
6. Quais partes precisam de background/service worker.
7. Quais partes precisam de popup/options.
8. Quais partes possuem interface visual.
9. Se existe alguma necessidade real de main world.
10. Como a autenticação atual funciona.
11. Como os dados são enviados para o backend.
12. Quais permissões de navegador serão necessárias.

Não implementar até concluir esse diagnóstico.

==================================================
FASE 2 — MIGRAÇÃO DAS APIs DO VIOLENTMONKEY
==================================================

Mapear explicitamente:

GM_getValue
GM_setValue
GM_deleteValue
GM_registerMenuCommand
GM_xmlhttpRequest

e qualquer outra API encontrada.

Substituir cada uma por mecanismos apropriados da extensão.

Conceitualmente:

GM_getValue / GM_setValue
→ storage da extensão

GM_registerMenuCommand
→ popup/options da extensão

GM_xmlhttpRequest
→ comunicação apropriada da extensão com a API

Não criar um "wrapper fake" simplesmente para reproduzir a API do Violentmonkey.

A arquitetura deve ser nativa de extensão.

==================================================
FASE 3 — AUTENTICAÇÃO
==================================================

A extensão não deve exigir que o usuário:

- copie código;
- cole código;
- edite JavaScript;
- configure um userscript;
- instale Violentmonkey.

A extensão deve possuir uma interface própria para configuração/autenticação.

Inicialmente, analisar o mecanismo atual de token e definir a menor alteração necessária para suportá-lo na extensão.

O token não deve ser inserido em código-fonte.

Não expor o token para o contexto da página desnecessariamente.

Preparar a arquitetura para futuramente substituir o token manual por um fluxo de "Conectar extensão" com credencial específica da extensão.

==================================================
FASE 4 — API
==================================================

Reutilizar a API existente do projeto Jobora.

Não criar uma segunda API sem necessidade.

Identificar o endpoint atualmente utilizado pelos scripts.

Preservar:

- payload;
- autenticação;
- respostas;
- tratamento de erros;
- comportamento de duplicidade.

Criar uma camada de API na extensão, por exemplo:

api/
    captures.ts
    client.ts

Mas adapte a estrutura à convenção do projeto.

==================================================
FASE 5 — CAPTURA POR SITE
==================================================

Não criar um único content script gigante contendo regras para todos os sites.

Criar uma arquitetura preparada para múltiplos sites.

Conceitualmente:

sites/
├── linkedin/
└── indeed/

Cada integração deve encapsular sua lógica específica de:

- detecção;
- extração;
- seleção de elementos;
- expansão;
- observação;
- navegação;
- transformação dos dados.

O núcleo da extensão deve permanecer independente do site.

Não criar uma taxonomia ou sistema de plugins complexo neste momento.

==================================================
FASE 6 — LINKEDIN
==================================================

Migrar o comportamento do script atual do LinkedIn.

Preservar:

- identificação dos posts;
- detecção de conteúdo relevante;
- expansão de conteúdo;
- extração;
- MutationObserver;
- navegação dinâmica;
- prevenção de processamento duplicado;
- envio para API;
- feedback visual.

Considerar explicitamente que o LinkedIn é uma SPA.

Não depender exclusivamente do carregamento inicial da página.

==================================================
FASE 7 — INDEED
==================================================

Migrar o comportamento do script atual do Indeed.

Preservar:

- identificação das vagas;
- abertura/expansão da vaga quando necessário;
- extração dos dados;
- MutationObserver;
- mudanças de página;
- prevenção de duplicidade;
- envio para API;
- feedback visual.

==================================================
FASE 8 — INTERFACE VISUAL
==================================================

Utilizar Vue 3 para as interfaces visuais.

Para interfaces inseridas diretamente nas páginas de LinkedIn/Indeed, preferir os mecanismos de UI fornecidos pelo WXT.

Avaliar especificamente `createShadowRootUi`.

Objetivo:

- impedir que o CSS da página contamine a interface da extensão;
- impedir que o CSS da extensão contamine a página;
- manter componentes Vue organizados;
- facilitar manutenção futura.

Não criar elementos Vue diretamente espalhados pelo DOM sem uma arquitetura clara.

Criar componentes reutilizáveis quando houver UI compartilhada entre sites.

==================================================
FASE 9 — POPUP
==================================================

Criar um popup inicial simples para a extensão.

Ele deve permitir visualizar pelo menos:

- estado da conexão;
- usuário conectado, quando disponível;
- estado da API;
- configuração/autenticação;
- ação de desconectar.

Não criar uma grande aplicação dentro do popup.

A interface deve ser mínima neste primeiro momento.

==================================================
FASE 10 — STORAGE
==================================================

Utilizar o mecanismo de storage apropriado para extensões/WXT.

Não utilizar:

- localStorage do LinkedIn;
- localStorage do Indeed;

como armazenamento principal da extensão.

Separar claramente:

- configuração da extensão;
- autenticação;
- estado temporário da captura.

==================================================
FASE 11 — COMUNICAÇÃO
==================================================

Quando houver comunicação entre:

- content script;
- background;
- popup;
- componentes da extensão;

utilizar mecanismos explícitos de messaging.

Não utilizar variáveis globais da página como mecanismo de comunicação entre partes da extensão.

Se main world for necessário no futuro, manter a comunicação:

MAIN WORLD
    ↓
CONTENT SCRIPT
    ↓
BACKGROUND
    ↓
API

O token não deve passar pelo main world.

==================================================
FASE 12 — MAIN WORLD
==================================================

Não utilizar main-world injection por padrão.

Os scripts atuais funcionam através de:

- document;
- DOM;
- querySelector;
- MutationObserver;
- eventos;
- window.location;
- manipulação de elementos.

Isso deve ser implementado como content script.

Somente utilizar main world se uma necessidade concreta for encontrada.

Caso isso aconteça:

1. documentar qual funcionalidade exige o main world;
2. explicar por que o isolated world não é suficiente;
3. utilizar o mecanismo recomendado pelo WXT;
4. manter o acesso às APIs da extensão no content script;
5. não expor credenciais ao código da página.

==================================================
FASE 13 — CONTEXTO DA EXTENSÃO
==================================================

Utilizar os recursos de contexto do WXT corretamente.

Em content scripts, utilizar o contexto fornecido pelo WXT para:

- timers;
- listeners;
- lifecycle;
- invalidação do contexto.

Evitar listeners e observers que permaneçam ativos após a extensão ser atualizada/desabilitada.

==================================================
FASE 14 — PERMISSÕES
==================================================

Declarar somente as permissões necessárias.

Não utilizar `<all_urls>` sem necessidade.

Os host permissions devem corresponder aos sites atualmente suportados.

Mapear claramente:

- LinkedIn;
- Indeed;
- API do Jobora.

Qualquer nova permissão deve ter justificativa.

==================================================
FASE 15 — SEGURANÇA
==================================================

Analisar:

- token;
- armazenamento;
- mensagens;
- conteúdo vindo das páginas;
- conteúdo enviado para API;
- XSS;
- permissões;
- comunicação externa.

Tratar todo conteúdo extraído das páginas como dado não confiável.

Não executar código recebido da API.

Não implementar mecanismo de execução remota de JavaScript.

==================================================
FASE 16 — TESTES
==================================================

Criar testes para a lógica que possa ser isolada.

Testar principalmente:

- extração LinkedIn;
- extração Indeed;
- detecção;
- duplicidade;
- transformação do payload;
- tratamento de dados ausentes;
- API;
- autenticação;
- storage.

Também realizar testes manuais da extensão nos sites reais.

Validar:

- instalação;
- carregamento;
- captura;
- navegação SPA;
- reload;
- múltiplas vagas;
- conteúdo carregado dinamicamente;
- erro de API;
- token inválido.

==================================================
FASE 17 — BUILD
==================================================

Configurar o projeto para:

desenvolvimento:

bun run dev

build:

bun run build

e, quando aplicável, build para navegador específico através dos mecanismos oficiais do WXT.

Não inventar scripts desnecessários.

Seguir o padrão do template oficial.

==================================================
FASE 18 — DOCUMENTAÇÃO
==================================================

Criar README explicando:

1. Como instalar dependências.
2. Como executar em desenvolvimento.
3. Como gerar build.
4. Como carregar a extensão no navegador.
5. Como configurar autenticação.
6. Como testar LinkedIn.
7. Como testar Indeed.
8. Como adicionar futuramente um novo site.

A documentação deve ser suficiente para outro desenvolvedor executar o projeto sem conhecimento prévio da implementação.

==================================================
REGRAS DE IMPLEMENTAÇÃO
==================================================

1. Não reescrever o backend.
2. Não alterar a lógica de negócio da API sem necessidade.
3. Não introduzir main world sem necessidade.
4. Não criar abstrações gigantes.
5. Não criar um framework próprio dentro da extensão.
6. Não duplicar lógica entre LinkedIn e Indeed quando ela puder ser compartilhada.
7. Não generalizar prematuramente.
8. Não colocar lógica de negócio importante no frontend da extensão.
9. Não armazenar segredo no código.
10. Não deixar dependência do Violentmonkey na implementação final.
11. Utilizar Vue 3 para UI.
12. Utilizar TypeScript para lógica.
13. Seguir as convenções oficiais do WXT.
14. Preferir APIs e mecanismos nativos do WXT em vez de soluções improvisadas.

==================================================
CRITÉRIOS DE ACEITE
==================================================

A primeira versão estará concluída quando:

- o projeto WXT + Vue estiver criado a partir do template oficial;
- a extensão compilar;
- a extensão puder ser carregada no navegador;
- LinkedIn funcionar sem Violentmonkey;
- Indeed funcionar sem Violentmonkey;
- as capturas chegarem à API;
- autenticação funcionar;
- não for necessário editar código manualmente;
- MutationObserver continuar funcionando;
- navegação SPA continuar funcionando;
- duplicidade continuar sendo evitada;
- interfaces visuais continuarem funcionando;
- não existir dependência funcional do Violentmonkey;
- permissões forem mínimas;
- o README permitir reproduzir o ambiente.

==================================================
RELATÓRIO FINAL
==================================================

Ao terminar, apresente:

1. Estrutura final do projeto.
2. Arquivos criados.
3. Arquivos alterados.
4. Como o Violentmonkey foi substituído.
5. Como GM_getValue/GM_setValue foram substituídos.
6. Como GM_xmlhttpRequest foi substituído.
7. Como GM_registerMenuCommand foi substituído.
8. Como LinkedIn foi migrado.
9. Como Indeed foi migrado.
10. Como a UI Vue foi integrada aos content scripts.
11. Se Shadow Root foi utilizado e por quê.
12. Se main-world injection foi utilizada.
13. Se não foi utilizada, confirmar explicitamente que não foi necessária.
14. Permissões utilizadas.
15. Estratégia de autenticação.
16. Testes realizados.
17. Comandos para desenvolvimento e build.
18. Problemas encontrados.
19. Pendências.
20. Próximos passos necessários para transformar a extensão em uma extensão distribuível ao usuário final.

IMPORTANTE:

Não considere o trabalho concluído apenas porque a extensão compila.

A prioridade é equivalência funcional com os scripts atuais do Violentmonkey.

A extensão deve substituir o mecanismo atual de captura, e não apenas criar um projeto WXT de demonstração.