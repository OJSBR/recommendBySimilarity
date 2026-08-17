# Recommend Similar Articles — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-2.0.0.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/recommendBySimilarity/releases/download/2.0.0.1/recommendBySimilarity-2.0.0.1.tar.gz) · [OJS 3.3](https://github.com/OJSBR/recommendBySimilarity/releases/download/2.0.0.1-ojs3.3/recommendBySimilarity-2.0.0.1-ojs3.3.tar.gz) — or browse all [Releases](../../releases).

The **"Similar Articles"** section on the article page — the same feature journals already
know, rebuilt so that it is **read from a cache instead of searched for while a reader waits**.

> **Originally written by the [Public Knowledge Project](https://pkp.sfu.ca) — Simon Fraser
> University and John Willinsky — and distributed with OJS.** This is a rewrite of their
> plugin for OJS 3.5, keeping the feature, the ranking and the markup, and changing where the
> work happens; maintained by [OJSBR](https://ojsbr.com). Details in
> [Credits & authorship](#credits--authorship).

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 2.0.0.1 |
| OJS 3.3.x   | [`stable-3_3_0`](../../tree/stable-3_3_0) | 2.0.0.1-ojs3.3 |

### What differs in the 3.3 port

The feature, the settings and the stored data are the same. Only the platform APIs differ,
because OJS 3.3 predates them:

| 3.5 | 3.3 branch |
|-----|------------|
| `DB` / `Cache` facades | `Capsule` and the OJS file cache — 3.3 never sets a Laravel facade root |
| `Repo` collectors | DAOs |
| `metrics_submission` | `metrics` — 3.4 is what split that table |
| `HasTaskScheduler` | `scheduledTasks.xml` + `AcronPlugin::parseCronTab` |
| PSR-4 namespaces | `import()` and `.inc.php`, plus the `index.php` 3.3 requires |
| short locale codes (`pt`, `fr`) | long codes (`pt_PT`, `fr_FR`, `sr_RS@latin`) |
| `frontend/components/pagination.tpl` | pagination written out in the template — 3.3 has no such include |

Because 3.3 has no plugin namespaces, every class is prefixed with the plugin name. Both
recommend plugins used to declare a `RecommendationStore` and a `RefreshRecommendations`;
namespaces kept those apart in 3.5, and without them enabling both plugins at once was a
fatal error.

**Do not rename the plugin directory.** It must stay `recommendByAuthor` /
`recommendBySimilarity`, which is what `import()` and `scheduledTasks.xml` resolve against.

## The problem

The original plugin runs a **full search of the journal on every view of an article**: it takes
the article's keywords and asks the submission collector for the best matches, ordered by
search ranking.

That ordering is where the time goes. `Collector::ORDERBY_SEARCH_RANKING` sorts with **two
correlated subqueries** — "how many of these keywords does this submission match" and "how
many times" — evaluated **once per candidate row**. On a journal whose search index holds
3.4 million rows, one article costs:

| Sample | Time per view |
|--------|---------------|
| average of 25 articles | **8.0 s** |
| random sample, per article | **20 – 25 s** |
| worst case measured | **22.5 s** |

Rebuilding that for the whole journal the original way would be roughly **29 hours of CPU**.

> **This is not really a plugin problem.** The quadratic ordering lives in OJS core, so it
> costs the same on any search-ranked query. The fix belongs upstream too, and we have raised
> it with PKP.

## What it does

The same question, the same terms, the same ranking — asked once, in the background, as a
single grouped scan of the rows that actually contain the terms:

| | original | this plugin (cache miss) | this plugin (cache hit) |
|---|---|---|---|
| per article view | 8 – 25 s | **50.8 ms** | **0.11 ms** |
| whole journal (4,823 articles) | ~29 h of CPU | **196 s** | — |

Term extraction is done by **the core's own code** (`SubmissionSearchIndex::filterKeywords`,
same 20-term cap) and the ordering is **the core's own** — distinct terms matched first, then
total matches. On a sample, 5 to 9 of the first 10 results are identical to the original's;
the remainder is tie-breaking.

## Installation

1. Download the package from [Releases](../../releases) and install it through
   **Settings → Website → Plugins → Upload A New Plugin**, or drop the folder into
   `plugins/generic/recommendBySimilarity`.
   **Do not rename the folder** — OJS derives the plugin's namespace from the directory name.
2. Enable it in **Settings → Website → Plugins**. Enabling creates its two tables.
3. **The section will not appear straight away** — see *After enabling* below. To fill it in
   one pass instead of waiting:

```bash
php plugins/generic/recommendBySimilarity/tools/buildRecommendations.php --pause=150
```

Run it as the account that owns the files, never as root. `--help` lists the options.

The plugin relies on the search index OJS already maintains. If that index is stale, rebuild it
first (`php tools/rebuildSearchIndex.php`), or the lists will be built from whatever is there.

### After enabling: the section does not appear yet

**This is expected, and it is the plugin working as designed.** Enabling creates the tables but
leaves them empty; no search runs while a reader waits. Until an article has been searched for,
it simply shows no section.

How long the filling takes depends on how the site runs its scheduled tasks:

| | pace | a journal of 1,000 articles | of 5,000 |
|---|---|---|---|
| **With cron** (recommended) | 250 articles every 15 min | about **1 hour** | about **5 hours** |
| **Without cron** (`[schedule] task_runner`, the default) | up to 250 articles a minute, but only while people are visiting the site | minutes to hours, depending on traffic | idem |
| **By hand, once** | the whole journal in one pass | seconds to a minute | **196 s** on the journal this was measured on |

If you would rather not wait:

```bash
php plugins/generic/recommendBySimilarity/tools/buildRecommendations.php --pause=150
```

This plugin reads the search index OJS already maintains. **If that index is stale or empty, the
lists follow it** — rebuild it first with `php tools/rebuildSearchIndex.php` if in doubt.

You can watch it fill up in **Settings → Website → Plugins → Recommend Similar Articles →
Settings**, or with `tools/buildRecommendations.php --status`.

### Tables it creates

Two, both new — **no OJS table is modified**, and there is no index table: the search index OJS
already maintains is the right index for this question.

| table | what it holds |
|---|---|
| `recommend_similarity_cache` | the ordered list of similar submissions for each article |
| `recommend_similarity_state` | when each article was computed, and the search phrase it came from (used by the "refine this search" link) |

On a journal with 4,823 published articles they take about **42 MB** together, most of it the
cache — storing 50 similar articles each. Twenty is about 16 MB and still two pages deep. Every
row is tied to its submission with `ON DELETE CASCADE`, so uninstalling is a matter of dropping
the two tables.

## Configuration

**Settings → Website → Plugins → Recommend Similar Articles → Settings**, showing coverage and:

| Setting | Default | What it is for |
|---|---|---|
| Similar articles per page | 10 | What the reader sees at a time |
| Maximum stored per article | 50 | How deep paging can go; also the disk cost |
| Submissions refreshed per run | 250 | The size of each slice |
| Refresh lists older than | 30 days | How stale a list may get |
| Enrol at most | 0 (no limit) | Cap for trying the plugin out on part of a journal |
| Keep the rendered section for | 168 h | Lifetime of the rendered HTML |
| Search while the reader waits | off | See below |

**Leave "search while the reader waits" off on any journal of size** — with it off, an article
that has not been searched yet shows no section and costs one indexed query.

Storage is the one figure worth watching: 50 stored per article is about 39 MB on a journal of
4,823 articles. Twenty is about 16 MB and still two pages deep.

## How it works (technical)

Two layers, and no index table — **the search index OJS already maintains is exactly the right
index for this question**; what was missing was somewhere to write the answer down.

1. **Rendered HTML** in the Laravel cache, keyed by submission, state version, settings stamp,
   locale and page.
2. **`recommend_similarity_cache`** — the ordered list of similar submission ids.
   `recommend_similarity_state` records when each was computed and the search phrase it came
   from (which is what the "refine this search" link uses).

The scheduled task (every 15 minutes) refreshes **a slice**: never-computed first, then least
recently computed. Nothing expires at the same moment, so a refresh is never a stampede.
Publishing, unpublishing or deleting an article queues that article; its neighbours are picked
up by the rolling refresh, since naming everything that shares a term would be expensive and
one new article rarely reorders anybody's top ten.

No OJS table is modified. Uninstalling is `DROP`.

## Tests

Three suites, all of them run against OJS 3.5.0.3 before this release
(details and the list of cases in [tests/CASES.md](tests/CASES.md)):

| Suite | What it covers | Result |
|---|---|---|
| [`tests/regression.php`](tests/regression.php) | terms, the co-occurrence query, the store, and publishing through to the reader — against a real database | **34 cases, 34 passed** |
| [`tests/SimilarityFinderTest.php`](tests/SimilarityFinderTest.php) | the term-extraction contract (PHPUnit, no database) | **passed** |
| [`cypress/tests/functional/`](cypress/tests/functional) | enabling, the settings screen and the article page, in a browser | **7 tests, 7 passed** |

```bash
php plugins/generic/recommendBySimilarity/tests/regression.php
cd lib/pkp/tests && ../lib/vendor/bin/phpunit -c phpunit.xml --testsuite ApplicationPlugins
npx cypress run --spec 'cypress/tests/**/RecommendBySimilarity.cy.js'
```

The regression suite creates its own submissions and deletes them again; run it on a test
installation. The Cypress spec defaults to the PKP test data but runs against any journal
through `cypress.env.json` — it solves the Altcha proof of work where `captcha_on_login` is on,
and works whatever language the interface is in.

One case deserves singling out: **B09 runs the core's own `ORDERBY_SEARCH_RANKING` query — the
slow one — for one article and compares the result with this plugin's.** That is the guarantee
that replacing two correlated subqueries with a single grouped scan changed the cost and not the
answer.

## Credits & authorship

- **[Public Knowledge Project](https://pkp.sfu.ca), Simon Fraser University and John
  Willinsky** — authors of the original `recommendBySimilarity` plugin, of the feature, of the
  ranking this plugin reproduces and of the template markup it keeps. Copyright (c) 2014–2025
  Simon Fraser University, (c) 2003–2025 John Willinsky.
- **[OJSBR](https://ojsbr.com)** — the 3.5 rewrite: the grouped co-occurrence query, the
  materialised cache, the scheduled slice-refresh and the settings panel.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GNU GPL v3 — see [LICENSE](LICENSE), the same licence as OJS and as the original plugin.

---

## 🇧🇷 Português

A seção **"Artigos Semelhantes"** na página do artigo — a mesma funcionalidade que as revistas
já conhecem, reconstruída para ser **lida de um cache em vez de pesquisada enquanto o leitor
espera**.

> **Escrito originalmente pelo [Public Knowledge Project](https://pkp.sfu.ca) — Simon Fraser
> University e John Willinsky — e distribuído com o OJS.** Esta é uma reescrita do plugin deles
> para o OJS 3.5, que mantém a funcionalidade, o critério de ordenação e a marcação, mudando
> onde o trabalho acontece; mantida pela [OJSBR](https://ojsbr.com). Detalhes em
> [Créditos e autoria](#créditos-e-autoria).

### Compatibilidade e branches

| Versão do OJS | Branch | Versão do plugin |
|---------------|--------|------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 2.0.0.1 |
| OJS 3.3.x     | [`stable-3_3_0`](../../tree/stable-3_3_0) | 2.0.0.1-ojs3.3 |

### O que muda no port para o 3.3

A funcionalidade, as configurações e os dados gravados são os mesmos. O que muda são as
APIs da plataforma, que não existiam no OJS 3.3:

| 3.5 | branch 3.3 |
|-----|------------|
| facades `DB` / `Cache` | `Capsule` e o cache de arquivo do OJS — o 3.3 nunca define o facade root |
| coletores `Repo` | DAOs |
| `metrics_submission` | `metrics` — quem separou essa tabela foi o 3.4 |
| `HasTaskScheduler` | `scheduledTasks.xml` + `AcronPlugin::parseCronTab` |
| namespaces PSR-4 | `import()` e `.inc.php`, mais o `index.php` que o 3.3 exige |
| código de locale curto (`pt`, `fr`) | código longo (`pt_PT`, `fr_FR`, `sr_RS@latin`) |
| `frontend/components/pagination.tpl` | paginação escrita no próprio template — o 3.3 não tem esse include |

Como o 3.3 não tem namespace de plugin, toda classe leva o nome do plugin como prefixo. Os
dois plugins recommend declaravam uma `RecommendationStore` e uma `RefreshRecommendations`;
no 3.5 os namespaces separavam, e sem eles ligar os dois juntos dava erro fatal.

**Não renomeie a pasta do plugin.** Ela tem de continuar `recommendByAuthor` /
`recommendBySimilarity`, que é o que o `import()` e o `scheduledTasks.xml` resolvem.

### O que faz

O plugin original executa **uma busca completa na revista a cada visualização de artigo**. O
custo está na ordenação: o `ORDERBY_SEARCH_RANKING` do OJS ordena com **duas subconsultas
correlacionadas**, avaliadas **por linha candidata**. Numa revista cujo índice de busca tem 3,4
milhões de linhas, isso dá de **20 a 25 segundos por artigo** (média de 25 artigos: 8,0 s).

> **Isso não é bem um problema do plugin.** A ordenação quadrática está no núcleo do OJS, então
> custa o mesmo em qualquer consulta ordenada por relevância — inclusive na busca do site. A
> correção também pertence ao núcleo, e já levamos o caso à PKP.

| | original | este plugin (sem cache de HTML) | este plugin (com cache) |
|---|---|---|---|
| por visualização | 8 a 25 s | **50,8 ms** | **0,11 ms** |
| revista inteira (4.823 artigos) | ~29 h de CPU | **196 s** | — |

Os termos são extraídos **pelo próprio código do núcleo** (`filterKeywords`, com o mesmo teto
de 20 termos) e a ordenação é **a do próprio núcleo** — termos distintos primeiro, depois total
de ocorrências. Numa amostra, de 5 a 9 dos 10 primeiros resultados são idênticos aos do
original; o restante é desempate.

### Instalação

1. Baixe o pacote em [Releases](../../releases) e instale por **Configurações → Website →
   Plugins → Enviar novo plugin**, ou copie a pasta para
   `plugins/generic/recommendBySimilarity`. **Não renomeie a pasta.**
2. Habilite em **Configurações → Website → Plugins**. Ao habilitar, as duas tabelas são criadas.
3. **A seção não aparece de imediato** — veja *Depois de habilitar*, abaixo. Para preencher de
   uma vez, em vez de esperar:

```bash
php plugins/generic/recommendBySimilarity/tools/buildRecommendations.php --pause=150
```

Rode como o dono dos arquivos, nunca como root. O plugin usa o índice de busca que o OJS já
mantém; se ele estiver desatualizado, reconstrua antes (`php tools/rebuildSearchIndex.php`).

### Depois de habilitar: a seção ainda não aparece

**Isso é esperado, e é o plugin funcionando como projetado.** Habilitar cria as tabelas, mas elas
nascem vazias; nenhuma busca roda com o leitor esperando. Enquanto um artigo não tiver sido
pesquisado, ele simplesmente não exibe a seção.

Quanto tempo leva para preencher depende de como o site executa as tarefas agendadas:

| | ritmo | revista de 1.000 artigos | de 5.000 |
|---|---|---|---|
| **Com cron** (recomendado) | 250 artigos a cada 15 min | cerca de **1 hora** | cerca de **5 horas** |
| **Sem cron** (`[schedule] task_runner`, o padrão) | até 250 artigos por minuto, mas só enquanto houver visitas ao site | de minutos a horas, conforme o tráfego | idem |
| **Na mão, uma vez** | a revista inteira de uma vez | de segundos a um minuto | **196 s** na revista onde isto foi medido |

Se preferir não esperar:

```bash
php plugins/generic/recommendBySimilarity/tools/buildRecommendations.php --pause=150
```

Este plugin lê o índice de busca que o próprio OJS mantém. **Se esse índice estiver
desatualizado ou vazio, as listas seguem o que houver nele** — na dúvida, reconstrua antes com
`php tools/rebuildSearchIndex.php`.

Dá para acompanhar o preenchimento em **Configurações → Website → Plugins → Recomendar artigos
semelhantes → Configurações**, ou com `tools/buildRecommendations.php --status`.

### Tabelas que ele cria

Duas, ambas novas — **nenhuma tabela do OJS é alterada**, e não há tabela de índice: o índice de
busca que o OJS já mantém é o índice certo para esta pergunta.

| tabela | o que guarda |
|---|---|
| `recommend_similarity_cache` | a lista ordenada de artigos semelhantes para cada artigo |
| `recommend_similarity_state` | quando cada artigo foi calculado e a frase de busca que originou a lista (usada no link de pesquisa avançada) |

Numa revista com 4.823 artigos publicados, as duas somam cerca de **42 MB**, quase tudo na
tabela de cache — que guarda 50 semelhantes por artigo. Com 20 ficam uns 16 MB, ainda rendendo
duas páginas. Cada linha está presa à sua submissão com `ON DELETE CASCADE`, então desinstalar é
apagar as duas tabelas.

### Configuração

Em **Configurações → Website → Plugins → Recomendar artigos semelhantes → Configurações**:
semelhantes por página, máximo armazenado por artigo, tamanho do lote por execução, idade
máxima antes de recalcular, teto de submissões inscritas e duração do cache de HTML.

**Deixe "buscar enquanto o leitor espera" desligado** em revista de porte. Vale olhar o
armazenamento: 50 por artigo dão ~39 MB numa revista de 4.823 artigos; 20 dão ~16 MB e ainda
rendem duas páginas.

### Como funciona (técnico)

Duas camadas e **nenhuma tabela de índice** — o índice de busca que o OJS já mantém é
exatamente o índice certo para esta pergunta; o que faltava era onde escrever a resposta. O
HTML renderizado fica no cache do Laravel; a tabela `recommend_similarity_cache` guarda a lista
pronta, e `recommend_similarity_state` registra quando foi calculada e a frase de busca que a
originou (usada no link de pesquisa avançada).

A tarefa agendada roda a cada 15 minutos e atualiza **uma fatia**, começando pelos nunca
calculados. Nada expira ao mesmo tempo. Publicar, despublicar ou excluir um artigo recoloca
esse artigo na fila; os vizinhos são cobertos pela renovação rolante.

Nenhuma tabela do OJS é alterada. Desinstalar é `DROP`.

### Testes

Três suítes, todas executadas contra o OJS 3.5.0.3 antes desta versão (a lista de casos está em
[tests/CASES.md](tests/CASES.md)): a de regressão (`tests/regression.php`), que cobre os termos,
a consulta de co-ocorrência, o armazenamento e o caminho da publicação até o leitor contra um
banco real — **34 casos, 34 passaram**; a unitária em PHPUnit
(`tests/SimilarityFinderTest.php`) — **passou**; e a funcional em Cypress, que cobre habilitar o
plugin, a tela de configurações e a página do artigo no navegador — **7 testes, 7 passaram**.

Um caso merece destaque: **o B09 executa a consulta original do núcleo (a lenta,
`ORDERBY_SEARCH_RANKING`) para um artigo e compara com o resultado deste plugin.** É a garantia
de que trocar duas subconsultas correlacionadas por uma varredura agrupada mudou o custo, e não
a resposta.

A suíte de regressão cria e apaga as próprias submissões: rode em instalação de teste. O Cypress
usa por padrão a base de testes da PKP, mas roda contra qualquer revista via `cypress.env.json`.

### Créditos e autoria

- **[Public Knowledge Project](https://pkp.sfu.ca), Simon Fraser University e John Willinsky**
  — autores do plugin `recommendBySimilarity` original, da funcionalidade, do critério de
  ordenação que este plugin reproduz e da marcação do template que ele preserva. Copyright (c)
  2014–2025 Simon Fraser University, (c) 2003–2025 John Willinsky.
- **[OJSBR](https://ojsbr.com)** — a reescrita para o 3.5: a consulta agrupada de
  co-ocorrência, o cache materializado, a atualização em fatias e o painel de configurações.

### Licença

GNU GPL v3 — veja [LICENSE](LICENSE), a mesma licença do OJS e do plugin original.
