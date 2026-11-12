# Contrato verificável de matching — matching-v1

## Escopo e aprovação

Contrato aprovado explicitamente pelo usuário para o ticket #32, sobre a base `b41609e`. A entrega contém especificação, JSON Schema e exemplos sanitizados rotulados manualmente. Não contém cálculo, worker, ferramentas MCP ou avaliação de um modelo real. O ticket #27 foi encerrado; o comentário de entrega do #30 registra perfil confirmado e importação revisável. A arquitetura §6.3 continua vigente.

Os artefatos públicos são `tests/Fixtures/matching-v1.schema.json` e `tests/Fixtures/matching-v1.json`. O diretório `tests/Fixtures` é novo, dentro da árvore de testes existente e nos caminhos autorizados para esta entrega.

## Solicitação futura e fronteiras de confiança

O contrato futuro é `request_matching(opportunity_id, expected_opportunity_version, expected_profile_confirmed_version, criteria_version='matching-v1')`. A identidade vem exclusivamente do transporte autenticado. Não aceita `user_id`, perfil livre, critérios adicionais, anchors fornecidos pelo cliente ou parâmetros de autorização. O usuário deve possuir tanto o perfil quanto a oportunidade.

Usar exclusivamente `professional_profiles.confirmed_snapshot`, incluindo as preferências, restrições e pretensão desse snapshot. Não usar o rascunho, preferências editáveis atuais, currículo, tradução, ATS, Clef ou outro perfil como fallback. Sem confirmação, responder `profile_not_confirmed` antes de chamar o provider.

A oportunidade deve ter `readiness=ready`. Não exigir o status legado `confirmed`: uma oportunidade pronta da triagem pode ter `status=pending`. `needs_review` não é pronta. IDs alheios não revelam conteúdo. Versão esperada diferente da versão atual produz `conflict`, sem cálculo. Oportunidade não pronta produz `opportunity_not_ready`; acesso não autorizado produz `forbidden` ou `not_found`, conforme a fronteira de autenticação/ownership existente.

## Entrada congelada e identidade

Congelar, antes da chamada delimitada:

- `user_id` interno, nunca argumento público;
- `profile`: `id`, `confirmed_version`, `snapshot`, `hash`;
- `opportunity`: `id`, `opportunity_version`, `snapshot`, `hash`;
- `provenance`: publicações efetivamente usadas, com `id`, `version`, `snapshot`, `hash`, sem rastreio DOM presumido;
- catálogo `anchors` construído pelo backend;
- `criteria_version=matching-v1`, `schema_version=matching-result-v1`, `provider=opencode_go` e `model=deepseek-v4.1-flash`.

Os hashes são SHA-256 hexadecimal minúsculo do JSON canônico de cada snapshot. A canonicalização ordena recursivamente as chaves de objetos, preserva a ordem das listas e emite UTF-8 compacto, sem escapar Unicode ou `/`. Não normaliza texto, aliases, moedas ou números durante o hashing. Os exemplos usam somente números inteiros, strings, null, booleanos e listas/objetos; o contrato não introduz valores monetários float ambíguos.

`input_fingerprint` é SHA-256 do JSON canônico de toda a entrada congelada descrita acima, incluindo usuário, proveniência, anchors, schema, critérios, provider e modelo. O backend, não o modelo, determina esses valores e valida o eco de fingerprint/basis. Não incluir API keys ou tokens. Não reler fontes mutáveis para validar evidência de uma execução já solicitada.

## Inventário determinístico

O backend cria exatamente uma avaliação por item das listas, preservando índices, sem deduplicação semântica ou critérios adicionais:

- `requirement:N`, kind `requirement`, para cada `opportunity.snapshot.requirements[N]`;
- `technology:N`, kind `technology`, para cada `opportunity.snapshot.technologies[N]`;
- `preference:N`, kind `preference`, para cada preferência do snapshot confirmado;
- `restriction:N`, kind `restriction`, para cada restrição do snapshot confirmado;
- cinco IDs fixos: `experience`, `seniority`, `education`, `location_modality`, `compensation`, com kind igual ao ID.

Listas vazias não geram itens artificiais. Os cinco critérios fixos continuam presentes. Sua exigência pode estar declarada em campos textuais da oportunidade, não necessariamente em um campo dedicado. Se não foi declarada, usar `unsupported` e `criterion_not_stated`, identificando o campo/lado em `missing_paths`. Nunca transformar falta de declaração em exigência inferida. Texto de prompt injection não altera inventário ou autorização.

## Estados e razões

| Status | Significado | reason_code permitido |
| --- | --- | --- |
| `met` | Suporte suficiente para o critério declarado. | `explicit_support` |
| `unsupported` | Falta uma exigência declarada ou informação suficiente de um dos lados. | `criterion_not_stated`, `profile_information_missing`, `opportunity_information_missing`, `bilateral_information_missing` |
| `incompatible` | Contradição explícita entre as condições dos dois lados. | `explicit_conflict` |
| `uncertain` | Há evidência, mas a relação permanece ambígua ou incomparável. | `ambiguous_evidence`, `partial_overlap`, `incomparable_compensation` |

Ausência nunca significa incompatibilidade. `met` e `incompatible` exigem evidência de ambos os lados: ao menos um anchor de perfil e um de oportunidade ou publicação de origem congelada. `uncertain` exige evidência da ambiguidade; falta pura de dados é `unsupported`. `unsupported` exige `missing_paths` não vazio. Nos outros estados, `missing_paths` é vazio.

Cada missing path usa `profile:/...` ou `opportunity:/...`, com JSON Pointer no snapshot. Identifica o campo ou seção onde falta a informação relevante, não exige que a chave inteira seja inexistente: `profile:/competencies` pode existir sem demonstrar o requisito Java. Para uma exigência não declarada em descrição/requisitos, indicar os campos consultados. Não inventar pointers de campos que não fazem parte do snapshot contratado.

`explanation` é texto livre para leitura humana, nunca alvo de igualdade textual de teste nem substituto de evidência. Não há score, pesos, thresholds, percentual, ranking, recomendação binária de candidatura ou nota global. `summary` contém somente contagens dos quatro estados.

## Evidências e anchors

O backend constrói entradas `{id, source, entity_id, version, snapshot_hash, path, text}`. `source` é `profile`, `opportunity` ou `publication`. `path` é um JSON Pointer para campo textual real do snapshot correspondente; `text` é seu conteúdo exato. Um anchor agregado de publicação representa apenas o campo persistido, não um elemento DOM.

Exemplos: `p:7:v4:/competencies/0`, `o:42:v3:/requirements/0` e `pub:9:v1:/content`. IDs são opacos para o modelo, únicos no catálogo e ligados à entidade, versão, hash e path exatos. A saída referencia somente `{anchor_id, quote}`. A citação deve existir literalmente no anchor indicado. Não aceitar um anchor inventado, versão/hash/pointer trocado, chave extra, critério ausente/duplicado/adicionado ou fingerprint diferente: o resultado inteiro é `invalid_output`, sem publicação parcial.

O suporte literal preserva identidade lexical Unicode e pontuação técnica: JavaScript não demonstra Java; C++/C# não demonstram C; Node.js não demonstra Node; .NET não demonstra NET. C++, C#, .NET e Node.js completos são válidos, inclusive no fim de frase. Uma citação recortada não pode contornar os limites do token no anchor original. Isso não define equivalências semânticas, aliases ou uma ontologia universal.

## Regras específicas

- **Experiência:** não inferir anos por títulos, não somar períodos sobrepostos e não converter uma trajetória ambígua em atendimento. Datas sem duração demonstrada não bastam para afirmar anos exigidos.
- **Senioridade:** não inferir por idade, duração da carreira ou ferramenta usada. Rótulos ambíguos com evidência são `uncertain`.
- **Formação:** não equiparar curso livre a graduação. Uma descrição ambígua com evidência é `uncertain`; ausência é `unsupported`.
- **Localização/modalidade:** usar somente condições explícitas do perfil. Não inferir residência pelo empregador. Remoto versus híbrido “a combinar” pode permanecer `uncertain`.
- **Preferências:** desejo não é veto. Uma preferência por remoto não vira restrição absoluta; registrar a ambiguidade quando a condição negociável não permite conclusão suficiente.
- **Restrições:** somente contradição explícita bilateral permite `incompatible`, como “não aceito presencial” versus vaga presencial.
- **Compensação:** comparar somente moeda e período explícitos compatíveis. Teto oferecido abaixo do mínimo explícito desejado é `incompatible`; piso oferecido suficiente é `met`; sobreposição parcial é `uncertain`. Moeda/período ausentes são `unsupported`. Moedas/períodos explícitos diferentes são `uncertain` com `incomparable_compensation`, sem conversão, anualização ou suposição bruto/líquido.

## Schema de saída e mecanismo futuro

O JSON Schema Draft 2020-12 publica o formato de resultado `matching-result-v1`. O envelope contém versões, fingerprint, `basis`, avaliações e contagens. Restrições entre campos, catálogo de anchors, inventário, hashes e ownership são verificações adicionais obrigatórias do backend #33, não garantias que JSON Schema sozinho forneça.

O mecanismo aprovado para #33 é uma chamada delimitada ao Laravel AI SDK/OpenCode Go. O modelo julga relações semânticas de texto livre; o backend valida contrato, inventário, referências e fingerprint. Sem ferramentas, navegador, memória, autonomia ou fallback Zen. Essa especificação não implementa a chamada nem mede a qualidade de um modelo real. Verificação lexical prova correspondência ao texto congelado, não verdade externa nem correção do julgamento semântico. Conteúdo das fontes é dado não confiável.

## Validade, histórico e reuso

Reuso exige mesmo usuário, IDs, versão confirmada do perfil, versão da oportunidade, hashes, proveniência, critérios, schema, provider e modelo. Uma edição de draft não invalida o resultado confirmado. Nova confirmação, nova versão/hash da oportunidade ou nova versão dos critérios torna o resultado anterior `stale`; não apaga histórico e não dispara matching automaticamente. Alteração de proveniência, schema, provider ou modelo também impede reuso como resultado atual. O resultado histórico nunca é apresentado como atual depois de perder validade.

## Dataset e limites da verificação

O dataset contém entradas sanitizadas, anchors e rótulos esperados definidos manualmente, além de um resultado completo de exemplo e mutações negativas declarativas. `expected` contém status, reason_code, anchors e missing paths literais independentes; explicações livres não são comparadas. Casos de gate e validade também são exemplos de contrato, não execução de um avaliador.

Não há biblioteca JSON Schema instalada. O teste consumidor verifica um subconjunto deliberadamente estreito: estrutura do schema publicado, enums/constantes, integridade dos snapshots/anchors, inventário, coerência de versões/contagens e vetores negativos. Não é um interpretador geral de JSON Schema nem um avaliador de matching. Não comprova comportamento HTTP, worker, autorização real, latência, custo ou qualidade do provider; esses testes pertencem à implementação dependente.
