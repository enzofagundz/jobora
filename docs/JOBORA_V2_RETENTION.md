# Jobora V2 — Retenção do HTML de captura

## Escopo

Documento operacional do bruto de captura consolidada (#20). Descreve o que o código atual garante, o que depende de configuração externa e o que permanece pendente. Não afirma implantação de infraestrutura.

## Defaults aprovados

Configurados em `config/capture.php` (`ingestion`), sobrescrevíveis por ambiente:

| Item | Default | Variável |
| --- | --- | --- |
| HTML bruto | 10 MiB (10 485 760 bytes) | `CAPTURE_HTML_MAX_BYTES` |
| Payload do envelope | 12 MiB (12 582 912 bytes) | `CAPTURE_PAYLOAD_MAX_BYTES` |
| Blocos por captura | 1 000 | `CAPTURE_BLOCKS_MAX` |
| Retenção do bruto | 30 dias | `CAPTURE_RAW_RETENTION_DAYS` |
| Disco | `local` | `CAPTURE_DISK` |
| Prefixo do caminho | `capture-envelopes` | `CAPTURE_PATH` |

Os limites falham explicitamente (`413`/`CaptureTooLargeException`) e não truncam conteúdo.

## Armazenamento

- O HTML sanitizado é gravado no disco privado `local`, cujo root é `storage/app/private`, em `capture-envelopes/{user_id}/{uuid}.html`.
- O caminho é referenciado em `page_captures.html_path`; não há coluna de URL pública nem geração de link público para o bruto.
- O disco `local` tem `serve` habilitado, o que registra a rota `/storage/{path}` somente para URLs assinadas temporárias (visibilidade privada por padrão). A aplicação não gera esses links para o bruto.
- O código da aplicação **não implementa** criptografia em repouso, KMS, versionamento de objeto nem lifecycle remoto. Se `CAPTURE_DISK` apontar para storage remoto, políticas de acesso, criptografia e expiração são responsabilidade da infraestrutura, ainda não verificada nem implantada.
- A ingestão vale para API (`POST /api/page-captures`) e MCP: ambos chamam `CaptureIngestionService`.

## Retenção implementada no código

`CaptureRetentionService::pruneRaw()`:

- seleciona `page_captures` com `html_path` preenchido e `raw_expires_at <= agora`;
- apaga o objeto no disco configurado e só considera podada a captura quando a exclusão é confirmada;
- limpa `html_path` e grava `raw_deleted_at` apenas nesse caso;
- **não** altera publicações, observações, origens de oportunidade, candidaturas, adequações nem resultados.

Metadados preservados após a expiração: identificadores, hashes, cobertura, contagens de blocos, vínculos e carimbos de data. O bruto não é recuperável pelo sistema após `html_path` ser limpo.

`raw_expires_at` é definido na ingestão como `agora + CAPTURE_RAW_RETENTION_DAYS`. Capturas criadas fora da ingestão, como as importadas pelo backfill legado (#17), têm `raw_expires_at` nulo e nunca são expiradas por este comando.

## Comandos e agenda

```bash
php artisan captures:prune-raw
php artisan captures:reconcile-orphans
```

- `captures:prune-raw` imprime a quantidade de capturas efetivamente podadas; falhas preservam metadados e aparecem apenas no log.
- `captures:reconcile-orphans` imprime objetos removidos e metadados limpos.
- Ambos estão agendados em `routes/console.php` como `daily()` com `withoutOverlapping()`.
- Falha do agendador/worker é operacional: sem execução, o bruto permanece além dos 30 dias.

## Órfãos e metadados sem objeto

Comportamento atual de `reconcileOrphans()`:

- **Objeto sem metadado:** arquivos abaixo de `CAPTURE_PATH` não referenciados por nenhum `html_path` são excluídos somente quando o `lastModified` do objeto é pelo menos 1 dia anterior. Arquivos mais novos são mantidos, cobrindo gravação antes da transação de metadados.
- **Metadado sem objeto:** quando `html_path` existe no banco mas o arquivo não existe no disco, o metadado é limpo apenas se `raw_expires_at <= agora`. Antes do vencimento, o caminho permanece registrado apontando para objeto ausente e o bruto já está indisponível; não há reparo automático nesse intervalo.
- Registros com `raw_expires_at` nulo (legado) não são limpos por essa regra.
- A remoção em `pruneRaw` só limpa `html_path`/`raw_deleted_at` quando o disco confirma a exclusão. Retorno falso ou exceção preserva o metadado e registra `Log::warning` com `page_capture_id` e código (`delete_failed` ou `delete_exception`), sem HTML nem caminho. O objeto continua referenciado e é tentado novamente na próxima execução.
- Objeto já ausente no disco local conta como exclusão idempotente bem-sucedida; com `raw_expires_at` vencido, o metadado é limpo conforme a regra normal.
- O comando imprime somente a contagem efetivamente podada; falhas não entram nesse número e exigem monitoramento do log.

## Backups — requisito operacional

A política de backup **deve** ser compatível com a retenção:

- excluir `capture-envelopes/**` dos backups, **ou** expirar cópias do bruto em até 30 dias;
- jamais restaurar HTML expirado: restaurar objetos apagados recria conteúdo pessoal fora da janela aprovada;
- backups de banco preservam os metadados; se o caminho do bruto não existir após restauração, o bruto é considerado indisponível e os metadados/resultados continuam válidos.

Essa política é configuração de infraestrutura externa. **Não está verificada nem implantada** por este repositório; nenhuma garantia de expiração de cópias antigas é dada pelo código.

## Logs e exposição

- O código de ingestão, sanitização e retenção não registra o HTML, tokens ou segredos. Falha de storage é reportada como exceção sem payload.
- O caminho privado (`capture-envelopes/...`) não é URL pública; o disco `local` não tem `url` pública configurada.
- Logs de acesso do proxy/servidor e retenção de logs ficam fora deste código e exigem a mesma revisão operacional de exposição.

## Garantias do código vs pendências operacionais

Garantido pelo código atual:

- limites de HTML, payload e blocos;
- sanitização remove conteúdo executável e atributos sensíveis conhecidos antes da gravação;
- bruto em disco privado com caminho por usuário;
- expiração do bruto em 30 dias quando `raw_expires_at` está preenchido, com metadados preservados e limpeza só após exclusão confirmada pelo disco;
- reconciliação de objetos órfãos com carência de 1 dia e limpeza de metadados vencidos sem objeto;
- comandos e agendamento diário.

Pendente de operação/infra (não implantado nem verificado aqui):

- exclusão de `capture-envelopes/**` dos backups ou expiração de cópias em 30 dias;
- criptografia/gestão de chaves e lifecycle do storage remoto;
- monitoramento de falha dos comandos agendados e alerta de bruto vencido não removido;
- decisão sobre capturas legadas sem `raw_expires_at`;
- validação de isolamento/expiração em ambiente real e testes de corrida/falha de metadados de outra entrega — não afirmados neste documento.
