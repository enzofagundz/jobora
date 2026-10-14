# PRD — Análise do Mercado de Trabalho a partir de Vagas de Emprego

## 1. Visão do produto

**O que é o produto**

Ferramenta pessoal que transforma descrições de vagas de emprego, coletadas manualmente ao longo do tempo, em uma base estruturada de dados sobre demandas do mercado de trabalho. A partir dessa base, o produto identifica padrões, frequências e tendências e cruza essas informações com o currículo do usuário. As vagas são a fonte de dados do produto: ele não é um gerenciador de vagas nem um sistema de acompanhamento de candidaturas. Além da visão agregada do mercado, o produto permite aplicar essa análise a uma vaga específica, gerando uma versão do currículo adequada aos critérios normalmente utilizados por sistemas ATS (Applicant Tracking Systems) — trabalhando exclusivamente com informações verdadeiras já presentes no currículo do usuário.

**Restrição tecnológica já definida**

O sistema será desenvolvido com Laravel + Filament. A estruturação das descrições será auxiliada por um modelo de linguagem — `mimo-v2.5-free`, fornecido pelo provedor OpenCode Zen via Laravel AI SDK — integrado à aplicação através do Laravel AI SDK, que atua como camada de integração com modelos de linguagem. Credenciais e configurações sensíveis (API key do OpenCode Zen, modelo) são fornecidas pelo ambiente/configuração da aplicação, sem hardcoding, e o modelo pode ser alterado posteriormente sem modificar a lógica de negócio. A adequação de currículo para uma vaga (F6) utilizará o provedor/modelo de IA já definido para o produto (OpenCode Zen, modelo `mimo-v2.5-free`, via Laravel AI SDK). A Cloudflare não participa da geração textual nem da adequação do currículo: é utilizada exclusivamente para a geração/renderização do PDF da versão adaptada, através do driver Cloudflare do `spatie/laravel-pdf`, que envia o HTML/Blade do currículo adaptado ao Cloudflare Browser Run para renderização em PDF. A exportação em PDF é o formato de exportação definido para o MVP. Demais escolhas de implementação ficam para o planejamento técnico.

**Arquitetura conceitual da integração com IA**

A aplicação não realiza chamadas HTTP diretas ao OpenCode Zen: a lógica do produto conversa com um agente/serviço de análise, que utiliza o Laravel AI SDK como camada de integração com o provedor. Dentro da arquitetura do produto, o Laravel AI SDK é responsável pela comunicação com o modelo — incluindo agentes, structured output, configuração de provedores, tratamento de respostas e erros e possibilidade de testes/mock da integração — por meio do provider opencode_zen com o modelo `mimo-v2.5-free`. Essa separação mantém o domínio da aplicação independente de detalhes específicos do OpenCode Zen e permite substituir o provedor ou o modelo posteriormente sem alterar a lógica principal de cadastro e análise das vagas. O mesmo princípio se aplica à adequação de currículo (F6): um agente/serviço próprio realiza a adequação através da mesma camada do Laravel AI SDK, com o provedor OpenCode Zen e o modelo `mimo-v2.5-free`, sem alterar a lógica de negócio.

**Qual problema ele resolve**

Descrições de vagas são textos não estruturados espalhados em links, abas e anotações: não dá para medir, de forma sistemática, o que o mercado pede, o que já está no currículo e o que falta. Decisões sobre o que aprender e desenvolver acabam baseadas em impressão, não em dados.

**Qual é seu objetivo principal**

Construir, a partir das vagas coletadas, uma base de dados sobre as demandas do mercado (tecnologias, linguagens, frameworks, habilidades, requisitos etc.) e cruzar esses dados com o currículo, para embasar decisões sobre quais conhecimentos, tecnologias e experiências desenvolver. Pergunta central do produto: "Dado o conjunto de vagas que estou observando, o que o mercado está pedindo e como meu currículo se posiciona em relação a isso?" Como objetivo complementar, o produto permite aplicar essa leitura a uma vaga específica: adequar o currículo atual aos critérios de ATS daquela vaga, sem inventar informações.

## 2. Usuário e problema

**Quem utilizará**

Uso pessoal do autor do produto (single-user). Perfil: profissional que busca entender o mercado de trabalho para planejar a carreira, se preparar para oportunidades e orientar a evolução do próprio currículo.

**Como o usuário utiliza atualmente esse tipo de informação**

Salva links e descrições de vagas (abas, favoritos, anotações avulsas), relê descrições individualmente quando precisa e compara mentalmente os requisitos com o currículo. Não existe base estruturada nem visão agregada.

**Dificuldades que o produto pretende resolver**

- Descrições espalhadas e sem estrutura: não há registro histórico nem base de dados.
- Comparação manual e mental entre o que o mercado pede e o currículo, sujeita a esquecimento e vieses.
- Sem visão agregada: não dá para saber quais tecnologias/linguagens/frameworks/habilidades são mais pedidos, quais requisitos são recorrentes e quais lacunas são frequentes.
- Decisões de desenvolvimento profissional tomadas sem base de dados.

## 3. Funcionalidades principais

### F1. Cadastro de descrição de vaga

- **O que o usuário faz**: encontra uma vaga (ex.: LinkedIn), copia a descrição e cola no sistema. Pode informar o link da vaga como referência de origem, mas isso é opcional.
- **O que o sistema faz**: valida a descrição (obrigatória), registra a data de coleta automaticamente e armazena a descrição. O link, quando informado, é apenas uma informação de origem/referência: não é identificador único e não gera regras de deduplicação.
- **Resultado esperado**: descrição armazenada com sua data de coleta, pronta para estruturação.

### F2. Estruturação dos dados da vaga

- **O que o usuário faz**: revisa a sugestão estruturada gerada pelo sistema e corrige o que for necessário.
- **O que o sistema faz**: envia a descrição da vaga ao agente/serviço de análise, que a encaminha ao modelo de linguagem `mimo-v2.5-free` (OpenCode Zen, acessado via Laravel AI SDK com provider opencode_zen) para identificar: cargo, empresa, tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida e outros atributos relevantes para a análise. A resposta do modelo segue um schema estruturado e validável (o schema exato será definido no planejamento técnico) e é validada pela aplicação antes de ser apresentada como sugestão. O modelo atua como mecanismo de extração/classificação, não como fonte definitiva dos dados: o resultado é uma sugestão estruturada, não uma verdade absoluta — a versão revisada pelo usuário é a que passa a integrar a base utilizada nas análises.
- **Resultado esperado**: vaga com dados estruturados confiáveis (o usuário é a fonte de verdade final para correções).

### F3. Cadastro e atualização do currículo

- **O que o usuário faz**: informa o currículo em texto (colar ou arquivo de texto). Formato de arquivo além de texto simples (PDF, DOCX): **PENDENTE**.
- **O que o sistema faz**: armazena o currículo como uma das fontes da análise comparativa. O currículo é tratado como algo que evolui: o usuário pode atualizá-lo a qualquer momento, e um novo envio substitui o texto atual. Histórico de versões do currículo pode ser considerado posteriormente; não é obrigatório para o MVP.
- **Resultado esperado**: currículo atual disponível para o cruzamento com os dados agregados das vagas.

### F4. Comparação com o currículo (agregada)

- **O que o usuário faz**: consulta a visão que cruza os padrões observados nas vagas com o currículo atual.
- **O que o sistema faz**: para cada item relevante (tecnologia, linguagem, framework, habilidade, requisito etc.), verifica a presença no texto do currículo atual e apresenta a frequência de presença nas vagas e a indicação de presença ou ausência no currículo, permitindo identificar lacunas e pontos de aderência em relação às tendências observadas.
- **Resultado esperado**: visão comparativa agregada, como no exemplo ilustrativo abaixo (frequências hipotéticas):

| Tecnologia | Presença nas vagas | Currículo |
| ---------- | -----------------: | --------- |
| PHP        |                78% | Presente  |
| Laravel    |                62% | Presente  |
| Docker     |                64% | Presente  |
| AWS        |                48% | Ausente   |
| Kubernetes |                31% | Ausente   |

A finalidade é identificar lacunas e aderências em relação às tendências do mercado, não dizer se o usuário atende ou não cada vaga individualmente. Uma análise individual por vaga pode existir, mas é secundária.

### F5. Análises do mercado

- **O que o usuário faz**: consulta as análises geradas a partir das vagas cadastradas.
- **O que o sistema faz**: agrega a base e apresenta as frequências por categoria (tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida), as combinações recorrentes e a evolução dessas frequências ao longo do tempo, quando houver dados suficientes.
- **Resultado esperado**: visões que embasam decisões sobre o que desenvolver e como ajustar o currículo.

### F6. Adequação de currículo para uma vaga (ATS)

- **O que o usuário faz**: seleciona uma vaga cadastrada (com dados estruturados confirmados) e solicita a adequação do currículo atual para aquela vaga.
- **O que o sistema faz**: recupera o currículo atual, a descrição original e os dados estruturados da vaga (tecnologias, habilidades, requisitos, experiência exigida etc.), junto com a comparação mercado × currículo, e envia essas informações ao agente/serviço de adequação, que utiliza o Laravel AI SDK com o provedor OpenCode Zen (modelo `mimo-v2.5-free`, o mesmo já definido para o produto). A IA analisa a aderência do currículo à vaga e gera uma versão adaptada do currículo, orientando: terminologia, palavras-chave, organização das informações, destaque de experiências relevantes, ordem/priorização das competências e adequação do conteúdo ao perfil procurado.
- **Regra fundamental — integridade das informações**: a IA trabalha exclusivamente com informações verdadeiras presentes no currículo do usuário. Não pode inventar experiências profissionais, tecnologias, conhecimentos, cargos, certificações, resultados, projetos, responsabilidades, formação ou qualquer outra informação factual. Ela pode reformular informações existentes, mas não falsificá-las. Requisitos da vaga ausentes do currículo são tratados como lacunas, e nunca criados artificialmente. Quando uma adaptação não puder ser feita sem inventar informação, o sistema emite um alerta.
- **Resultado esperado**: o usuário recebe (1) uma versão adaptada do currículo; (2) a explicação das principais alterações realizadas; (3) os requisitos/palavras-chave da vaga contemplados; (4) os requisitos relevantes da vaga ainda ausentes no currículo; (5) alertas quando uma adaptação não puder ser feita sem inventar informações. A versão adaptada continua sendo um currículo do usuário — não uma reprodução da descrição da vaga. O usuário pode copiar a versão final ou exportá-la em PDF — formato de exportação definido para o MVP; DOCX fica fora do MVP.

**Pipeline de geração do PDF** (responsabilidades): IA — adequa o conteúdo do currículo à vaga; Laravel — orquestra o processo; Blade/HTML — apresenta o currículo adaptado; `spatie/laravel-pdf` — gera o PDF; driver `cloudflare` do `spatie/laravel-pdf` (Cloudflare Browser Run) — renderiza o HTML em PDF. A Cloudflare não participa da geração textual nem da adequação do currículo.

## 4. Dados

**Armazenados**

- **Vaga (unidade de observação)**: descrição (obrigatória), data de coleta (automática), link (opcional; apenas referência de origem, não é identificador único) e dados estruturados: cargo, empresa, tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida e outros atributos relevantes (extraídos em F2 via modelo de IA; campos podem ficar vazios quando não identificáveis). Apenas a versão revisada pelo usuário é armazenada: a sugestão do modelo é usada na etapa de revisão (F2) e não é mantida após a revisão.
- **Currículo**: texto do perfil em versões por idioma — PT-BR (canônica) e EN (derivada do PT-BR por tradução de IA) —, cada uma atualizável; histórico de versões não é obrigatório no MVP.
- **Versão adaptada do currículo (por vaga e idioma)**: resultado da adequação (F6) — uma versão adaptada por (vaga, idioma), no máximo PT-BR e EN, acompanhada da explicação das alterações realizadas, dos requisitos/palavras-chave contemplados, dos requisitos relevantes ainda ausentes e dos alertas de adaptação; uma nova geração substitui a versão anterior daquele idioma (sem histórico de versões no MVP).

**Extraídos ou derivados por análise**

- Frequências por categoria (tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida); combinações recorrentes entre tecnologias e requisitos; posicionamento do currículo diante dos padrões agregados (presente/ausente por item); lacunas (itens frequentes nas vagas e ausentes do currículo, priorizados por frequência); evolução das frequências ao longo do tempo — possível porque cada vaga tem data de coleta. Para a adequação por vaga (F6), a IA deriva a versão adaptada do currículo e seus itens associados (alterações, contemplados, lacunas, alertas) a partir do currículo atual, da descrição original e dos dados estruturados da vaga.

## 5. Análises

O produto deve permitir:

- **Frequências por categoria**: tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos e experiência exigida mais frequentes, ranqueados por número/proporção de vagas.
- **Combinações recorrentes**: tecnologias e requisitos que aparecem juntos; cargos e empresas mais comuns.
- **Comparação com o currículo**: posicionamento do currículo diante dos padrões agregados das vagas (ex.: item × frequência nas vagas × presente/ausente no currículo), com visão de aderência e lacunas.
- **Lacunas identificadas**: itens frequentes nas vagas e ausentes do currículo, priorizados por frequência.
- **Evolução ao longo do tempo**: variação das frequências por período (ex.: mensal/trimestral), para identificar itens em alta ou queda, quando houver dados suficientes.
- **Adequação por vaga**: aplicação complementar à análise agregada — posicionamento do currículo em relação a uma vaga específica (descrição original + dados estruturados), com geração de versão adaptada, alterações realizadas, requisitos contemplados e lacunas. A análise agregada do mercado permanece o objetivo principal do produto.

## 6. Fluxo principal

Fluxo conceitual: vagas coletadas → dados estruturados → base de mercado → agregação → padrões/frequências/tendências → comparação com currículo → identificação de lacunas e aderências → adequação do currículo para uma vaga específica (aplicação complementar).

Fluxo operacional:

1. O usuário encontra uma vaga (ex.: LinkedIn), copia a descrição e a cadastra no sistema (o link é opcional).
2. O sistema armazena a descrição com a data de coleta.
3. O sistema envia a descrição ao modelo de linguagem via Laravel AI SDK (OpenCode Zen, modelo `mimo-v2.5-free`), recebe uma resposta estruturada, valida-a e apresenta a sugestão; o usuário revisa e corrige; a versão revisada passa a integrar a base.
4. As análises agregam a base: frequências por categoria, combinações recorrentes e evolução temporal.
5. O sistema cruza os padrões agregados com o currículo atual, mostrando aderências e lacunas.
6. O usuário usa os resultados para decidir o que desenvolver e como ajustar o currículo — e pode atualizar o currículo no sistema quando necessário.
7. A coleta contínua ao longo do tempo enriquece a base e as análises, incluindo as tendências temporais.

Fluxo da adequação de currículo para uma vaga (F6):

1. O usuário seleciona uma vaga cadastrada (com dados estruturados confirmados) e solicita a adequação do currículo atual para aquela vaga.
2. O sistema recupera o currículo atual.
3. O sistema recupera a descrição original e os dados estruturados da vaga.
4. O sistema envia currículo, descrição, dados estruturados e a comparação mercado × currículo ao agente de adequação (Laravel AI SDK, provedor OpenCode Zen, modelo `mimo-v2.5-free`).
5. A IA analisa a aderência do currículo aos requisitos da vaga.
6. A IA gera a versão adaptada do currículo e os demais resultados (alterações, contemplados, lacunas, alertas).
7. O sistema apresenta o resultado ao usuário.
8. O usuário revisa a versão adaptada.
9. O usuário pode copiar a versão final ou exportá-la em PDF — gerado via `spatie/laravel-pdf` com driver `cloudflare` (Cloudflare Browser Run).

## 7. MVP

**Essencial para o MVP**

- Cadastro das descrições de vagas (descrição obrigatória + data de coleta; link opcional).
- Estruturação dos dados da vaga com IA (OpenCode Zen + `mimo-v2.5-free` via Laravel AI SDK), gerando sugestão estruturada e validada.
- Revisão e correção manual dos dados estruturados antes de integrarem a base.
- Cadastro e atualização do currículo (em texto).
- Agregação dos dados das vagas: frequências por categoria e combinações recorrentes.
- Visualização das principais frequências e padrões.
- Cruzamento com o currículo: aderências e lacunas em relação aos padrões agregados.
- Análise temporal básica (evolução de frequências por período, ex.: mensal/trimestral); os resultados dependem do acúmulo de dados ao longo do tempo.
- Adequação de currículo para uma vaga (F6): seleção de vaga cadastrada, geração da versão adaptada com os resultados associados (alterações, requisitos contemplados, lacunas, alertas), revisão, cópia e exportação em PDF (`spatie/laravel-pdf` com driver `cloudflare` / Cloudflare Browser Run — formato de exportação do MVP); persistência de uma versão adaptada por (vaga, idioma) — PT-BR e EN — (sem histórico).

**Interessante, mas pode esperar**

- Análises temporais mais aprofundadas (comparação entre períodos, tendências de longo prazo).
- Visualização detalhada por vaga individual (análise secundária; o foco é o agregado).
- Filtros avançados na listagem (por categoria, empresa, período).
- Histórico de versões do currículo.
- Exportação dos dados (CSV/JSON).
- Exportação da versão adaptada em DOCX (não faz parte do MVP; pode ser considerada futuramente).

**Fora do escopo do produto**

- Multi-usuário/contas.
- Captura automática de vagas (scraping, crawling ou integração com LinkedIn).
- Recomendação de vagas.
- Gestão de candidaturas (status de aplicação) e análise de salários.
- Geração de carta de apresentação.
- Candidatura automática e envio automático de currículo.
- Integração com LinkedIn como fluxo do produto (ex.: publicação ou candidatura), além da captura de vagas.
- Histórico temporal do currículo adaptado (persiste-se uma versão por idioma — PT-BR e EN —, sem histórico de versões).

## 8. Critérios de aceitação

- **Cadastro de descrição**: ao colar uma descrição válida, a vaga aparece na base com a data do dia; uma vaga cadastrada sem link é salva normalmente (link opcional).
- **Estruturação**: ao cadastrar uma descrição contendo, por exemplo, "Python" e "React", o sistema apresenta uma sugestão estruturada gerada pelo modelo (via Laravel AI SDK + OpenCode Zen + `mimo-v2.5-free`) e validada pela aplicação; após a revisão, a vaga fica registrada com essas tecnologias; uma correção manual persiste após recarregar; enquanto a revisão não é concluída, a vaga não entra nas análises.
- **Currículo**: ao colar o texto do currículo, ele é salvo na versão do idioma correspondente; um novo envio substitui a versão anterior daquele idioma; a versão PT-BR se reflete nas análises comparativas.
- **Comparação agregada**: com vagas contendo "Python" e "React" e um currículo com "Python" sem "React", a visão comparativa mostra Python como presente no currículo e React como ausente, junto com a frequência de cada item nas vagas.
- **Análises**: com 3 vagas cadastradas (ex.: 2 com Python, 1 com React), o relatório lista Python com frequência 2 e React com frequência 1; as lacunas listam apenas itens frequentes nas vagas e ausentes do currículo.
- **Base para o tempo**: toda vaga registrada possui data de coleta, permitindo análises temporais.
- **Adequação de currículo**: com uma vaga confirmada exigindo, por exemplo, "Python" e "React" e um currículo com "Python" sem "React", a adequação gera uma versão adaptada que reformula e destaca a experiência com Python (apenas informações existentes), lista "React" como requisito ausente (lacuna) e não introduz nenhuma informação factual ausente do currículo original; a versão adaptada não é uma reprodução da descrição da vaga.
- **Integridade**: em qualquer cenário, a versão adaptada contém apenas informações verdadeiras presentes no currículo (reformuladas, nunca inventadas); requisitos da vaga sem correspondência no currículo aparecem como lacunas; quando uma adaptação exigiria inventar informação, o sistema exibe um alerta.
- **Resultado da adequação**: a resposta apresenta os cinco itens — versão adaptada, explicação das alterações, requisitos contemplados, requisitos ausentes e alertas.
- **Persistência da adequação**: gerar uma nova adequação para a mesma vaga e idioma substitui a versão adaptada anterior daquele idioma (sem histórico); as adequações de idiomas diferentes coexistem.

## Decisões ainda pendentes

Questões que precisam ser decididas antes do desenvolvimento:

- Formato de arquivo do currículo além de texto simples (PDF, DOCX).
