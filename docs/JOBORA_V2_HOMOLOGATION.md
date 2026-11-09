# Jobora V2 — Evidências de homologação (#38)

## Escopo e estado

Registro das evidências automatizadas e do smoke real supervisionado em 07/11/2026, sobre as bases #36 (`630acfa`) e #37 (`98f6e4d`). Os ensaios automatizados e restores usam dados sintéticos e bancos descartáveis; essa restrição não descreve o smoke externo, que coletou páginas reais e está registrado separadamente abaixo. As correções motivadas pelo smoke estão em validação. Nenhum envio real de candidatura foi autorizado ou executado.

Esta evidência **não significa homologação integral**. Suíte completa, fluxo integrado controlado, upgrade e restore possuem os baselines automatizados registrados abaixo. No primeiro smoke, LinkedIn publicações teve duas páginas com captura completa e ACK correlacionado. No segundo, Indeed Jobs produziu uma captura útil e durável, mas parcial; LinkedIn Jobs revelou perda de vínculo da reserva após mudança semântica dos filtros. Esse problema foi corrigido e validado automaticamente; a revalidação supervisionada e a implantação dos backups continuam pendentes. O fechamento de #29 ocorreu por pedido humano, sem comprovação integral das três superfícies.

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

Para o próximo smoke, o usuário autorizou posteriormente uma antecipação pontual **somente de `next_due_at` da fonte 5**, após releitura da sua versão atual. O Hermes executou essa antecipação no segundo smoke registrado adiante; a sessão principal não alterou a agenda. Frequências, URLs, filtros e demais fontes permanecem fora dessa permissão; em particular, a fonte 4 não deve ser reordenada para facilitar o teste. Os filtros originalmente aprovados devem ser restaurados no navegador, sem adotar silenciosamente os filtros extras do LinkedIn.

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

## Segundo smoke real Hermes de 07/11/2026 — vínculo de reserva e resultado parcial

Esta seção acrescenta o segundo relatório sem substituir o primeiro smoke nem os baselines automatizados anteriores. Todas as horas abaixo estão em **UTC**; a conversão do relato em BRT é **UTC = BRT + 3 horas**. A sessão principal correlacionou os metadados por consultas de banco somente leitura, sem ler cabeçalhos de autenticação e sem alterar dados ou agendas. O estado de cada execução é o observado na consulta, não uma afirmação sobre seu estado ou TTL no momento de leitura deste documento.

### Indeed: captura 19 confirmada, útil e parcial

**Fonte 6, versão 1; execução 6; captura 19:** `execution_id=aace8c02-104f-4887-b337-cb6299aa1be1`. A reserva ocorreu às **19:55:07 UTC** e a conclusão às **19:59:00 UTC**. O Hermes leu o ACK `201` antes de passar para LinkedIn. No service worker, observou validação da credencial `200`, resolução `200` e captura `201`.

A conferência do banco confirmou cobertura `partial`, motivo `timeout`, **29/30 blocos**, **15 observações e 15 publicações**, extração marcada como `completed`, expiração do bruto em **07/12/2026 às 19:59 UTC**, próximo vencimento em **08/11/2026 às 19:59 UTC** e `last_success_at=NULL`. Não foram reportados conflitos. Trata-se de conteúdo persistido e aproveitável com confirmação durável, **não sucesso pleno nem cobertura completa**.

O console Indeed apresentou avisos Apollo, falhas de fetch e bloqueios `blocked_by_client`. Essas mensagens não foram isoladas na janela exata da captura 19; não é possível atribuir cada aviso a essa captura nem declarar o console limpo.

### LinkedIn Jobs: reserva sem ACK e capturas manuais persistidas

**Fonte 5, versão 2; execução 7:** `execution_id=67e7ac0b-f296-4064-b0e2-9a6f1601f998`. A reserva foi registrada em **20:00:02 UTC**, com vencimento em **20:30:02 UTC**. Na consulta, a execução estava `active`, sem captura associada. Esses horários não significam que a reserva continue vigente agora.

A URL inicial era `/jobs/search/?keywords=Laravel&f_WT=2`. O LinkedIn passou a exibir `/jobs/search-results/?currentJobId=4486639255&keywords=Laravel&f_TPR=r86400`: removeu `f_WT=2` e acrescentou `f_TPR=r86400`. Essa mudança altera os filtros da pesquisa; não é somente tracking ou um alias de caminho. O usuário não aceitou adotar os novos filtros. Portanto, a rejeição de correlação dessa URL modificada é intencional e não deve ser contornada afrouxando a equivalência semântica.

O Hermes observou resolução `200` vinculada à execução na URL original e, depois, `404` para a URL modificada. Em seguida, ocorreram uploads pelo caminho manual, com `execution_id=null`:

- **Captura 20, às 20:00:48 UTC:** `no_content`, zero blocos.
- **Captura 21, às 20:01:21 UTC:** `timeout`, um bloco e uma publicação.
- **Captura 22, às 20:03:41 UTC:** `no_content`, zero blocos.

O banco confirmou essas capturas manuais persistidas; elas não são objetos órfãos de storage e **não constituem ACK da execução 7**, que permanecia sem conclusão na consulta. As capturas **20–22 devem ser preservadas**, não apagadas para fazer o smoke parecer limpo. O relatório também informou o evento de acesso `3`, de falha, às **20:05 UTC**.

Na mesma conferência, a fonte 5 tinha `next_due_at=07/11/2026 19:54 UTC` e `last_success_at=NULL`. A fonte 4 permanecia na versão **2**, com próximo vencimento em **08/11/2026 às 17:15 UTC**. A sessão principal não alterou esses valores.

### Ações observadas e limites dos diagnósticos

O Hermes executou a antecipação aprovada **somente de `next_due_at` da fonte 5**, passando da versão **1 para 2**, ativou a captura no popup com token e API já configurados e reiniciou o Brave com CDP na porta **9222**. Não mudou frequência, URLs ou outras fontes. Essas ações pertencem ao relato do smoke; as consultas posteriores da sessão principal foram somente leitura.

As tentativas de restaurar a pesquisa pela interface e por `history.replaceState` não produziram uma pesquisa equivalente verificada. A leitura de console LinkedIn apresentou `ERR_FAILED` e **249 mensagens omitidas**; o painel de todos os filtros não foi aberto. Alterar cosmeticamente a URL não comprova que os filtros foram aplicados e não é uma recomendação para contornar a divergência.

Os diagnósticos backend disponíveis ficaram limitados ao MCP e ao `Laravel.log`, sem linhas na janela examinada. Essa ausência não comprova backend limpo nem correlação integral de todos os diagnósticos. As limitações de console e rede descritas aqui devem permanecer no aceite, sem preencher retrospectivamente janelas não observadas.

### Regressão isolada e decisão de correção mínima

A conclusão é dupla: o Indeed demonstrou captura durável **parcial**; no LinkedIn, a divergência semântica foi corretamente rejeitada, mas uma tentativa que já conhecia sua reserva degradou silenciosamente para captura manual. Esse segundo comportamento é o bug, não o `404` isolado nem a preservação dos filtros originais.

O teste público composto [reservation-context-regression.test.ts](../extension/tests/reservation-context-regression.test.ts), com controller, background e fila reais e transporte simulado, reproduziu sobre a base `5b90636` **3 falhas RED e 2 controles aprovados**. As falhas mostram resolução reservada `200`, re-resolução `404` e upload `201` com `execution_id=null`, além da tentativa de enviar a execução antiga com filtros incompatíveis. Os controles preservam alias/tracking com a mesma semântica e captura manual legítima em uma página inicialmente sem reserva. Esses casos passaram após a correção, junto às regressões adicionais de isolamento e replay descritas abaixo.

A decisão do usuário é **“Só bloquear (mínimo)”**: depois de uma reserva conhecida, não permitir fallback manual silencioso; bloquear com erro claro. Captura manual legítima iniciada sem reserva, inclusive em outra aba, deve continuar possível. Não estão aprovados um botão novo, um modo novo ou uma funcionalidade de escolha manual explícita. Não se deve forçar o identificador antigo sobre a pesquisa errada.

Não houve solicitação real de triagem, matching, preparação, aprovação ou envio de candidatura nesse segundo smoke. Backups operacionais continuam **não implantados**. A correção foi validada automaticamente; novos diagnósticos e coleta real continuam pendentes e não estão automaticamente autorizados por este relatório.

### Correção e validação do bloqueio reservado

- O background recebe o remetente real do runtime e vincula a intenção reservada a **aba/frame**, credencial, proprietário e API. Não há alegação de isolamento por `documentId`: o reload da mesma aba preserva deliberadamente a intenção pendente.
- O vínculo persiste em `storage.session`. Erro de resolução, expiração ou mudança de execução não transforma uma tentativa já reservada em captura manual. Uma aba inicialmente sem reserva continua podendo realizar captura manual legítima; não existe latch global por proprietário. Capacidade de 100 contextos falha fechada, sem eviction silenciosa; fechar a aba remove contextos, sem abandonar a fila.
- Novos envelopes LinkedIn Jobs revalidam a pesquisa no backend antes de enfileirar. Filtros divergentes permanecem bloqueados; não se falsifica a URL final nem se consulta somente a URL inicial para forçar correspondência.
- O hash imutável do envelope é persistido antes do efeito. Replay exato mantém validações de remetente, credencial, proprietário, API e pin; corpo diferente é rejeitado. Após ACK perdido, flush e recriação do background, o replay continua possível mesmo com a fila esvaziada. Quando necessário, a API deduplica a repetição exata ou rejeita primeira chegada expirada; não há renovação de reserva ou TTL.
- Falha auxiliar ao salvar receipt/status não invalida ACK recebido. Testes reproduzem falha de storage somente depois de HTTP `201`, perda do ACK e replay posterior. O painel apresenta alerta acessível: “Captura bloqueada para preservar a reserva. Verifique a pesquisa e a validade da execução.” Não foi criado botão nem modo manual novo.
- **Extensão completa:** 255 testes aprovados em 32 arquivos, saída 0; log `/tmp/opencode/jobora38-ack-replay-full.log`. Typecheck passou. As 14 verificações focadas de UI/controller usam Vue real e se sobrepõem à suíte; não são somadas aos 255 testes.
- **Revisões:** eixos spec e padrões executados; achados de ACK/replay foram corrigidos e revisados novamente em modo somente leitura, sem novos testes. Não houve alteração em backend PHP, migrations, configuração ou permissões nesta correção; suas evidências anteriores permanecem delimitadas aos respectivos commits.
- **Recursos locais:** após solicitação do usuário, não foram repetidas suítes já verdes. Antes do build, havia 4.196 MiB disponíveis e 3.485 MiB de swap usados. O build passou em escopo transitório com `MemoryMax=768M`, `MemorySwapMax=0`, `CPUQuota=100%` e heap Node limitado a 384 MiB. Esses valores são limites impostos, não medição de pico. Futuras execuções devem conferir RAM e usar um teste focado, um processo e um worker por vez, sem suítes paralelas.

O build atualizado está em `extension/.output/chrome-mv3`, fora do Git. Isso não comprova a extensão recarregada no navegador nem uma nova coleta real. Fontes, agendas e capturas 20–22 não foram modificadas ou apagadas pela correção.

## Pré-checagem posterior da fonte 5 — sem reserva ou coleta

O Hermes relatou uma nova pré-checagem pelo Browse sobre o Brave real, sem CDP nem reinício do navegador. Informou Browse `0.0.14`, extensão do Browse `0.0.10`, protocolo 3 compatível e navegador pareado `brave-fedora`. Esses dados descrevem o transporte usado pelo Hermes, não uma nova verificação independente feita pela sessão principal nem a versão da extensão Jobora. A configuração nativa do Hermes não foi alterada pela sessão principal.

Segundo o relatório, a fonte 5 permanecia na versão 2, ativa, com `next_due_at=07/11/2026 16:54 BRT`. Não houve nova reserva. A execução 7 continuava registrada como `active`, mas com `expires_at` vencido; esse registro não foi tratado como autorização vigente.

A navegação começou na URL original `/jobs/search/?keywords=Laravel&f_WT=2`. Em aproximadamente 1,5, 4 e 9 segundos, o LinkedIn alternou para `/jobs/search-results`, acrescentou `f_SAL` e `f_TPR=r86400` e retirou `f_WT=2` da URL. A interface mostrava os chips “Últimas 24 horas” e “Remota”, assuntos adicionais, um resultado e aviso de ausência de resultados exatos com apresentação de critérios semelhantes. O chip remoto, isoladamente, não comprova equivalência de todos os filtros nem uma pesquisa exata.

As tentativas pela interface de abrir “Todos os filtros” e alterar a data não produziram opções utilizáveis ou restauraram a pesquisa original; a navegação limpa reaplicou as restrições. Não foi usado `history.replaceState`, manipulação de DOM ou URL cosmética. A conclusão delimitada é que **a fonte 5, com sua intenção atual, permaneceu bloqueada na pré-checagem**. Isso não demonstra impossibilidade geral de capturar LinkedIn Jobs com outras pesquisas autorizadas.

“Ativar captura” permaneceu desativado e nenhum painel Jobora apareceu. O Hermes informou ausência de novas capturas, exclusões ou alterações de fontes, frequências, agendas e permissões. Foram observados DOM, chips, presença do painel e estado via MCP; console, rede e logs backend não foram inspecionados nessa rodada. Portanto, não há alegação de ausência de erros nesses canais.

A interrupção antes de reservar ou coletar respeitou o roteiro e evitou novos registros manuais. **Essa rodada não exercitou o bloqueio `reservation_changed` após uma reserva reconhecida**, nem produziu ACK vinculado a uma nova execução; não substitui a validação supervisionada pendente dessa transição. Nenhum teste, build, coleta ou alteração de configuração foi executado pela sessão principal ao registrar o relatório.

## Pendências para aceite integral

- Implantar e verificar a política de backups, incluindo retenção das cópias e prevenção de restauração de bruto expirado.
- Revalidar o comportamento de retentativa e planejar novos diagnósticos com a sessão principal, sem reagendar fontes existentes nem apagar as capturas 20–22 para facilitar o ensaio.
- Retomar o smoke supervisionado com Hermes e a versão corrigida da extensão instalada somente após confirmar a autorização do usuário para a próxima interação. LinkedIn publicações possui evidência positiva delimitada em duas páginas; Indeed possui captura útil, porém parcial. LinkedIn Jobs ainda exige pesquisa semanticamente equivalente à fonte original, sem troca silenciosa de filtros, com correlação de ACK, JavaScript, rede e backend. Não inventar diagnósticos retrospectivos das páginas anteriores.
- Não confundir autorização do smoke ou destes tickets com aprovação de uma candidatura real. Nenhum envio real foi executado ou autorizado por esta entrega.
