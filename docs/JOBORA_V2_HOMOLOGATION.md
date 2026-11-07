# Jobora V2 — Evidências de homologação (#38)

## Escopo e estado

Registro das evidências automatizadas e do smoke real supervisionado em 07/11/2026, sobre as bases #36 (`630acfa`) e #37 (`98f6e4d`). Os ensaios automatizados e restores usam dados sintéticos e bancos descartáveis; essa restrição não descreve o smoke externo, que coletou páginas reais e está registrado separadamente abaixo. As correções motivadas pelo smoke estão em validação. Nenhum envio real de candidatura foi autorizado ou executado.

Esta evidência **não significa homologação integral**. Suíte completa, fluxo integrado controlado, upgrade e restore possuem o baseline automatizado registrado abaixo, anterior às correções do smoke. O retorno real do Hermes foi recebido: LinkedIn publicações teve duas páginas com captura completa e ACK correlacionado; LinkedIn Jobs e Indeed Jobs permaneceram bloqueados nos limites descritos adiante. A revalidação dessas superfícies e a implantação dos backups continuam pendentes. O fechamento de #29 ocorreu por pedido humano, sem comprovação integral das três superfícies.

## Isolamento das execuções focadas

- Runtime verificado: PHP 8.5.12, Laravel 13.25.0 e Pest 4.7.8, no container do lerd.
- Configuração PHPUnit exclusiva: `/tmp/opencode/jobora38-operational.xml`, com paths absolutos, `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_URL=` e `DB_DATABASE=/tmp/opencode/jobora38-operational-testing.sqlite`, forçados no processo.
- Storage exclusivo: `JOBORA38_STORAGE_ROOT=/tmp/opencode/jobora38-operational-storage`; disco de teste e chaves OAuth ficam nessa área. O teste restaura o caminho global das chaves ao terminar.
- O teste operacional exige `APP_ENV=testing` antes de permitir `RefreshDatabase`. SQLite só é aceito em arquivo existente, regular, sem symlink, diretamente sob `/tmp/opencode/`, com diretório e arquivo canônicos e basename que corresponda a `\Ajobora38(?:-[a-z0-9]+)*-testing\.sqlite\z`. Aceita `/tmp/opencode/jobora38-operational-testing.sqlite` e `/tmp/opencode/jobora38-final-testing.sqlite`; rejeita `:memory:`, outros diretórios, paths com traversal e o SQLite compartilhado padrão. Essas verificações ocorrem antes de abrir o PDO; depois confere driver PDO e arquivo SQLite efetivo antes de migrar.
- No teste operacional, PostgreSQL é recusado por padrão. A única exceção exige simultaneamente driver `pgsql`, banco configurado e efetivo `jobora_t22_20261107_testing` e opt-in explícito `JOBORA_OPERATIONAL_PG_DATABASE=jobora_t22_20261107_testing`. O opt-in de upgrade descrito abaixo não concede essa permissão. A execução posterior com `/tmp/opencode/jobora38-operational-pg.xml` e storage `/tmp/opencode/jobora38-operational-parent-pg-storage` passou com **9 testes, 105 assertions e saída 0**.
- Fila `database`, armazenamento de falhas e batches são vinculados explicitamente à conexão descartável validada. A fila usa a tabela `jobs` e o nome `default`, sem herdar outra conexão do ambiente.
- Por padrão, o teste de upgrade usa outra conexão SQLite, arquivo aleatório e storage próprios. Após o upgrade, cria um segundo arquivo exclusivo usando a API nativa `SQLite3::backup`, abre a cópia por outra conexão e verifica integridade, referências, dados e ownership. Remove somente os dois arquivos que criou ao terminar. Sem opt-in, inclusive em uma suíte externa PostgreSQL, esse teste continua sendo uma evidência SQLite.
- O opt-in `JOBORA_UPGRADE_PG_DATABASE=jobora_t24_upgrade_20261107_testing` permite repetir exatamente o mesmo dataset, a cadeia V1→V2 e as verificações de preservação em PostgreSQL real. Exige `APP_ENV=testing`, configuração com driver `pgsql` e esse nome exato; usa conexão dedicada copiada de `database.connections.pgsql`, com `database` substituído e `url=null`. Antes de qualquer migration, confere driver PDO, banco configurado, `current_database()` e ausência de tabelas preexistentes em todos os schemas de usuário. O banco deve ser criado vazio pela sessão principal. O teste não usa `fresh`, não apaga tabelas e não exclui o banco ou o dataset PostgreSQL ao terminar; preserva-os para export/restore, inclusive em caso de falha parcial. O storage permanece exclusivo.
- As configurações e os logs em `/tmp/opencode` são efêmeros. Para reproduzir, recrie a configuração com essas variáveis forçadas, paths absolutos e apenas os dois arquivos de teste. Crie o arquivo SQLite dentro do container antes de executar; um arquivo com o mesmo nome no host não comprova sua existência no container.

## Comandos e resultados focados

```bash
lerd php vendor/bin/pest --configuration=/tmp/opencode/jobora38-operational.xml tests/Feature/JoboraV2LegacyUpgradeTest.php --compact
lerd php vendor/bin/pest --configuration=/tmp/opencode/jobora38-operational.xml tests/Feature/JoboraV2OperationalReadinessTest.php --compact
lerd php vendor/bin/pest --configuration=/tmp/opencode/jobora38-operational.xml --compact
lerd php vendor/bin/pint --format agent tests/Feature/JoboraV2LegacyUpgradeTest.php tests/Feature/JoboraV2OperationalReadinessTest.php
```

| Recorte | Testes aprovados | Assertions |
| --- | ---: | ---: |
| Upgrade legado e backup/restore SQLite nativo | 1 | 82 |
| Readiness operacional | 9 | 105 |
| Execução conjunta dos mesmos arquivos | 10 | 187 |

A execução conjunta terminou com status de saída `0`. As linhas individuais e a conjunta se sobrepõem: não são coberturas adicionais. Pint passou nos dois arquivos.

Antes da inclusão do restore SQLite, uma tentativa com `lerd php artisan test --configuration=/tmp/opencode/jobora38-operational.xml --compact` reportou 10 testes/161 assertions aprovados, mas o processo terminou com status `1`. Esse comando não é registrado como sucesso operacional; a execução direta pelo Pest confirmou status `0`, inclusive no recorte atual de 10 testes/187 assertions. A discrepância do wrapper permanece para investigação.

Uma sonda negativa com configuração temporária apontando para `/tmp/opencode/jobora38-do-not-open.sqlite` foi recusada antes da abertura do arquivo e antes de migrations, com `Readiness exige APP_ENV=testing e banco descartável exclusivo; RefreshDatabase bloqueado.` O arquivo continuou inexistente. Esse erro esperado verifica a proteção do teste, não representa regressão de produto nem integra os 10 testes aprovados.

Na revisão STANDARDS38, o recorte foi reexecutado após restringir a guarda e usar a factory existente de perfil profissional: **10 testes, 187 assertions, status de saída 0**. Quatro sondas negativas foram recusadas antes de abrir PDO ou executar migrations, todas com status esperado `2`: arquivo fora de `/tmp/opencode/`, symlink com nome permitido, path com traversal e configuração PostgreSQL sem opt-in. O alvo sintético do symlink permaneceu inalterado e somente os dois arquivos criados para essa sonda foram removidos. Essas sondas não são testes funcionais adicionais nem execução de PostgreSQL. Pint foi executado somente com paths explícitos, sem `--dirty`, para não formatar arquivos de outras frentes.

Após adicionar o opt-in PostgreSQL, o recorte SQLite do upgrade passou com **1 teste, 56 assertions, status de saída 0**. Após acrescentar o backup/restore SQLite, o mesmo recorte passou com **1 teste, 82 assertions, status de saída 0**. São execuções sobrepostas, não coberturas somáveis. Pint passou novamente nesse arquivo. Uma configuração temporária com `JOBORA_UPGRADE_PG_DATABASE=jobora_t24_wrong_testing` foi recusada com status `2` e a mensagem `Upgrade PostgreSQL exige driver pgsql e banco exclusivo jobora_t24_upgrade_20261107_testing; nenhuma conexão foi aberta.` Nenhuma execução PostgreSQL foi feita pela frente focada; os resultados PostgreSQL comunicados pela sessão principal estão descritos adiante.

Nos testes de caracterização de preservação e readiness acima, não houve mudança funcional de produto nem demonstração de RED funcional. As execuções iniciais exigiram correções nos próprios testes, incluindo composição do trait, preparação do SQLite no container e expectativas compatíveis com os estados existentes. As regressões e correções posteriores da extensão motivadas pelo smoke têm evidências separadas abaixo.

## Preservação V1 durante o upgrade

O [teste de upgrade](../tests/Feature/JoboraV2LegacyUpgradeTest.php) monta o schema até imediatamente antes de `2026_11_04_200001_create_discovery_domain_tables.php`. Em seguida insere duas contas com:

- uma vaga confirmada por conta, itens e métodos de candidatura;
- currículos e adequações PT-BR/EN por conta;
- candidatura V1 já marcada como enviada e sua movimentação histórica;
- captura promovida, duas capturas Indeed com o mesmo `jk` e tracking diferente, e captura soft-deleted por conta.

Depois aplica o restante da cadeia e executa o backfill duas vezes. Compara todas as colunas originais de nove tabelas, incluindo IDs, conteúdo, ownership, referências e quantidades. O primeiro backfill cria 8 capturas de página, 6 publicações, 8 observações e 2 origens; o segundo não cria registros e informa 8 capturas ignoradas. As variantes Indeed convergem por conta, sem fundir proprietários. As exclusões lógicas e as origens promovidas permanecem vinculadas.

As vagas legadas não recebem `readiness` V2 por equivalência silenciosa. Não são criados perfis confirmados, matching, preparações, artefatos, aprovações ou tentativas de envio retroativas. O status de candidatura V1 preservado não vira autorização para novo envio.

No ramo SQLite, a extensão `SQLite3` e o método `backup` foram verificados no runtime. O teste usa essa API nativa, não copia diretamente um banco aberto nem depende de copiar arquivos WAL. Na segunda conexão, confere `PRAGMA integrity_check = ok`, ausência de violações em `PRAGMA foreign_key_check`, os snapshots das nove tabelas V1 e todas as linhas das quatro tabelas V2 geradas pelo backfill. Também verifica as contagens por proprietário e ausência de aprovações e outros registros retroativos. O ramo PostgreSQL não executa esse backup SQLite e preserva seu dataset.

## Upgrade e restore PostgreSQL — sessão principal

A sessão principal informou a execução real do upgrade V1→V2 no banco exclusivo `jobora_t24_upgrade_20261107_testing`: **1 teste, 56 assertions aprovados**. A repetição no mesmo banco foi recusada por tabelas preexistentes antes de executar migrations; o log ficou em `/tmp/opencode/jobora-t24-nonempty-guard.log`. O resultado de 56 assertions corresponde ao ramo PostgreSQL, que não contém as verificações adicionais de restore SQLite.

O dump de origem `/tmp/opencode/jobora-t24-upgrade.sql` foi importado no banco descartável `jobora_t24_restore_20261107_testing` e reexportado em `/tmp/opencode/jobora-t24-restored.sql`. Exportação e importação usaram a CLI do lerd com `--service postgres` explícito. Foi necessário recorrer à CLI porque a ferramenta MCP de criação havia informado um banco que não existia; a resposta da ferramenta, isoladamente, não foi aceita como prova de criação.

A comparação normalizada preservou schema, dados, sequences e FKs: **51 tabelas, 72 FKs e 32 sequences**. Foram removidos somente comentários iniciados por `--` e linhas `\restrict`/`\unrestrict` com tokens aleatórios. Os CHECKs de enum apresentaram diferenças equivalentes de deparse de casts de `ANY`, como `(ARRAY['x'::varchar])::text[]` e `ARRAY[('x'::varchar)::text]`; a canonicalização manteve, para cada CHECK, o nome da constraint, a coluna e a lista ordenada de valores permitidos. Não houve normalização de bytes nulos. Todo o restante dos dumps permaneceu igual.

O manifesto ficou em `/tmp/opencode/jobora-t24-restore-manifest.json`, com SHA-256 informado pela sessão principal `28af4d2dd0a2668fb82536d948ff811357797c52da3729018c9575a5c16cfa90`. Os dumps e o manifesto são temporários e não são adicionados ao Git. Essa evidência comprova a equivalência delimitada dos dumps; **não comprova startup da aplicação nem execução real de matching após o restore**.

## Retenção, acesso e interrupção operacional

O [teste operacional](../tests/Feature/JoboraV2OperationalReadinessTest.php) verifica interfaces públicas e seus efeitos:

1. `captures:prune-raw` remove o bruto no instante de vencimento e preserva bruto ainda válido e legado sem expiração. `captures:reconcile-orphans` remove órfão com um dia de idade, mantém órfão novo e só limpa metadado sem objeto após o vencimento. Publicações, resultados Clef e de triagem, operação de IA, observações, origens, vagas, candidaturas e movimentações permanecem inalterados.
2. O download HTTP autenticado entrega bytes cujo SHA-256 confere. Link vencido retorna `403`; artefato vencido ou revogado, `410`; integração revogada, `403`; outro proprietário, `404`; ausência de autenticação, `401`. A resposta não oferece caminho local. Negar acesso não remove fisicamente o PDF nem altera o histórico.
3. Desativar a fonte retira sua elegibilidade para novas reservas. Revogar a credencial individual bloqueia tanto replay da captura anterior quanto uma nova ingestão (`401`), sem apagar fonte, captura ou bruto anteriores. O teste não afirma cancelamento de execuções já autorizadas externamente.
4. Uma preparação solicitada por MCP deixa uma operação em fila real `database`. Após revogar a integração, `queue:work --once` encontra `AuthorizationException`, não chama provedor nem cria adaptação, artefato ou tentativa de envio, e preserva movimentações. O comportamento observado é reagendamento: a operação permanece `queued`/`retrying`, com um job e uma tentativa. Não se afirma drenagem completa nem falha terminal imediata.

O cliente HTTP bloqueia chamadas externas não simuladas. O teste de download usa PDF sintético; não valida renderização real na Cloudflare.

Falhas de exclusão de bruto já possuem cobertura em [CaptureRetentionFailureTest](../tests/Feature/CaptureIngestion/CaptureRetentionFailureTest.php). Recuperação de dispatch e fencing já possuem testes em [PublicationOperationRecoveryRegressionTest](../tests/Feature/PublicationOperationRecoveryRegressionTest.php), [PublicationTriageRecoveryTest](../tests/Feature/PublicationTriageRecoveryTest.php) e [MatchingBackendTest](../tests/Feature/MatchingBackendTest.php). Essas referências evitam duplicação; não foram somadas nem reexecutadas no recorte acima. Devem integrar a revisão da suíte completa.

## Backups, restore e limites de retenção

**Backups ainda não foram implantados**, conforme confirmação do usuário. Nenhuma política foi configurada nesta frente. Um ensaio descartável de restore não equivale à implantação de backup.

A [política de retenção](JOBORA_V2_RETENTION.md#backups--requisito-operacional) exige excluir `capture-envelopes/**` das cópias ou expirar suas cópias em até 30 dias. Não se deve restaurar HTML já expirado. Capturas legadas com `raw_expires_at` nulo não expiram automaticamente. Para PDFs, os testes comprovam negação de acesso por TTL/revogação, **não limpeza física automática**. Não há limpeza física automática de PDF conhecida nesta evidência.

O [baseline anterior](JOBORA_V2_BASELINE.md) permanece histórico, inclusive suas limitações e o incidente de isolamento registrado. Esta entrega não reclassifica evidências anteriores como se não existissem.

## Fluxo integrado e baseline automatizado anterior ao smoke real

O [cenário integrado PHP](../tests/Feature/JoboraV2HomologationTest.php) percorre duas contas com dados positivos e três superfícies. Exercita descoberta, captura e replay, worker real, triagem explícita, perfil confirmado, matching, adequação, download com hash, confirmação humana Telegram, aprovação, reserva, consumo único, sucesso e desconhecido controlados, revogação e reconciliação pela sessão proprietária. O tracking permanece independente. A execução focada passou com **1 teste e 389 assertions**.

O [cenário da extensão](../extension/tests/v2-homologation.test.ts) usa controllers e background reais com transporte controlado e [fixture compartilhada](../extension/tests/fixtures/v2-homologation.json). Verifica a execução recebida sem normalizar seu ID, confirmação durável e replay após recriação. São **4 testes**, incluídos nos 213 testes do baseline da extensão. A fixture compartilhada comprova compatibilidade do envelope; não representa transporte real entre a extensão instalada e o servidor durante o teste PHP.

HTTP MCP passa pelo Kernel autenticado da aplicação, não por conexão de rede externa. Providers e PDF são fronteiras simuladas. Storage, chaves Passport e conexões de fila ficam isolados. O worker mantém limite finito de memória, calculado sobre o consumo inicial do processo mais 64 MiB, e exige saída 0 e drenagem dos jobs. Isso corrigiu a falha inicial por limite de memória (`exit 12`), sem alterar limites do produto nem aceitar esse código como sucesso. Os testes antigos da extensão passaram a aguardar estado observável e crypto assíncrono, preservando assertions e cleanup.

```bash
lerd php vendor/bin/pest --configuration=/tmp/opencode/jobora38-final-suite.xml --compact
lerd php vendor/bin/pest --configuration=/tmp/opencode/jobora38-operational-pg.xml tests/Feature/JoboraV2OperationalReadinessTest.php --compact
```

Na pasta `extension`:

```bash
bun run test
bun run compile
bun run build
```

- **Suíte PHP completa:** 1.445 casos, **1.432 aprovados**, 13 ignorados por exigirem PostgreSQL, **11.337 assertions**, saída 0. Configuração temporária derivada de `phpunit.xml`, com bootstrap e diretórios absolutos, variáveis de teste forçadas e SQLite exclusivo `/tmp/opencode/jobora38-final-testing.sqlite`; log `/tmp/opencode/jobora38-final-suite.log`.
- **Concorrência PostgreSQL:** 8 corridas históricas aprovadas, com 28 assertions, em `DiscoveryRunConcurrencyTest`, `DiscoveryConcurrencyTest` e `CaptureIngestionConcurrencyTest`; log `/tmp/opencode/jobora38-pg-historical-concurrency.log`. As 5 corridas de envio também passaram, com 42 assertions. Assim, os 13 casos ignorados em SQLite possuem execução PostgreSQL válida, sem somar execuções sobrepostas à suíte completa.
- **Extensão:** 213 testes aprovados em 29 arquivos, saída 0; typecheck e build passaram. Logs `/tmp/opencode/jobora38-extension-final.log` e `/tmp/opencode/jobora38-extension-build.log`.
- **Formatação e revisão:** Pint passou. Revisões independentes de padrões e spec concluídas; achados de isolamento, vínculo da execução e sincronização foram corrigidos e revalidados.
- **Upgrade e restore:** resultados delimitados registrados acima. O banco PostgreSQL de origem contém dados; a guarda deve recusar nova execução, não limpar o banco. Restore SQLite nativo inclui integridade, FKs e preservação. Nenhum desses ensaios implanta backups ou autoriza restaurar HTML expirado.
- **Navegador:** telas de execuções, operações e capturas responderam 200. Inspeções somente leitura foram exercitadas em desktop 1479×758 e mobile 390×844, sem JavaScript inesperado. Lentes do backend foram consultadas; repetições de eager loading entre resolução da ação, tabela e detalhe foram classificadas como limitadas, não como loop por registro. Essa verificação não comprova todos os caminhos da interface de candidatura nem o smoke externo.

Os números desta seção descrevem o baseline anterior ao smoke após `ba8ec7f`. Os resultados das correções motivadas pelo smoke estão registrados em seção própria abaixo; não são somados ao baseline como coberturas independentes.

## Smoke real Hermes de 07/11/2026 — resultado parcial

As horas desta seção estão em **UTC**, salvo indicação contrária; BRT corresponde a UTC−3. Os registros abaixo combinam o relatório do Hermes com a correlação de banco feita em modo somente leitura pela sessão principal. O banco confirmou os identificadores e o proprietário `4`, sem que essa consulta executasse novas coletas ou alterasse agendas.

### LinkedIn publicações: duas páginas completas

- **Fonte 1, execução 2, captura 14:** `execution_id=901563b9-c9da-4dff-b0e3-d797d620a777`. Reserva em **17:13:04 UTC**, captura em **17:13:35 UTC** e ACK observado no relatório às **17:13:41 UTC**, antes da navegação. Cobertura `complete`, **36/36 blocos**.
- **Fonte 3, execução 3, captura 15:** `execution_id=1f45200c-f1dd-4749-8aeb-95cad7784b19`. Reserva em **17:13:55 UTC**, captura em **17:14:07 UTC** e ACK observado no relatório às **17:14:26 UTC**. Cobertura `complete`, **2/2 blocos**.

A consulta confirmou os vínculos de fonte, execução e captura, o proprietário, a conclusão completa, os blocos e o próximo agendamento diário. Isso registra sucesso delimitado em **uma superfície, com duas páginas**, não nas três superfícies do smoke. A sessão principal não inspecionou retrospectivamente console, JavaScript ou rede dessas páginas LinkedIn; não há alegação de ausência de erros nesses canais.

### LinkedIn Jobs: upload confirmado, sem conteúdo capturado

**Fonte 5, execução 4, captura 18:** `execution_id=1bc2db7c-c156-4567-9bc7-2e9e84ce1c4f`. O evento de acesso `1` registrou `failure` às **17:15:30 UTC**, antes do upload. A captura foi registrada às **17:15:54 UTC** com cobertura `partial`, motivo `no_content` e **zero blocos**. O banco confirmou execução `completed`, `completion_status=partial`, `last_success_at=NULL` e próximo vencimento em **08/11/2026 às 17:15:54 UTC**.

Nesse caso, `completed` significa que houve confirmação do upload, **não sucesso da busca nem coleta de vagas**. O redirecionamento observado foi de `/jobs/search/` para `/jobs/search-results`, com parâmetros de interface `currentJobId`, `skipRedirect` e `infoNotice`, mas também com filtros semânticos adicionais `f_TPR` e `f_SAL`. Esses filtros não podem ser ignorados como se fossem decoração da URL para forçar equivalência com a fonte original. O usuário não aceitou as restrições adicionais; os filtros originalmente aprovados devem ser restaurados no próximo smoke.

### Indeed Jobs: sem captura associada na observação

**Fonte 6, execução 5:** `execution_id=69457432-cefc-4480-b0f8-3cf3d3a1baba`, sem captura associada. O evento `2` registrou `failure` às **17:22:30 UTC**. O Hermes observou respostas `200` para validação da credencial e resolução da execução no service worker, mas nenhum `POST` de captura durante a janela de **30 segundos** examinada. Essa janela é evidência limitada, não prova de ausência de tentativas fora dela.

Com a abertura da página autorizada pelo usuário, a sessão principal inspecionou o DOM por CDP em modo somente leitura e confirmou o painel **Job Post Details** sem `#jobDescriptionText`. A inspeção não navegou nem enviou captura. O fragmento sanitizado ficou em `/tmp/opencode/indeed-job-details-20261107.html`; esse artefato de uma vaga real não é adicionado ao Git. A fixture usada nos testes conserva somente a estrutura necessária e substitui os dados por nomes e descrições fictícios. O diagnóstico DOM e a janela de rede não constituem aprovação do fluxo real da versão corrigida.

### Alterações observadas, correções e limites

Segundo o relatório Hermes, foram criadas as fontes **5 e 6**, com `frequency_minutes=1440`, e a fonte **4** foi adiada para **08/11/2026 às 17:15:00 UTC**, versão **2**. A sessão principal não reverteu nem alterou qualquer fonte ou agenda. Essas mudanças de estado são atribuídas ao smoke relatado, não às consultas posteriores somente leitura.

O usuário aprovou uma mudança de **código para comportamento futuro**: permitir retentativa de captura parcial `no_content` com zero blocos somente depois do vencimento da reserva original. Essa política não autoriza reparo automático das agendas existentes. O backend implementa essa regra com confirmação durável separada de sucesso, preservando adiamentos explícitos, desativação, mudança de identidade e replay. Seu funcionamento está coberto automaticamente, mas ainda não foi comprovado por nova coleta real.

As correções da extensão passaram nos recortes focados: **29 testes LinkedIn** e **28 testes Indeed**. No Indeed, o RED com a estrutura real reproduziu detalhe ausente e título incorreto; o GREEN usa descrição delimitada e mantém compatibilidade com o DOM antigo e a correlação de identidade. Outra regressão reproduziu título atualizado com descrição da vaga anterior: sem identidade explícita, a troca de vaga agora exige mudança no texto da descrição, não apenas no cabeçalho ou spinner. Sem evidência suficiente, a captura permanece parcial. Esses testes não equivalem a captura real usando a nova versão instalada.

Para o próximo smoke, o usuário autorizou posteriormente uma antecipação pontual **somente de `next_due_at` da fonte 5**, após releitura da sua versão atual. Essa autorização ainda não foi executada pela sessão principal. Frequências, URLs, filtros e demais fontes permanecem fora dessa permissão; em particular, a fonte 4 não deve ser reordenada para facilitar o teste. Os filtros originalmente aprovados devem ser restaurados no navegador, sem adotar silenciosamente os filtros extras do LinkedIn.

Não houve solicitação real de triagem, matching, preparação, aprovação ou envio de candidatura durante esse smoke; somente o Clef automático participou do processamento de IA observado. A ausência de inspeção retrospectiva de JavaScript e rede no LinkedIn permanece uma limitação. Os backups operacionais continuam não implantados.

## Validação das correções motivadas pelo smoke

- **LinkedIn Jobs:** aceita os caminhos exatos `/jobs/search-results` e `/jobs/search`, preservando origem, pesquisa e proveniência. O comparador reutiliza tracking conhecido, desconsidera somente as decorações de interface declaradas e mantém filtros reais, opções desconhecidas e valores duplicados relevantes. Hosts permitidos distintos não são unificados; os hashes persistidos não foram alterados. Divergência na URL final de uma captura reservada é rejeitada antes de persistir.
- **Agenda e falhas:** `partial/no_content/0` mantém ACK e histórico sem registrar sucesso e usa o vencimento original da reserva para nova elegibilidade, sem antecipar adiamentos explícitos. Falha inesperada de metadados preserva objeto órfão reconciliável; cleanup do objeto perdedor fica restrito a conflito conhecido. Os testes verificam a exceção injetada, não apenas um HTTP 500 genérico.
- **Fixture de ambiguidade:** a segunda reserva agora possui URL inicial e superfície coerentes com sua fonte. A expectativa de conflito entre duas reservas realmente correspondentes foi preservada.
- **PostgreSQL ampliado:** **214 testes, 1.596 assertions, saída 0**, abrangendo `DiscoveryRuns`, `DiscoverySources`, `CaptureIngestion` e `DiscoveryDomain`. Configuração `/tmp/opencode/jobora38-smoke-pgsql.xml`, banco exclusivo `jobora_t22_20261107_testing`; log `/tmp/opencode/jobora38-smoke-fixes-pg-final.log`.
- **Suíte PHP completa após as correções:** **1.482 casos, 1.469 aprovados, 13 ignorados e 11.517 assertions, saída 0**. Configuração `/tmp/opencode/jobora38-smoke-sqlite.xml`, SQLite exclusivo `/tmp/opencode/jobora38-final-testing.sqlite`; log `/tmp/opencode/jobora38-smoke-fixes-full-php.log`. As oito corridas históricas constam da execução PostgreSQL; as cinco corridas de envio possuem evidência PostgreSQL separada e código não alterado por esta correção.
- **Extensão:** **229 testes em 30 arquivos**, typecheck e build aprovados. Os resultados anteriores de 227 testes precedem a proteção adicional contra descrição obsoleta. O build atualizado fica em `extension/.output/chrome-mv3`, sem adicionar artefatos ao Git.
- **Revisão e estilo:** revisões independentes de spec e padrões concluídas. Achados de tracking, host e descrição obsoleta foram corrigidos e revalidados; comentários explicativos reescritos foram restaurados. Pint nos arquivos PHP alterados e whitespace checks passaram.

As configurações temporárias foram recriadas após reinício do servidor com bootstrap/diretórios absolutos e variáveis de teste forçadas. Artefatos antigos em `/tmp/opencode` podem não sobreviver a reinícios; seus caminhos históricos não implicam disponibilidade permanente. Nenhuma agenda real foi alterada pelos testes ou por essa recuperação. A validação automatizada não converte o smoke anterior parcialmente falho em sucesso das três superfícies.

## Pendências para aceite integral

- Implantar e verificar a política de backups, incluindo retenção das cópias e prevenção de restauração de bruto expirado.
- Retomar o smoke supervisionado com Hermes e a versão corrigida da extensão instalada, respeitando a autorização do usuário para a próxima interação. LinkedIn publicações já possui evidência parcial positiva em duas páginas; LinkedIn Jobs e Indeed Jobs exigem nova validação, com filtros originais restaurados e correlação de ACK, JavaScript, rede e backend. Não inventar diagnósticos retrospectivos das páginas anteriores.
- Não confundir autorização do smoke ou destes tickets com aprovação de uma candidatura real. Nenhum envio real foi executado ou autorizado por esta entrega.
