# Test cases — recommendBySimilarity

Three suites, with different reaches. **Re-run them on every OJS upgrade** and on every change
to the plugin: it reads the core's search index and reproduces the core's own ranking, and both
have changed between minor releases before.

| Suite | What it covers | Last run |
|---|---|---|
| `tests/regression.php` | terms, the co-occurrence query, the store and the whole path, against a real database | **34 cases, 34 passed** |
| `tests/SimilarityFinderTest.php` (PHPUnit) | the term-extraction contract, no database | **passed** — 36 tests / 54 assertions together with the author plugin's |
| `cypress/tests/functional/` | enabling, settings and the article page, in a browser | **7 tests, 7 passed** |

All three were run against OJS 3.5.0.3 on PHP 8.2 before this was published.

## How to run

```bash
# 1. Regression: needs only a working install with one journal.
php plugins/generic/recommendBySimilarity/tests/regression.php

# 2. Unit: needs the dev dependencies (composer install) and the pkp-lib test
#    tree. The PKP configuration picks plugin tests up on its own.
cd lib/pkp/tests && ../lib/vendor/bin/phpunit -c phpunit.xml --testsuite ApplicationPlugins

# 3. Functional: needs Node and Cypress.
npx cypress run --spec 'cypress/tests/**/RecommendBySimilarity.cy.js'
```

Run them as the account that owns the files, never as root.

The Cypress spec assumes the PKP test data (`publicknowledge`, `admin`/`admin`); any other
installation can run the same spec by putting its own values in `cypress.env.json`:

```json
{ "contextPath": "myjournal", "adminUser": "…", "adminPassword": "…", "articleId": 42 }
```

It solves the Altcha proof of work when the site has `captcha_on_login` turned on, sets a
session cookie so that an edge cache cannot answer for the application, and drives the grid by
element name rather than by label, so the language of the interface does not matter.

> **Test installations only.** The regression suite creates and deletes submissions, and indexes
> them for search. Everything it creates is titled `[RBS-REGRESSION]` and removed at the end;
> `--keep` leaves it behind for inspection. It writes `tests/results.json` and exits non-zero if
> any case fails.

## What each block guards

### A — Search phrase and terms (6 cases)

The plugin must ask the *same question* the original asks, or it is a different feature wearing
the same name.

- **A01–A02** — the phrase is the article's keywords; an article without keywords has no phrase,
  which is the case where the original shows nothing.
- **A03–A05** — an empty phrase yields no terms; terms come back lowercased, as the index stores
  them; duplicates are dropped.
- **A06** — *no more terms than the original used.* The 20-term cap is part of the contract:
  the original passes it to `searchPhrase()`, and a different cap would give different results.

### B — Co-occurrence over the search index (9 cases)

- **B01–B04** — articles sharing terms are found, the article itself never is, more shared terms
  rank higher, and an article with nothing in common does not appear.
- **B05–B06** — no terms means no query at all; the limit is honoured.
- **B07** — an unpublished article drops out, which is what keeps withdrawn work from being
  recommended.
- **B08** — a journal only ever recommends its own articles.
- **B09** — **the ranking agrees with the core `ORDERBY_SEARCH_RANKING`.** This is the case that
  justifies the whole plugin: it runs the original's own slow query once, for one article, and
  compares. It is the guarantee that replacing two correlated subqueries with a single grouped
  scan changed the cost and not the answer.

### C — Store (10 cases)

- **C01–C02** — enrolment, and only for the journals asked about.
- **C03** — refresh stores both the list and the phrase it came from; the phrase is what the
  "refine this search" link uses, so losing it would break a link on the page.
- **C04** — *an article with no keywords is still marked as computed.* Without this it would be
  picked up as pending on every single run, for ever.
- **C05–C07** — the storage cap, paging, and that refreshing replaces rather than accumulates.
- **C08** — invalidation clears `computed_at` **and** bumps the version, which is what makes the
  already-rendered HTML unreachable.
- **C09–C10** — the queue order and the batch size; the enrolment cap.

### D — End to end (4 cases)

- **D01** — an article published later becomes recommendable to an earlier one.
- **D02** — the `Publication::publish` hook queues the article.
- **D03–D04** — deleting a submission removes its rows through the foreign keys and takes it out
  of everybody else's list. This is what makes uninstalling safe.

### E — Guards (5 cases)

- **E01–E03** — unknown submissions, empty batches and terms that exist nowhere are all harmless.
- **E04** — the defaults are the safe ones, above all `computeOnDemand = 0`: with it off, an
  article that has not been searched yet shows no section and costs one indexed query.
- **E05** — no orphan rows in either table, in either direction.

## What is not covered here

- **Load.** The performance figures in the README were measured on a production journal, not
  asserted by a test. A regression in speed would not fail these suites.
- **Search index freshness.** The plugin reads the index OJS maintains; if that index is stale,
  the lists follow it. Rebuilding it is the administrator's job (`tools/rebuildSearchIndex.php`).
- **Multi-journal isolation** is asserted from one journal (B08 checks that nothing foreign comes
  back). A second journal would make it stronger; the suite does not create one.
