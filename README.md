# Jobora

Aplicação Laravel/Filament para descoberta de oportunidades, perfil profissional confirmado, matching explicável, adequação de currículo e acompanhamento de candidaturas.

## Fluxo V2

Fontes e execuções de descoberta organizam a coleta. A [extensão deste monorepo](extension/README.md) envia envelopes para `/api/page-captures` com credencial individual do proprietário, obtida no setup de captura do painel. A triagem de publicações é solicitada explicitamente; oportunidades revisadas e perfil confirmado alimentam o matching versionado. Currículos PT-BR/EN, competências estratégicas, adequação e ATS permanecem disponíveis.

O painel oferece consultas somente leitura, limitadas ao proprietário:

- Execuções: `filament.admin.pages.discovery-runs`.
- Capturas de página: `filament.admin.resources.page-captures.index`.
- Operações de IA: `filament.admin.pages.ai-operations`.

Administração exige Gate explícito; autoelevação é bloqueada. Mercado fica fora da navegação principal e do catálogo MCP, mas suas rotas históricas, serviços e normalização compartilhada permanecem. `ready` V2 não equivale a `confirmed` V1.

## Hermes e envio de candidaturas

O MCP remoto HTTP/OAuth já existe, com identidade por proprietário e scopes por integração. Stdio também existe; não é a única forma de acesso. Downloads remotos usam artefatos privados autenticados com hash e TTL, não caminhos locais do servidor.

Preparação, aprovação e reserva não enviam candidatura. Somente o primeiro consumo remoto autenticado, com aprovação vigente e scope `applications:submit` concedido explicitamente, pode autorizar o Hermes a executar externamente. Consulta e replay nunca autorizam. Revogação antes do consumo bloqueia; depois não cancela navegador já autorizado. Resultado `unknown` impede repetição até reconciliação; o proprietário pode reconciliar pela web mesmo com integração revogada. Não há garantia externa de exactly-once. Tracking é separado do envio.

## Transição e pendências

O usuário confirmou migração de todos os clientes e instalações Hermes para V2; userscripts e extensão antiga não estão em uso. `/api/job-captures` está aposentado sem efeito. Captura/triagem/promoção V1 não são aliases da ingestão e triagem V2. Consulte o [contrato vigente](MCP_SPEC.md#contrato-v2-vigente-37) antes de configurar agentes.

Backups **ainda não foram implantados**. O teste de restore em ambiente descartável e o smoke real Hermes de LinkedIn publicações, LinkedIn Jobs e Indeed Jobs, incluindo browser/JS/rede/backend, continuam pendentes em #38. O fechamento de #29 por pedido do usuário não comprova homologação integral.

## Documentação

- [Contexto de domínio](CONTEXT.md).
- [Produto e histórico do MVP](PRD.md).
- [Plano técnico e transição V2](TECHNICAL_PLAN.md).
- [Plano de implementação e evidências](IMPLEMENTATION_PLAN.md).
- [Contrato MCP e histórico V1](MCP_SPEC.md).
- [Currículos por idioma](docs/adr/0001-versoes-de-curriculo-por-idioma.md) e [pré-filtro V1](docs/adr/0002-pre-filtro-clef-na-triagem.md), com supersessão parcial explicitada.
