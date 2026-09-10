---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations precisam rodar em SQLite e PostgreSQL
Local/testes usam SQLite, producao usa PostgreSQL (Laravel Cloud). Nunca use SQL bruto especifico de um driver em migrations (ex.: 'INSERT OR IGNORE', PRAGMA, strftime). Prefira o query builder: insertOrIgnore() compila para INSERT OR IGNORE no SQLite e ON CONFLICT DO NOTHING no Postgres. Ao inserir ids explicitos em coluna bigserial, reajuste a sequence ou deixe o banco gerar o id.
