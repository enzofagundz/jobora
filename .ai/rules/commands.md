---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Deduplicação conservadora de JobPostingItem
job-posting-items:normalize deduplica apenas por (job_posting_id, category, comparisonKey), onde comparisonKey = trim + collapse espaços + lowercase, SEM resolver aliases do ConceptNormalizer (Node != Node.js, C != C++, .NET != .NET Core). O canonical é user → menor created_at → menor id; itens órfãos (sem vaga) são ignorados e reportados, nunca removidos. Schedule diário em routes/console.php.
