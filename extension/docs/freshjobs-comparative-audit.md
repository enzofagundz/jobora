# Auditoria Comparativa FreshJobs x Jobora

## Escopo e evidências

Auditoria baseada nos seguintes snapshots:

- Jobora: `HEAD 0b24f18`, extensão em `extension/`.
- FreshJobs: `origin/main`, commit `b84765b`, versão `1.0.3`.
- FreshJobs: [repositório no GitHub](https://github.com/Lucas-Delacroix/fresh-jobs/tree/b84765b84b510deea7fb43e5841f81b7a486569f).

Nenhum código do FreshJobs foi incorporado ao Jobora. O documento histórico `extension/docs/extension-audit.md` foi mantido sem alterações.

Verificações realizadas:

- Jobora: `bun run test`, 64 testes passando.
- Jobora: `bun run compile`, falha com 12 erros de tipagem nos testes.
- FreshJobs: `npm run check`, passando com 71 testes, lint, typecheck, build e `web-ext lint`.
- FreshJobs: `npm audit` encontrou 3 vulnerabilidades altas somente na cadeia de desenvolvimento `web-ext -> addons-linter -> image-size`.
- FreshJobs: `npm audit --omit=dev` não encontrou vulnerabilidades de produção.

## 1. Resumo Executivo

Sim, o FreshJobs possui ideias úteis para o Jobora, mas não existe nele uma solução superior de captura ou deduplicação.

As contribuições mais relevantes são:

1. Observer incremental que identifica quais cards foram afetados, em vez de reprocessar todo o documento.
2. Adapter isolado para seletores, fallbacks e interpretação de variações do LinkedIn.
3. Sanitização de configurações persistidas.
4. Mensageria com validação de payloads.
5. Popup com estados acessíveis, contadores ao vivo e tratamento de erro.

O Jobora já possui soluções mais fortes para:

- Captura e envio ao backend.
- Identidade lógica de uma captura.
- Deduplicação server-side.
- Concorrência entre abas.
- Isolamento do token.
- Fluxo serial do Indeed.
- Integração com triagem e processamento posterior.

O FreshJobs não deve ser incorporado como arquitetura inteira. A recomendação é aproveitar alguns padrões técnicos, principalmente depois de corrigir fragilidades existentes no Jobora.

As prioridades reais são:

- Corrigir claims que ficam presos após saída de rota ou invalidação.
- Corrigir a validação do token quando a captura está desligada.
- Impedir que o Indeed capture descrição antiga do painel lateral.
- Alinhar a identidade calculada no cliente e no backend.
- Considerar processamento incremental para reduzir rescans.

## 2. Principais Descobertas

### Estado atual do Jobora

| Área | Implementação atual | Estado |
|---|---|---|
| Estrutura | `api/`, `components/`, `entrypoints/`, `sites/`, `types/`, `utils/`, `tests/` | Bem separada |
| Entrypoints | Background, `linkedin.content`, `indeed.content` e popup | Funcional |
| LinkedIn | Captura apenas `/search/results/content/*` | Funcional para posts |
| LinkedIn Jobs | Não há adapter nem captura para `/jobs/*` | Ausente no código atual |
| Indeed | Captura cards `a[data-jk]` em `/jobs*` | Funcional, mas dependente do painel lateral |
| Busca | A extensão não inicia buscas; usa a URL atualmente aberta | Parcial |
| Background | Recebe `capture:submit`, lê token e chama API | Adequado |
| Popup | Configura URL, token e ativação | Funcional, com bug na validação |
| Vue | `CapturePanel.vue` e popup `App.vue` | Adequado |
| Stores/composables | Não existem stores ou composables dedicados | Simples, suficiente para o escopo atual |
| Persistência | URL, token e flag em `storage.local`; último status em `storage.session` | Adequada |
| Captura | Extração separada por site | Adequada |
| Deduplicação local | Identidade por ID, URL ou fingerprint | Forte |
| Deduplicação definitiva | Backend com índices únicos por usuário | Mais forte que FreshJobs |
| Batch | Processamento serial e rescan enfileirado | Correto, mas pode ser lento |
| DOM | `MutationObserver` no `document.body`, debounce de 400 ms | Funcional, pouco eficiente |
| SPA | Evento oficial `wxt:locationchange` | Melhor que polling |
| Infinite scroll | Detectado indiretamente por mutações no DOM | Funcional |
| Retry | Não existe retry automático | Lacuna deliberada |
| Erros | Classificados na API e exibidos por contadores/status | Parcial |
| Feedback | Painel Shadow Root com contadores e popup com último status | Adequado |
| Notificações | Não há notificação de navegador para captura; triagem gera notificação no backend | Fora da extensão |
| Triagem | A extensão persiste `JobCapture`; triagem posterior é disparada pelo painel Jobora | Integração indireta correta |

### Fragilidades do Jobora

- `validateCaptureToken()` usa `isCaptureReady()`, que exige `captureEnabled === true`. Como o popup não ativa captura ao salvar, um token válido pode ser apresentado como inválido em `api/captures.ts:82-95` e `App.vue:74-89`.
- Claims feitos antes de um `await` podem permanecer em `claimed` quando a rota é abandonada. Como a sessão é preservada na reentrada, a captura pode nunca ser reenviada. Referências: `capture-session.ts:53-74` e os controllers em `sites/*/controller.ts`.
- `waitForPane()` do Indeed considera suficiente existir uma descrição e `vjk` coincidir. Uma descrição antiga pode permanecer no DOM enquanto o novo painel ainda carrega.
- O fingerprint do cliente inclui `author_name`, mas o backend o exclui. O backend também remove o sufixo `... mais` ou `… mais`, enquanto o cliente não.
- Qualquer mutação relevante dispara uma varredura global.
- O LinkedIn reextrai cards já finalizados antes de descobrir que a identidade já foi processada.
- A documentação ainda orienta o uso de `VAGAS_API_TOKEN`, embora o fluxo atual use credencial individual por usuário.
- `docs/extension-audit.md` contém trechos antigos sobre deduplicação, autenticação e posicionamento da UI.
- Não há typecheck passando, apesar dos testes de runtime passarem.

## 3. O Que o FreshJobs Faz Melhor

| Área | Diferença concreta |
|---|---|
| Observer | `src/content/observer.ts` mantém `pendingCards` e envia ao engine apenas os cards afetados |
| Debounce | Usa 120 ms em vez de 400 ms |
| Mutations | Observa `characterData`, `class`, `aria-label`, `title`, `datetime`, `data-viewed` e `data-jobstate` |
| Adapter | Centraliza seletores, identificação da raiz do card e regras específicas do LinkedIn |
| Fallbacks | Usa múltiplos atributos semânticos, atributos de dados e classes alternativas |
| Texto | Normaliza acentos, caixa e espaços antes de comparar labels |
| Configuração | `sanitizeSettings()` corrige tipos inválidos e valores desconhecidos |
| Escritas | `updateSettings()` serializa alterações concorrentes no storage |
| Mensagens | Valida mensagens e estatísticas recebidas antes de utilizá-las |
| UX | Usa `aria-pressed`, `aria-live`, `role="status"`, `role="alert"` e estado ocupado |
| Internacionalização | Possui inglês e português brasileiro |
| Distribuição | Executa lint, typecheck, testes, build e validação Firefox em `npm run check` |

Essas vantagens precisam ser contextualizadas:

- O observer do FreshJobs ainda recalcula as estatísticas com uma busca global em `updateStats()`.
- Os seletores incluem diversas classes CSS suscetíveis a mudanças.
- Não há teste real em uma página do LinkedIn.
- O FreshJobs não captura dados, não faz requests e não possui identidade de vaga.
- O debounce menor só é vantajoso porque a operação é local e idempotente.

## 4. O Que o Jobora Já Faz Melhor

- Possui backend como fonte de verdade.
- Deduplica por usuário e por fonte.
- Trata concorrência real no banco.
- Usa `external_id`, URL canônica e fingerprint.
- Reivindica a identidade antes do clique ou request.
- Isola credenciais no background.
- Não envia o token em mensagens para content scripts.
- Usa credenciais individuais revogáveis.
- Aplica rate limiting no backend.
- Mantém processamento serial do Indeed para evitar associação incorreta entre card e painel.
- Usa o evento oficial `wxt:locationchange`, enquanto o FreshJobs faz polling a cada 750 ms.
- Possui UI injetada com Shadow Root.
- Captura tanto LinkedIn Content/Posts quanto Indeed.
- Possui testes específicos para lifecycle, concorrência, painel lateral e regressão de múltiplos cards.
- Integra as capturas ao fluxo de triagem do Jobora.

O Jobora não deve substituir esse núcleo por `Set<HTMLElement>`, `data-*` ou classes CSS do FreshJobs.

### Classificação das Descobertas

As categorias seguem a classificação solicitada. A categoria C também é usada para melhorias técnicas recomendadas pela auditoria quando a solução não existe literalmente no FreshJobs, mas o padrão observado ou o problema identificado pode melhorar o Jobora.

| Descoberta | Categoria | Justificativa |
|---|---|---|
| WXT, content scripts separados, background e Shadow Root | A | Já existe adequadamente no Jobora |
| Identidade lógica e deduplicação server-side | A | Jobora possui solução mais completa |
| Processamento serial do Indeed | A | É necessário para o painel compartilhado |
| Observer incremental com `pendingCards` | B | Jobora já observa mutações, mas o FreshJobs limita melhor o processamento |
| Fallbacks organizados em adapter | C | Pode melhorar a manutenção dos adapters do Jobora |
| Normalização de labels multilíngues | C | Técnica aplicável a classificações de texto, com adaptação por site |
| Sanitização de configurações e fila de escrita | C | Pode fortalecer storage e popup do Jobora |
| Mensagens com guards de schema | C | Pode complementar a validação atual do background |
| Filtros de recência e status visual | D | Feature de produto sem relação direta com captura |
| Modo Fresh | F | Interessante somente como possível feature futura |
| Processamento exclusivamente local | E | Incompatível com API, triagem e persistência do Jobora |
| Deduplicação baseada somente em DOM | E | Não resolve identidade ou concorrência de capturas |
| Seletores de LinkedIn Jobs aplicados a posts ou Indeed | E | Dependem de outro contexto de página |
| Claims presos após cancelamento | C | Fragilidade do Jobora descoberta durante a comparação |
| Validação de token bloqueada pela flag de captura | C | Bug de fluxo do Jobora, sem equivalente no FreshJobs |
| Painel Indeed stale | C | Risco de integridade de dados do Jobora |

## 5. Melhorias Técnicas Recomendadas

### 1. Liberar claims cancelados

- **Problema:** a identidade fica em estado pendente quando a rota muda durante `sendMessage()` ou `waitForPane()`.
- **Solução recomendada:** distinguir estados `pending`, `settled` e `cancelled`; liberar somente claims cancelados. Sucesso, duplicata e erro terminal continuam definitivos.
- **Impacto:** evita perda silenciosa de capturas após navegação SPA.
- **Complexidade:** Média.
- **Prioridade:** Alta.

### 2. Separar validação de credencial do gate de captura

- **Problema:** salvar um token com a captura desligada retorna `auth`, aparentando token inválido.
- **Solução recomendada:** a validação deve exigir apenas token configurado e URL válida. O gate `captureEnabled` deve proteger apenas envio e captura.
- **Impacto:** corrige o fluxo de autenticação do popup sem alterar o comportamento de ativação.
- **Complexidade:** Baixa.
- **Prioridade:** Alta.

### 3. Blindar associação do painel do Indeed

- **Problema:** `waitForPane()` pode aceitar descrição anterior com o novo `vjk`.
- **Solução recomendada:** exigir confirmação do novo título, novo estado do painel e associação consistente entre `jk`, URL, título e descrição. O painel anterior não deve ser considerado válido.
- **Impacto:** evita persistir descrição de outra vaga.
- **Complexidade:** Média.
- **Prioridade:** Alta.

### 4. Alinhar identidade cliente/backend

- **Problema:** cliente e backend usam prefixos, campos e normalizações diferentes.
- **Solução recomendada:** definir vetores de identidade compartilhados e testes de paridade. O formato textual da chave local não precisa ser igual ao formato do banco, mas as regras de equivalência precisam ser.
- **Impacto:** reduz requests redundantes e protege contra futuras divergências.
- **Complexidade:** Média.
- **Prioridade:** Média.

### 5. Adotar observer incremental

- **Origem:** padrão de `LinkedInDomObserver` do FreshJobs em `src/content/observer.ts:6-72`.
- **Problema:** o Jobora faz rescan completo após qualquer mutação.
- **Solução recomendada:** mapear cada mutação para o card ou post afetado e processar apenas esse conjunto.
- **Adaptação necessária:** no Indeed, o painel lateral fica fora do card; será necessário manter uma referência ao card ativo ou um evento específico do painel.
- **Impacto:** reduz consultas, extração repetida e custo em páginas longas.
- **Complexidade:** Média.
- **Prioridade:** Média.

### 6. Fortalecer adapters e fixtures

- **Origem:** organização de `linkedin-adapter.ts` e testes do FreshJobs.
- **Problema:** os seletores do Jobora ainda são próximos dos userscripts originais e não possuem fallback organizado por contexto.
- **Solução recomendada:** manter seletores agrupados dentro de cada adapter, priorizar atributos semânticos e adicionar fixtures anonimizadas para variações reais.
- **Impacto:** menor fragilidade perante mudanças de markup.
- **Complexidade:** Média.
- **Prioridade:** Média.

### 7. Tornar o typecheck um gate real

- **Problema:** `bun run test` passa, mas `bun run compile` falha em 12 erros de tipagem.
- **Solução recomendada:** corrigir as declarações de `window.happyDOM`, acessos possivelmente indefinidos e a configuração dos testes.
- **Impacto:** evita regressões que o runner de testes não detecta.
- **Complexidade:** Baixa.
- **Prioridade:** Alta antes de distribuição.

### Retry

O FreshJobs não possui rede e, portanto, não oferece uma estratégia de retry útil para o Jobora. Não recomendo adicionar retry automático indiscriminado agora. Se necessário no futuro, ele deve ser limitado a falhas transitórias, ter backoff e usar a identidade da captura como chave de idempotência.

## 6. Melhorias de UX

O FreshJobs apresenta uma UX de popup mais madura:

- Estado explícito de filtros ativos e inativos.
- Contadores `shown`, `hidden` e `total`.
- Feedback acessível.
- Bloqueio temporário dos controles durante gravação.
- Mensagem de erro recuperável.
- Interface localizada.

Aplicações de baixo risco para o Jobora:

- Renderizar `phase` e `lastOutcome`, que já existem em `CaptureSession`, mas ainda não aparecem no painel.
- Diferenciar “ativo”, “aguardando”, “enviando” e “erro”.
- Usar `aria-live` para mudanças relevantes dos contadores.
- Desabilitar temporariamente o botão Salvar durante a validação.
- Tratar falhas de `storage.setValue()` no popup.
- Corrigir a mensagem de autenticação para não confundir captura desligada com token inválido.

Não recomendo remover o painel in-page do Jobora. Para uma extensão que captura e envia dados, o feedback contínuo na página é mais apropriado que depender exclusivamente do popup.

## 7. O Que Não Vale A Pena Implementar

- Filtros de promovidas, repostadas, visualizadas e já candidatadas: são comportamento de produto do FreshJobs, não melhoria de captura.
- Modo Fresh: adicionaria nova feature e nova semântica ao Jobora.
- Alteração automática de `f_TPR`: modifica a busca do usuário e pode interferir nos contextos suportados.
- Popup somente com contadores: reduziria o feedback disponível durante a captura.
- Processamento local sem backend: incompatível com triagem, persistência, autenticação e deduplicação do Jobora.
- `Set<HTMLElement>` como deduplicação: não resolve re-render, troca de aba ou concorrência.
- Seletores de LinkedIn Jobs aplicados diretamente ao LinkedIn Content/Posts.
- Uso do adapter de LinkedIn Jobs para Indeed.
- Polling de URL do FreshJobs como substituto do `wxt:locationchange`.
- Migração do WXT para o build manual com esbuild do FreshJobs.
- Retry automático indiscriminado.

## 8. Potenciais Features Futuras

Classificação F, sem implementação agora:

- Suporte separado a LinkedIn Jobs, caso o produto realmente precise capturar esse contexto.
- Resumo por aba ou histórico de capturas no popup.
- Interface multilíngue da extensão.
- Retry manual ou controlado para falhas transitórias.
- Filtros opcionais de captura por contexto, desde que não sejam confundidos com os filtros visuais do FreshJobs.

## 9. Deduplicação

| Aspecto | FreshJobs | Jobora |
|---|---|---|
| ID lógico da vaga | Não extrai nem persiste | `external_id`, URL ou fingerprint |
| Identificação de card | Atributos, URLs e classes | Seletores específicos por fonte |
| Deduplicação DOM | `Set<HTMLElement>` por lote | Claim por identidade lógica |
| Re-render | Reprocessa o novo elemento | Bloqueia pela identidade |
| Card removido e recriado | Processa novamente, sem efeito colateral | Não envia novamente se identidade estiver settled |
| Infinite scroll | Processa cards adicionados | Rescan global, mas bloqueia envio duplicado |
| Persistência | Apenas configurações | Backend persiste captura e deduplicação |
| Concorrência entre abas | Não aplicável | Banco garante idempotência |
| Retry | Não aplicável | Não existe retry automático |
| Fonte da verdade | Estado visual do DOM | Backend Jobora |

### Respostas ao problema histórico

1. O FreshJobs usa atributos e URLs para encontrar cards, mas não transforma esses dados em uma identidade persistente.
2. Ele evita efeitos repetidos porque a operação é apenas alternar uma classe CSS; não evita requests.
3. Cards que desaparecem e reaparecem são processados novamente.
4. Infinite scroll é tratado por `MutationObserver` e cards adicionados.
5. Alterações no DOM são agrupadas em `pendingCards` e processadas depois de 120 ms.
6. Um elemento novo é diferenciado somente pela referência DOM no conjunto temporário; não existe `seen set` lógico.

Conclusão: o FreshJobs não possui uma solução melhor para deduplicação.

A estratégia do Jobora é conceitualmente superior porque:

- Não depende da referência do elemento DOM.
- Funciona quando o card é recriado.
- Funciona entre abas.
- Funciona depois de reload, graças ao backend.
- Trata corridas de criação no banco.
- Diferencia captura nova de captura já existente.

O problema histórico atual não é a escolha de identidade. É o lifecycle dos claims pendentes e a divergência entre cliente e backend.

## 10. Performance

| Área | Jobora | FreshJobs | Melhor abordagem |
|---|---|---|---|
| Observer | `document.body`, 400 ms | `document.body`, 120 ms | Observer incremental do FreshJobs |
| Escopo do processamento | Documento inteiro | Cards afetados | FreshJobs, com adaptação |
| Estatísticas | Contadores acumulados em memória | Recontagem global de cards | Jobora para captura; FreshJobs para filtros |
| Extração | Pode repetir cards settled no LinkedIn | Reprocessamento local idempotente | Evitar extração de identidades settled |
| Requests | Um request por identidade reivindicada | Nenhum | Jobora precisa manter background e backend |
| Indeed | Sequencial, polling de até 12 s por card | Não suporta Indeed | Jobora, mas precisa validar painel stale |
| Memória | Sets de identidades crescem durante a sessão | Marcadores nos cards e pending set temporário | Jobora deve medir sessões longas |
| Navegação SPA | Evento WXT | Polling de 750 ms | Jobora |
| Atualização da UI | Reativa no painel | Mensagens ao popup | Depende do caso |

O maior ganho provável está em evitar rescans globais. Porém, a implementação não deve ser copiada literalmente, pois o Indeed possui estado compartilhado no painel lateral.

## 11. Segurança e Privacidade

| Área | Jobora | FreshJobs |
|---|---|---|
| Dados enviados | Descrição, URLs, dados de empresa e autor | Nenhum dado externo |
| Backend | Presente | Ausente |
| Token | Armazenado no storage da extensão e usado pelo background | Não existe |
| Token em mensagens | Não é enviado ao content script | Não aplicável |
| Autenticação | Token individual, hash no backend, revogação | Não aplicável |
| Permissões | `storage`, LinkedIn, Indeed e hosts da API | `storage` e LinkedIn |
| Main world | Não utilizado | Não utilizado |
| Código remoto | Não utilizado | Não utilizado |
| HTML dinâmico | UI usa Vue e Shadow Root | UI usa `createElement` e `textContent` |
| Telemetria | Não identificada na extensão | Não possui analytics ou telemetria |
| CORS | Backend permite origens amplas sem cookies | Não faz requests |
| Mensagens | Background valida tipo, mas não schema completo | Guards de estatísticas mais completos |

Recomendação adicional para o Jobora: validar o formato completo do payload no background, além de validar somente `message.type` e `typeof payload`.

### Licença

O FreshJobs declara MIT em `LICENSE`.

- Ideias e padrões podem ser reimplementados independentemente.
- Código copiado exigiria manter o aviso de copyright e a licença MIT.
- Assets, como logo e ícones, não devem ser incorporados automaticamente.
- Nenhum código ou asset do FreshJobs foi incorporado nesta auditoria.

### Cadeia de desenvolvimento

No snapshot auditado, `npm audit` identificou vulnerabilidades altas em dependências usadas pelo `web-ext`, especificamente `addons-linter` e `image-size`. O problema está na ferramenta de desenvolvimento e empacotamento, não no código distribuído da extensão. Deve ser reavaliado antes de uma publicação.

## 12. Testes

| Projeto | Cobertura observada |
|---|---|
| Jobora | 64 testes passando em 9 arquivos |
| Jobora | Extração LinkedIn, extração Indeed, API, messaging, identidade, sessão, lifecycle e regressão de múltiplos cards |
| Jobora | Sem E2E real contra LinkedIn ou Indeed |
| Jobora | Fixtures são sintéticas |
| Jobora | Typecheck falha em 12 erros nos testes |
| FreshJobs | 71 testes passando em 8 arquivos |
| FreshJobs | Adapter, labels, parsing de idade, filtros, observer, mensagens, storage e manifests |
| FreshJobs | `npm run check` passa integralmente |
| FreshJobs | Sem teste de captura, deduplicação ou concorrência, pois não possui essas responsabilidades |
| FreshJobs | Observer tem cobertura limitada, principalmente uma alteração de atributo |

Testes recomendados para a próxima etapa de estabilização:

- Reentrada após cancelamento durante request.
- Reentrada após cancelamento durante `waitForPane()`.
- Validação de token com captura desligada.
- Painel Indeed antigo permanecendo no DOM.
- Paridade de fingerprints entre TypeScript e PHP.
- Teste do handler real de background, em vez de duplicar sua lógica dentro de `messaging.test.ts`.
- Fixtures anonimizadas mais próximas do markup real.

Não é necessário criar uma suíte ampla nova agora.

## 13. Arquitetura

| Aspecto | Jobora | FreshJobs | Avaliação |
|---|---|---|---|
| Runtime | WXT, Vue, TypeScript | TypeScript, esbuild manual | WXT é melhor para o Jobora |
| Content scripts | Um por site | Um content script com adapter | Jobora separa melhor fontes |
| Background | Necessário para token e API | Não existe | Ausência é adequada ao FreshJobs |
| LinkedIn | Content/Posts | Jobs | Contextos não intercambiáveis |
| Indeed | Suportado | Não suportado | Jobora |
| DOM | Rescan global | Cards pendentes | FreshJobs |
| SPA | Evento WXT | Polling e eventos de histórico | Jobora |
| Estado | Sessão lógica de captura | Estado visual de filtros | Jobora para captura |
| Persistência | Storage e backend | Storage local de settings | Cada um adequado ao propósito |
| UI | Popup e painel in-page | Popup apenas | Jobora oferece feedback melhor para captura |
| Segurança | Token isolado no background | Sem dados sensíveis | Incomparável por escopo |
| Build | WXT gera Chrome/Firefox | Manifestos manuais por browser | Não há motivo para migrar |
| Manutenção | Seletores isolados por site | Adapter único para LinkedIn Jobs | FreshJobs possui fallback mais sistematizado |

A melhor arquitetura para o Jobora é manter:

- WXT.
- Background.
- Content scripts separados por fonte e contexto.
- Adapters específicos.
- Sessão lógica de captura.
- Backend como fonte definitiva de deduplicação.

### Matriz Comparativa

| Área | Jobora | FreshJobs | Melhor abordagem | Justificativa |
|---|---|---|---|---|
| Arquitetura da extensão | WXT, Vue, background e múltiplos content scripts | esbuild manual, content script e popup | Jobora | O Jobora precisa proteger token, falar com API e suportar múltiplos contextos |
| Content scripts | Um entrypoint para LinkedIn e outro para Indeed | Um entrypoint para LinkedIn Jobs | Jobora | Isola fontes distintas |
| Background/service worker | Faz fetch e mantém o token fora da página | Não possui | Jobora | Necessário para autenticação e API |
| Comunicação | Content -> background; popup usa storage | Content <-> popup para estatísticas | Jobora para captura | O canal atual protege a credencial |
| Manipulação do DOM | Extração e expansão por site | Filtragem local por adapter | Depende do contexto | As operações têm objetivos diferentes |
| MutationObserver | Rescan global após debounce de 400 ms | `pendingCards`, debounce de 120 ms | FreshJobs, adaptado | Processar somente cards afetados é mais eficiente |
| Infinite scroll | Funciona via rescan global e identidade lógica | Funciona via nodes adicionados | FreshJobs para performance; Jobora para integridade | O Jobora preserva identidade e envio ao backend |
| Identificação de elementos | `[role="listitem"]` e `a[data-jk]` | Lista extensa de selectors e `closestCardRoot` | Adapter do FreshJobs como padrão | Fallbacks são mais sistemáticos, mas os valores são específicos de Jobs |
| Identificação de vaga | URN do LinkedIn, `data-jk`, URL e fingerprint | Não existe identidade persistente | Jobora | FreshJobs só precisa classificar cards |
| Deduplicação | Claims locais e unique server-side | Não aplicável | Jobora | É a única abordagem adequada a requests |
| Controle de estado | Sessão reativa, lifecycle generation e counters | Estado de filtros e estatísticas | Jobora para captura | O Jobora possui efeitos assíncronos e concorrentes |
| Persistência local | Token, URL, ativação e último status | Configurações de filtros | FreshJobs para sanitização; Jobora para escopo | As necessidades são diferentes |
| Popup | Configuração, credencial e último resultado | Controles compactos e contadores ao vivo | FreshJobs para UX; Jobora para função | Não trocar configuração de autenticação por filtros |
| Feedback visual | Painel in-page e popup | Popup apenas e resumo de cards | Jobora para captura | Captura exige feedback contínuo |
| Filtros | Não possui filtros visuais de vagas | Recência e status do LinkedIn | FreshJobs | Feature específica, não melhoria técnica da captura |
| Performance | Requests seriais e rescans globais | Operações locais e incrementais | FreshJobs no DOM; Jobora no backend | Não eliminar o backend para ganhar performance local |
| Concorrência | Serial por controller e concorrência segura no servidor | Sem concorrência de captura | Jobora | FreshJobs não enfrenta o mesmo problema |
| Retry | Ausente | Não aplicável | Nenhuma | Não existe solução externa para copiar |
| Erros | Classificação de API e lifecycle | Erro principalmente de storage | Jobora como base | Precisa apenas corrigir estados terminais e feedback |
| Compatibilidade LinkedIn | Route guard específico para Content/Posts | Fallbacks para Jobs | Separação por contexto | Não usar selectors de Jobs em Posts |
| Compatibilidade Indeed | Suportado com painel lateral | Não suportado | Jobora | FreshJobs não oferece referência aplicável diretamente |
| Segurança | Token isolado, host allowlist e backend por usuário | Sem rede e sem credenciais | Depende do objetivo | FreshJobs é mais restrito porque não captura nem transmite |
| Testes | Fluxos de captura e concorrência, mas typecheck falha | 71 testes e `check` completo | Combinação | Jobora precisa recuperar o typecheck e adotar guards/fixtures úteis |
| Manutenção | Adapters separados, fallbacks limitados | Adapter centralizado e documentado | Combinação | Preservar separação do Jobora e melhorar disciplina dos adapters |

### Aplicabilidade ao Indeed

| Solução do FreshJobs | Aplicabilidade ao Indeed | Motivo |
|---|---|---|
| `pendingCards` e processamento incremental | Aplicável com adaptação | O painel lateral fica fora do card e exige referência ao item ativo |
| `findCardFromNode()` | Aplicável com adaptação | Deve também reconhecer mutações no painel compartilhado |
| Lista de selectors de LinkedIn Jobs | Não aplicável | O Indeed possui markup e fluxo próprios |
| Normalização de labels | Aplicável diretamente como técnica | Pode ser útil para estados e textos localizados |
| `f_TPR` | Não aplicável | É parâmetro específico do LinkedIn |
| Filtros promovida/repostada/visualizada | Não aplicável diretamente | São filtros visuais do produto FreshJobs |
| Storage sanitizado | Aplicável diretamente | Não depende do site |
| Popup com busy state e `aria-live` | Aplicável diretamente | Melhora a UX sem alterar o produto |
| Processamento local sem backend | Não aplicável | O fluxo do Jobora depende da API e da triagem |

### O Que Deve Permanecer Como Está

- O uso de WXT e Vue.
- O background como único local de acesso ao token e à API.
- A separação entre LinkedIn Content/Posts e Indeed.
- O uso do evento `wxt:locationchange`.
- O Shadow Root para o painel in-page.
- O processamento serial do Indeed.
- A identidade lógica independente da referência DOM.
- A deduplicação definitiva no backend.
- O isolamento por usuário e a revogação de credenciais.
- A ausência de main-world injection.
- A política de permissões restritas.

## 14. Matriz de Recomendações

| Recomendação | Origem | Benefício | Complexidade | Risco | Prioridade | Implementar agora? |
|---|---|---|---|---|---|---|
| Liberar claims cancelados | Jobora, descoberta da auditoria | Evita perda de captura após navegação | Média | Médio | Alta | Não nesta tarefa |
| Separar validação de token do gate de captura | Jobora | Corrige autenticação do popup | Baixa | Baixo | Alta | Não nesta tarefa |
| Validar painel Indeed contra conteúdo stale | Jobora | Evita salvar descrição errada | Média | Médio | Alta | Não nesta tarefa |
| Corrigir typecheck dos testes | Jobora | Fortalece o gate de qualidade | Baixa | Baixo | Alta | Não nesta tarefa |
| Alinhar identidade cliente/backend | Jobora/backend | Reduz requests redundantes e drift | Média | Médio | Média | Não nesta tarefa |
| Observer incremental por card | FreshJobs adaptado | Reduz custo de DOM e extração | Média | Médio | Média | Não nesta tarefa |
| Fallbacks e fixtures por adapter | FreshJobs adaptado | Aumenta resistência a mudanças do LinkedIn | Média | Médio | Média | Não nesta tarefa |
| Sanitização de storage e guards de payload | FreshJobs adaptado | Melhora robustez e segurança | Baixa | Baixo | Baixa | Não nesta tarefa |
| Filtros de recência e modo Fresh | FreshJobs | Feature sem relação direta com captura | Alta | Alto | Não implementar | Não |
| Substituir backend por processamento local | FreshJobs | Incompatível com o Jobora | Alta | Alto | Não implementar | Não |

## 15. Conclusão

| Pergunta | Resposta |
|---|---|
| O FreshJobs possui técnica que deveria ser incorporada? | Sim, observer incremental e disciplina de adapter |
| Há solução melhor de deduplicação? | Não. O Jobora é superior, mas possui problema de claims cancelados |
| Há solução melhor para observar LinkedIn? | Sim, processamento de cards afetados; manter `wxt:locationchange` |
| Há solução melhor para infinite scroll? | O padrão incremental do FreshJobs é melhor; o Jobora já funciona |
| Há melhoria de performance relevante? | Sim, evitar rescans globais |
| Há melhoria de UX relevante? | Sim, estados acessíveis, busy state e feedback mais claro |
| Há melhoria de arquitetura relevante? | Apenas refinamentos; não substituir a arquitetura atual |
| Há melhoria de testes relevante? | Sim, typecheck, paridade de identidade e cenários de cancelamento |
| O que não vale copiar? | Filtros de produto, processamento local, `Set<HTMLElement>` e selectors específicos de Jobs |
| O que deve permanecer no Jobora? | Background, WXT, Shadow Root, backend e deduplicação server-side |

As 5 melhorias técnicas mais justificáveis para a estabilização do Jobora são:

1. Corrigir o lifecycle de claims cancelados.
2. Separar validação de credencial da ativação da captura.
3. Blindar a associação entre card e painel do Indeed.
4. Alinhar a identidade do cliente com o backend.
5. Adotar processamento incremental de mutações, mantendo adapters separados para LinkedIn Content, LinkedIn Jobs e Indeed.

Nenhuma dessas mudanças foi implementada nesta auditoria.
