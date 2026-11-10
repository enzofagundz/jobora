# Baseline V2 — Evidências do T01 (#15)

Registro das evidências do ticket #15 no checkout `jobora-v2`, em 04/11/2026. Os logs de baseline e QA ficaram em `/tmp/opencode` e são efêmeros; este documento guarda o resumo necessário para reprodução e auditoria.

## 1. Ambiente efetivo

| Componente | Versão efetiva |
| --- | --- |
| PHP | **8.5.12** (não 8.4) |
| Laravel | 13.25.0 |
| Filament | 5.7.6 |
| laravel/ai | 0.10.3 |
| laravel/mcp | 0.9.5 |
| Pest | 4.7.8 |
| PHPUnit | 12.5.33 |
| Composer | 2.10.3 |
| Node.js | 22.23.4 |
| Bun | 1.3.14 |
| Vitest | 4.1.11 |
| WXT | 0.21.4 |
| lerd | 1.38.0 |

O `composer.json` declara `php: ^8.3`, mas o runtime efetivo do container é 8.5.12. Os testes PHP rodaram no container do lerd; a extensão usou Bun/Vitest no host.

## 2. Baseline inicial

Execuções concluídas, antes das correções:

| Banco | Testes | Passou | Falhou | Erros | Assertions | Duração |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| SQLite | 703 | 683 | 18 | 2 | 3760 | 41,4 s |
| PostgreSQL | 703 | 677 | 8 | 18 | 3739 | 59,4 s |

Nenhum resultado interrompido foi tratado como aprovação.

## 3. Causas e correções

### SQLite

- **18 falhas de fila:** `DB_QUEUE_AFTER_COMMIT=true` vinha do `.env`; com `Queue::fake()`, jobs despachados após commit não eram observados. A configuração de teste passou a forçar `DB_QUEUE_AFTER_COMMIT=false`.
- **2 erros em `NormalizeJobPostingItemsOrphansTest`:** o helper abria um PDO separado para inserir item órfão, gerando lock/visibilidade inconsistente e retorno `null`. A correção usa a mesma conexão do teste e adia a checagem de FK (`PRAGMA defer_foreign_keys` no SQLite; `session_replication_role = replica` no PostgreSQL), restaurando a configuração em `finally`.
- **Locks e backfill em subconjuntos:** o cache lock em banco disputava o SQLite e o backfill legado tentava usar `VAGAS_API_TOKEN` do ambiente. A configuração de teste passou a forçar `CACHE_LOCK_STORE=array`, `VAGAS_API_TOKEN=` e `memory_limit=1G`.

### PostgreSQL

- **18 erros:** `strftime()` não existe no PostgreSQL; `notifications.data` era `text` e não aceitava `->>`; o `GROUP BY` do widget de conceitos não incluía `job_posting_items.id`; e testes que esperavam `QueryException` deixavam a transação abortada. Correções: expressões de data específicas por driver (`to_char`/`date_trunc`), migration de `notifications.data` para `json`, `defaultKeySort(false)` no widget e encapsulamento das violações esperadas em `DB::transaction`.
- **8 falhas:** seis vinham do mesmo `data->>` em `text` (autenticação/Filament/isolamento); uma era expectativa fixa de URL/modelo do OpenCode Zen (`mimo-v2.5-free`); uma refletia erro de mercado. O teste passou a ler URL/modelo da configuração efetiva, e as consultas/notificações foram corrigidas pelas mudanças acima.

### Extensão TypeScript

- Compilação inicial: **19 erros** de tipagem, incluindo `Array.at(-1)`, acesso opcional em chamadas de mock, índices possivelmente indefinidos e ausência de declaração para `window.happyDOM`. Correções: acesso seguro, declarações de teste e ajustes de tipos. Compilação final: **0 erros**.
- Suíte completa: **121 testes**. Na primeira execução, 6 falharam porque as fixtures do contrato de envelope ainda não existiam; após criá-las, **121/121** passaram. O build `wxt build` concluiu com sucesso.

## 4. Baseline após correções

Baseline histórico das correções do T01, antes das features #16–#20:

| Banco | Testes | Passou | Assertions | Duração |
| --- | ---: | ---: | ---: | ---: |
| SQLite | 719 | 719 | 3838 | 42,3 s |
| PostgreSQL | 719 | 719 | 3838 | 57,1 s |

Extensão naquele baseline: **121/121 testes**, **0 erros de compilação**, build concluído.

### Suíte completa final após #16–#20

Execução única em bancos descartáveis, conexão verificada dentro do processo, incluindo os 4 testes novos de identificador de execução (65/200 caracteres):

| Banco | Testes | Passou | Skips | Assertions | Duração |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite | 900 | 892 | 8 | 4627 | 52,1 s |
| PostgreSQL | 900 | 900 | 0 | 4658 | 73,1 s |

Os 8 skips do SQLite são testes que exigem PostgreSQL (corridas reais). O **719/719 é histórico** e não representa o estado atual.

## 5. Contrato de envelope v1

Formato compartilhado PHP/TS: `jobora.capture-envelope`, versão `1`.

O envelope contém metadados de captura (chave idempotente, `execution_id` opcional, fonte, superfície, URLs inicial/final, instante, cobertura completa/parcial e HTML consolidado inerte) e um manifesto `blocks[]` com `block_id`, `kind` (`post`, `card`, `detail`), `platform_id`, `permalink` e relação card/detalhe.

O HTML consolidado não é executado nos testes. Cada bloco aparece uma vez com `data-block-id`, `data-kind`, `data-platform-id`, `data-permalink`; cards usam `data-detail-block-id` e detalhes usam `data-card-block-id`.

Fixtures sanitizadas:

- `fixtures/capture-envelope/v1/capture-envelope.v1.schema.json`
- `fixtures/capture-envelope/v1/fixtures.json`
- `fixtures/capture-envelope/v1/linkedin-publications.json`
- `fixtures/capture-envelope/v1/linkedin-jobs.json`
- `fixtures/capture-envelope/v1/indeed-jobs.json`
- `fixtures/capture-envelope/v1/indeed-jobs-partial.json`

Cobertura: três superfícies — publicações LinkedIn, vagas LinkedIn e vagas Indeed —, com uma fixture Indeed parcial (`timeout`, card sem detalhe) para diferenciar captura parcial de completa. Os dados são sintéticos (`Exemplo`), sem dados pessoais ou publicações reais.

Testes do contrato:

- `tests/Feature/Contracts/CaptureEnvelopeV1ContractTest.php` — 6 testes, 746 assertions.
- `extension/tests/contracts/capture-envelope.contract.test.ts` — 6 testes; incluído nos 121 da extensão.

## 6. DeepSeek via OpenCode Zen

Ensaio real delimitado, sem envio de candidatura:

- Provider/modelo: `opencode_zen/deepseek-v4.1-flash`
- Endpoint: `https://opencode.ai/zen/go/v1`
- Latência: **1,96 s**
- Resposta: JSON com **8 chaves**
- Tokens: **prompt 1156**, **completion 99**

O ensaio **não usou strict JSON schema**. Não foi apurado custo exato; nenhum valor de custo é afirmado aqui.

## 7. OAuth e Hermes

**Compatibilidade documental:** Hermes 0.21.7 declara suporte a Dynamic Client Registration (DCR), authorization code com PKCE S256, escopo `mcp:use`, leitura via registry, refresh e revogação.

**Smoke real:** Hermes 0.21.7 executou DCR, PKCE S256, `mcp:use`, leitura via registry, refresh e revogação. A sessão Laravel foi sintética, por HTTP; **não foi um login de navegador**.

**Escopos:** o token OAuth `mcp:use` é separado das capacidades internas. Naquele momento existiam **17 capacidades internas**. As capacidades de fontes e execuções foram adicionadas depois e **não são retroativas** a tokens já emitidos.

**QA posterior:** a verificação de consentimento e navegador executou **56 testes** e **91 checks** em desktop e mobile, sem problemas registrados. Evidências em `/tmp/opencode/jobora-qa-browser.log`, `/tmp/opencode/jobora-qa-evidence.json` e `/tmp/opencode/qa-*.png`.

## 8. Incidente de isolamento

Um wrapper do `lerd` ignorou o ambiente e aplicou **8 migrations, no batch 10, no banco de desenvolvimento**, criando schema de `notifications`, OAuth e domínio. Nenhum usuário de smoke foi criado e **não houve rollback**. O usuário foi informado e escolheu **manter o schema**; o backup do banco principal foi feito pelo usuário.

As execuções seguintes verificaram a conexão efetiva **dentro do processo de teste** e usaram bancos descartáveis. O banco de desenvolvimento **foi tocado**; este documento não afirma que ele permaneceu intocado.

## 9. Limitações e pendências

- A suíte completa após #16–#20 está registrada na seção 4 (900 no PostgreSQL; 892 + 8 skips no SQLite). O baseline 719/719 permanece apenas como referência histórica.
- Sanitização, confirmação durável e expiração do bruto têm implementação e testes registrados nesta entrega. A **política de backups** permanece requisito operacional não implantado nem verificado, conforme `docs/JOBORA_V2_RETENTION.md`.
- `composer audit --locked` reporta **22 advisories em 12 pacotes**; nenhum foi corrigido nesta entrega.
- O smoke OAuth não cobriu login humano em navegador; a QA posterior cobre consentimento e navegador, mas não substitui o smoke.
- O ensaio DeepSeek não usou strict schema e não mediu custo exato.
- Logs em `/tmp` são efêmeros; este resumo é a evidência persistida.
- Nenhum segredo, token ou dado pessoal é reproduzido aqui.

## 10. Artefatos e reprodução

- Logs PHP: `/tmp/opencode/baseline15/full-php-hermetic-final.json`, `full-php-pgsql.json`, `final-php-sqlite.json`, `final-php-pgsql.json`.
- Logs da extensão: `/tmp/opencode/baseline15/extension-compile.log`, `extension-test.log`, `extension-test-contracts-final.log`, `extension-build.log`.
- Versões: `/tmp/opencode/baseline15/versions.txt` e `composer-show-direct.txt`.

As configurações PHPUnit dedicadas (`phpunit.baseline15*.xml` e `phpunit.t20.xml`) são os artefatos necessários para forçar o banco e as variáveis de teste. A reprodução deve usá-las em vez de depender de variáveis exportadas no shell. A conexão PostgreSQL efetiva deve ser verificada dentro do processo de teste, com banco descartável. Comandos ou flags que foram removidos não são reconstruídos aqui.
