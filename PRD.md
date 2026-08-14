# PRD — Análise do Mercado de Trabalho a partir de Vagas de Emprego

## 1. Visão do produto

**O que é o produto**

Ferramenta pessoal que transforma descrições de vagas de emprego, coletadas manualmente ao longo do tempo, em uma base estruturada de dados sobre demandas do mercado de trabalho. A partir dessa base, o produto identifica padrões, frequências e tendências e cruza essas informações com o currículo do usuário. As vagas são a fonte de dados do produto: ele não é um gerenciador de vagas nem um sistema de acompanhamento de candidaturas. O nome do produto é **PENDENTE** (ainda não definido).

**Restrição tecnológica já definida**

O sistema será desenvolvido com Laravel + Filament. A estruturação das descrições será auxiliada por um modelo de linguagem — `deepseek-v4-flash`, fornecido pelo provedor OpenCode Go por meio de endpoint OpenAI-compatible — integrado à aplicação através do Laravel AI SDK, que atua como camada de integração com modelos de linguagem. Credenciais e configurações sensíveis (endpoint/base URL do OpenCode Go, API key, modelo) são fornecidas pelo ambiente/configuração da aplicação, sem hardcoding, e o modelo pode ser alterado posteriormente sem modificar a lógica de negócio. Demais escolhas de implementação ficam para o planejamento técnico.

**Arquitetura conceitual da integração com IA**

A aplicação não realiza chamadas HTTP diretas ao OpenCode Go: a lógica do produto conversa com um agente/serviço de análise, que utiliza o Laravel AI SDK como camada de integração com o provedor. Dentro da arquitetura do produto, o Laravel AI SDK é responsável pela comunicação com o modelo — incluindo agentes, structured output, configuração de provedores, tratamento de respostas e erros e possibilidade de testes/mock da integração — por meio do provider OpenAI-compatible apontando para o OpenCode Go com o modelo `deepseek-v4-flash`. Essa separação mantém o domínio da aplicação independente de detalhes específicos do OpenCode Go e permite substituir o provedor ou o modelo posteriormente sem alterar a lógica principal de cadastro e análise das vagas.

**Qual problema ele resolve**

Descrições de vagas são textos não estruturados espalhados em links, abas e anotações: não dá para medir, de forma sistemática, o que o mercado pede, o que já está no currículo e o que falta. Decisões sobre o que aprender e desenvolver acabam baseadas em impressão, não em dados.

**Qual é seu objetivo principal**

Construir, a partir das vagas coletadas, uma base de dados sobre as demandas do mercado (tecnologias, linguagens, frameworks, habilidades, requisitos etc.) e cruzar esses dados com o currículo, para embasar decisões sobre quais conhecimentos, tecnologias e experiências desenvolver. Pergunta central do produto: "Dado o conjunto de vagas que estou observando, o que o mercado está pedindo e como meu currículo se posiciona em relação a isso?"

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
- **O que o sistema faz**: envia a descrição da vaga ao agente/serviço de análise, que a encaminha ao modelo de linguagem `deepseek-v4-flash` (OpenCode Go, acessado via Laravel AI SDK com provider OpenAI-compatible) para identificar: cargo, empresa, tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida e outros atributos relevantes para a análise. A resposta do modelo segue um schema estruturado e validável (o schema exato será definido no planejamento técnico) e é validada pela aplicação antes de ser apresentada como sugestão. O modelo atua como mecanismo de extração/classificação, não como fonte definitiva dos dados: o resultado é uma sugestão estruturada, não uma verdade absoluta — a versão revisada pelo usuário é a que passa a integrar a base utilizada nas análises.
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

## 4. Dados

**Armazenados**

- **Vaga (unidade de observação)**: descrição (obrigatória), data de coleta (automática), link (opcional; apenas referência de origem, não é identificador único) e dados estruturados: cargo, empresa, tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida e outros atributos relevantes (extraídos em F2 via modelo de IA; campos podem ficar vazios quando não identificáveis). Apenas a versão revisada pelo usuário é armazenada: a sugestão do modelo é usada na etapa de revisão (F2) e não é mantida após a revisão.
- **Currículo**: texto atual (uma versão corrente, atualizável; histórico de versões não é obrigatório no MVP).

**Extraídos ou derivados por análise**

- Frequências por categoria (tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos, experiência exigida); combinações recorrentes entre tecnologias e requisitos; posicionamento do currículo diante dos padrões agregados (presente/ausente por item); lacunas (itens frequentes nas vagas e ausentes do currículo, priorizados por frequência); evolução das frequências ao longo do tempo — possível porque cada vaga tem data de coleta.

## 5. Análises

O produto deve permitir:

- **Frequências por categoria**: tecnologias, linguagens, frameworks, bancos de dados, ferramentas, cloud, metodologias, habilidades, requisitos e experiência exigida mais frequentes, ranqueados por número/proporção de vagas.
- **Combinações recorrentes**: tecnologias e requisitos que aparecem juntos; cargos e empresas mais comuns.
- **Comparação com o currículo**: posicionamento do currículo diante dos padrões agregados das vagas (ex.: item × frequência nas vagas × presente/ausente no currículo), com visão de aderência e lacunas.
- **Lacunas identificadas**: itens frequentes nas vagas e ausentes do currículo, priorizados por frequência.
- **Evolução ao longo do tempo**: variação das frequências por período (ex.: mensal/trimestral), para identificar itens em alta ou queda, quando houver dados suficientes.

## 6. Fluxo principal

Fluxo conceitual: vagas coletadas → dados estruturados → base de mercado → agregação → padrões/frequências/tendências → comparação com currículo → identificação de lacunas e aderências.

Fluxo operacional:

1. O usuário encontra uma vaga (ex.: LinkedIn), copia a descrição e a cadastra no sistema (o link é opcional).
2. O sistema armazena a descrição com a data de coleta.
3. O sistema envia a descrição ao modelo de linguagem via Laravel AI SDK (OpenCode Go, modelo `deepseek-v4-flash`), recebe uma resposta estruturada, valida-a e apresenta a sugestão; o usuário revisa e corrige; a versão revisada passa a integrar a base.
4. As análises agregam a base: frequências por categoria, combinações recorrentes e evolução temporal.
5. O sistema cruza os padrões agregados com o currículo atual, mostrando aderências e lacunas.
6. O usuário usa os resultados para decidir o que desenvolver e como ajustar o currículo — e pode atualizar o currículo no sistema quando necessário.
7. A coleta contínua ao longo do tempo enriquece a base e as análises, incluindo as tendências temporais.

## 7. MVP

**Essencial para o MVP**

- Cadastro das descrições de vagas (descrição obrigatória + data de coleta; link opcional).
- Estruturação dos dados da vaga com IA (OpenCode Go + `deepseek-v4-flash` via Laravel AI SDK), gerando sugestão estruturada e validada.
- Revisão e correção manual dos dados estruturados antes de integrarem a base.
- Cadastro e atualização do currículo (em texto).
- Agregação dos dados das vagas: frequências por categoria e combinações recorrentes.
- Visualização das principais frequências e padrões.
- Cruzamento com o currículo: aderências e lacunas em relação aos padrões agregados.
- Análise temporal básica (evolução de frequências por período, ex.: mensal/trimestral); os resultados dependem do acúmulo de dados ao longo do tempo.

**Interessante, mas pode esperar**

- Análises temporais mais aprofundadas (comparação entre períodos, tendências de longo prazo).
- Visualização detalhada por vaga individual (análise secundária; o foco é o agregado).
- Filtros avançados na listagem (por categoria, empresa, período).
- Histórico de versões do currículo.
- Exportação dos dados (CSV/JSON).

**Fora do escopo do produto**

- Multi-usuário/contas.
- Captura automática de vagas (scraping, crawling ou integração com LinkedIn).
- Recomendação de vagas.
- Gestão de candidaturas (status de aplicação) e análise de salários.

## 8. Critérios de aceitação

- **Cadastro de descrição**: ao colar uma descrição válida, a vaga aparece na base com a data do dia; uma vaga cadastrada sem link é salva normalmente (link opcional).
- **Estruturação**: ao cadastrar uma descrição contendo, por exemplo, "Python" e "React", o sistema apresenta uma sugestão estruturada gerada pelo modelo (via Laravel AI SDK + OpenCode Go + `deepseek-v4-flash`) e validada pela aplicação; após a revisão, a vaga fica registrada com essas tecnologias; uma correção manual persiste após recarregar; enquanto a revisão não é concluída, a vaga não entra nas análises.
- **Currículo**: ao colar o texto do currículo, ele é salvo; um novo envio substitui a versão anterior; a atualização se reflete nas análises comparativas.
- **Comparação agregada**: com vagas contendo "Python" e "React" e um currículo com "Python" sem "React", a visão comparativa mostra Python como presente no currículo e React como ausente, junto com a frequência de cada item nas vagas.
- **Análises**: com 3 vagas cadastradas (ex.: 2 com Python, 1 com React), o relatório lista Python com frequência 2 e React com frequência 1; as lacunas listam apenas itens frequentes nas vagas e ausentes do currículo.
- **Base para o tempo**: toda vaga registrada possui data de coleta, permitindo análises temporais.

## Decisões ainda pendentes

Questões que precisam ser decididas antes do desenvolvimento:

- Nome do produto.
- Formato de arquivo do currículo além de texto simples (PDF, DOCX).
