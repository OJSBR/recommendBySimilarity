<?php

/**
 * @file tests/regression.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Regression suite for recommendBySimilarity.
 *
 *        Covers the term extraction, the co-occurrence query against the search
 *        index, the store, and the whole path from publishing an article with
 *        keywords to the section the reader sees. It creates its own
 *        submissions, indexes them, and deletes them again.
 *
 *        One case compares the ranking against the core's own
 *        ORDERBY_SEARCH_RANKING, which is the guarantee that this plugin
 *        answers the same question as the original — the slow way is run once,
 *        on purpose, for one article.
 *
 *        See tests/CASES.md.
 *
 * Usage: php plugins/generic/recommendBySimilarity/tests/regression.php [--keep]
 *
 *        Test installations only. Run it as the account that owns the files,
 *        never as root.
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\recommendBySimilarity\classes\RecommendationStore;
use APP\plugins\generic\recommendBySimilarity\classes\SimilarityFinder;
use APP\submission\Collector;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

const CONTEXT_ID = 1;
const PLUGIN = 'recommendbysimilarityplugin';
const MARKER = '[RBS-REGRESSION]';

$keep = in_array('--keep', $argv, true);

class RouterWithContext extends PageRouter
{
    private $pinned;
    public function pinContext($context) { $this->pinned = $context; }
    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context { return $this->pinned; }
}

$request = Application::get()->getRequest();
$context = Application::getContextDAO()->getById(CONTEXT_ID);
if (!$context) {
    exit("FATAL: journal " . CONTEXT_ID . " does not exist.\n");
}
$router = new RouterWithContext();
$router->setApplication(Application::get());
$router->pinContext($context);
$request->setRouter($router);

PluginRegistry::loadCategory('generic', false, CONTEXT_ID);
$plugin = PluginRegistry::getPlugin('generic', PLUGIN);
if (!$plugin) {
    exit("FATAL: plugin not installed.\n");
}
foreach (['recommend_similarity_cache', 'recommend_similarity_state'] as $table) {
    if (!Schema::hasTable($table)) {
        exit("FATAL: table {$table} is missing. Enable the plugin once so its migration runs.\n");
    }
}

// A suite creates and deletes submissions. That is fine on a test installation
// and unacceptable on a live one, and the only difference between the two, from
// in here, is how much is published. Anything above this looks like a real
// journal and the suite refuses to touch it.
const PRODUCTION_LOOKS_LIKE = 100;

$published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
if ($published > PRODUCTION_LOOKS_LIKE && !in_array('--yes-this-is-a-test-installation', $argv, true)) {
    exit(
        "REFUSING TO RUN: this installation has {$published} published submissions, which looks\n"
        . "like a live journal. This suite creates and deletes submissions.\n"
        . "If it really is a test installation, re-run with --yes-this-is-a-test-installation\n"
    );
}

//
// Tiny framework
//
$RESULTS = [];
$BLOCK = '';

function block(string $title): void
{
    global $BLOCK;
    $BLOCK = $title;
    echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
}

function testCase(string $id, string $title, callable $body): void
{
    global $RESULTS, $BLOCK;
    try {
        $body();
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => true, 'message' => ''];
        printf("  [ PASS ] %-6s %s\n", $id, $title);
    } catch (Throwable $e) {
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => false, 'message' => $e->getMessage()];
        printf("  [ FAIL ] %-6s %s\n            -> %s\n", $id, $title, str_replace("\n", "\n            ", $e->getMessage()));
    }
}

function assertEquals($expected, $actual, string $message = 'value differs from the expected one'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message
            . "\n              expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n              actual  : " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertNull($value, string $message): void
{
    if ($value !== null) {
        throw new RuntimeException($message . ' (got ' . json_encode($value, JSON_UNESCAPED_UNICODE) . ')');
    }
}

//
// Domain helpers
//
$CREATED = [];

/** A published submission with the given keywords, indexed for search. */
function newPublishedSubmission(string $title, array $keywords): int
{
    global $CREATED, $context;

    $userGroupId = UserGroup::withContextIds([CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first()?->id
        ?? UserGroup::withContextIds([CONTEXT_ID])->first()?->id;
    $sectionId = DB::table('sections')->where('journal_id', CONTEXT_ID)->value('section_id');
    $issueId = DB::table('issues')->where('journal_id', CONTEXT_ID)->value('issue_id');

    $submission = Repo::submission()->newDataObject([
        'contextId' => CONTEXT_ID,
        'status' => Submission::STATUS_QUEUED,
        'submissionProgress' => '',
        'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
        'locale' => 'pt_BR',
    ]);
    $publication = Repo::publication()->newDataObject([
        'title' => ['pt_BR' => MARKER . ' ' . $title],
        'sectionId' => $sectionId,
        'issueId' => $issueId,
        'locale' => 'pt_BR',
        'status' => Submission::STATUS_QUEUED,
        'datePublished' => '2026-01-15',
    ]);
    $submissionId = Repo::submission()->add($submission, $publication, $context);
    $CREATED[] = $submissionId;

    $submission = Repo::submission()->get($submissionId);
    $publication = $submission->getCurrentPublication();

    $author = Repo::author()->newDataObject([
        'publicationId' => $publication->getId(),
        'givenName' => ['pt_BR' => 'Autor'],
        'familyName' => ['pt_BR' => 'Regressao'],
        'userGroupId' => $userGroupId,
        'seq' => 0,
        'includeInBrowse' => true,
        'email' => 'rbs.regression@example.org',
        'country' => 'BR',
    ]);
    $authorId = Repo::author()->add($author);
    Repo::publication()->edit($publication, ['primaryContactId' => $authorId]);
    $publication = Repo::publication()->get($publication->getId());

    if ($keywords) {
        Repo::publication()->edit($publication, ['keywords' => ['pt_BR' => $keywords]]);
        $publication = Repo::publication()->get($publication->getId());
    }

    Repo::publication()->publish($publication);

    // The plugin reads the search index, so the article has to be in it.
    $submission = Repo::submission()->get($submissionId);
    Application::getSubmissionSearchIndex()->submissionMetadataChanged($submission);
    Application::getSubmissionSearchIndex()->submissionChangesFinished();

    return $submissionId;
}

function cleanUp(): void
{
    global $CREATED;
    foreach (array_unique($CREATED) as $submissionId) {
        if ($submission = Repo::submission()->get($submissionId)) {
            Repo::submission()->delete($submission);
        }
    }
    $stale = DB::table('publication_settings as ps')
        ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
        ->where('ps.setting_name', 'title')
        ->where('ps.setting_value', 'like', '%' . MARKER . '%')
        ->distinct()->pluck('p.submission_id');
    foreach ($stale as $submissionId) {
        if ($submission = Repo::submission()->get((int) $submissionId)) {
            Repo::submission()->delete($submission);
        }
    }
    $CREATED = [];
}

function onlyOurs(array $ids): array
{
    global $CREATED;
    return array_values(array_intersect($ids, $CREATED));
}

$store = new RecommendationStore();
$finder = new SimilarityFinder();

cleanUp();

echo "\nrecommendBySimilarity — regression suite\n";
echo "journal: " . $context->getPath() . "   plugin: " . $plugin->getCurrentVersion()?->getVersionString() . "\n";

//
// Fixtures: three articles that overlap on purpose, one that does not, one with
// no keywords at all.
//
$shared = newPublishedSubmission('shared-one', ['metodologias ativas', 'formação docente', 'ensino superior']);
$overlapTwo = newPublishedSubmission('shared-two', ['formação docente', 'ensino superior']);
$overlapOne = newPublishedSubmission('shared-three', ['formação docente', 'gestão escolar']);
$unrelated = newPublishedSubmission('unrelated', ['zebrafish', 'bioluminescência']);
$noKeywords = newPublishedSubmission('no-keywords', []);

//
// A. Terms
//
block('A. Search phrase and terms');

testCase('A01', 'the search phrase of an article is its keywords', function () use ($finder, $shared) {
    $phrase = $finder->searchPhrase($shared);
    foreach (['metodologias', 'ativas', 'docente'] as $word) {
        assertTrue(str_contains($phrase, $word), "phrase should contain '{$word}', got: {$phrase}");
    }
});

testCase('A02', 'an article with no keywords has an empty phrase', function () use ($finder, $noKeywords) {
    assertEquals('', $finder->searchPhrase($noKeywords));
});

testCase('A03', 'an empty phrase yields no terms', function () use ($finder) {
    assertEquals([], $finder->terms(''));
});

testCase('A04', 'terms are extracted by the core, so they match what was indexed', function () use ($finder) {
    $terms = $finder->terms('Metodologias Ativas');
    assertTrue(count($terms) > 0, 'expected some terms');
    foreach ($terms as $term) {
        assertEquals(mb_strtolower($term, 'UTF-8'), $term, 'terms should be lowercased like the index');
    }
});

testCase('A05', 'terms are deduplicated', function () use ($finder) {
    $terms = $finder->terms('docente docente docente');
    assertEquals(count(array_unique($terms)), count($terms), 'duplicates should be gone');
});

testCase('A06', 'no more terms than the original plugin used', function () use ($finder) {
    $many = [];
    for ($i = 0; $i < 60; $i++) {
        $many[] = 'palavra' . $i;
    }
    assertTrue(count($finder->terms(implode(' ', $many))) <= SimilarityFinder::MAX_SEARCH_KEYWORDS,
        'the 20-term cap of the original must be honoured');
});

//
// B. Finding similar articles
//
block('B. Co-occurrence over the search index');

testCase('B01', 'articles sharing terms are found', function () use ($finder, $shared, $overlapTwo, $overlapOne) {
    $found = onlyOurs($finder->find($shared, CONTEXT_ID, $finder->terms($finder->searchPhrase($shared)), 50));
    assertTrue(in_array($overlapTwo, $found, true), 'the two-term article should be found');
    assertTrue(in_array($overlapOne, $found, true), 'the one-term article should be found');
});

testCase('B02', 'the article itself is never in its own list', function () use ($finder, $shared) {
    $found = $finder->find($shared, CONTEXT_ID, $finder->terms($finder->searchPhrase($shared)), 50);
    assertTrue(!in_array($shared, $found, true), 'self-reference found');
});

testCase('B03', 'more shared terms rank higher', function () use ($finder, $shared, $overlapTwo, $overlapOne) {
    $found = onlyOurs($finder->find($shared, CONTEXT_ID, $finder->terms($finder->searchPhrase($shared)), 50));
    $posTwo = array_search($overlapTwo, $found, true);
    $posOne = array_search($overlapOne, $found, true);
    assertTrue($posTwo !== false && $posOne !== false, 'both should be found');
    assertTrue($posTwo < $posOne, 'the article sharing two terms must come before the one sharing one');
});

testCase('B04', 'an unrelated article is not recommended', function () use ($finder, $shared, $unrelated) {
    $found = $finder->find($shared, CONTEXT_ID, $finder->terms($finder->searchPhrase($shared)), 50);
    assertTrue(!in_array($unrelated, $found, true), 'nothing in common, should not appear');
});

testCase('B05', 'no terms means no results', function () use ($finder, $shared) {
    assertEquals([], $finder->find($shared, CONTEXT_ID, [], 50));
});

testCase('B06', 'the limit is honoured', function () use ($finder, $shared) {
    $found = $finder->find($shared, CONTEXT_ID, $finder->terms($finder->searchPhrase($shared)), 1);
    assertTrue(count($found) <= 1, 'limit ignored');
});

testCase('B07', 'unpublished articles are not recommended', function () use ($finder, $shared) {
    $temp = newPublishedSubmission('to-unpublish', ['formação docente', 'ensino superior']);
    $terms = $finder->terms($finder->searchPhrase($shared));
    assertTrue(in_array($temp, $finder->find($shared, CONTEXT_ID, $terms, 50), true), 'setup failed');

    $submission = Repo::submission()->get($temp);
    Repo::publication()->unpublish($submission->getCurrentPublication());

    assertTrue(!in_array($temp, $finder->find($shared, CONTEXT_ID, $terms, 50), true),
        'an unpublished article must drop out');
});

testCase('B08', 'a journal only recommends its own articles', function () use ($finder, $shared) {
    $terms = $finder->terms($finder->searchPhrase($shared));
    $found = $finder->find($shared, CONTEXT_ID, $terms, 500);
    if (!$found) {
        return;
    }
    $foreign = DB::table('submissions')->whereIn('submission_id', $found)
        ->where('context_id', '<>', CONTEXT_ID)->count();
    assertEquals(0, $foreign, 'results leaked from another journal');
});

testCase('B09', 'the ranking agrees with the core ORDERBY_SEARCH_RANKING', function () use ($finder, $shared) {
    // The slow way, run once on purpose: this is the guarantee that the plugin
    // answers the same question as the original.
    $phrase = $finder->searchPhrase($shared);
    $core = Repo::submission()->getCollector()
        ->excludeIds([$shared])
        ->filterByContextIds([CONTEXT_ID])
        ->filterByStatus([Submission::STATUS_PUBLISHED])
        ->searchPhrase($phrase, SimilarityFinder::MAX_SEARCH_KEYWORDS)
        ->limit(10)->offset(0)
        ->orderBy(Collector::ORDERBY_SEARCH_RANKING)
        ->getMany()->map(fn (Submission $s) => $s->getId())->all();

    $ours = $finder->find($shared, CONTEXT_ID, $finder->terms($phrase), 10);

    // Same set of best matches. Order within a tie is not guaranteed by either
    // side, so the sets are compared, not the sequences.
    sort($core);
    sort($ours);
    assertEquals($core, $ours, 'the plugin should find what the core search finds');
});

//
// C. The store
//
block('C. Recommendation store');

testCase('C01', 'enqueueNew enrols published submissions', function () use ($store, $shared) {
    $store->enqueueNew([CONTEXT_ID], 5000);
    assertTrue(DB::table('recommend_similarity_state')->where('submission_id', $shared)->exists(), 'not enrolled');
});

testCase('C02', 'enqueueNew ignores journals it was not asked about', function () use ($store) {
    assertEquals(0, $store->enqueueNew([], 5000));
});

testCase('C03', 'refresh stores the list and the phrase it came from', function () use ($store, $finder, $shared, $overlapTwo) {
    $store->refresh([$shared], CONTEXT_ID, $finder, 50);
    assertTrue(in_array($overlapTwo, $store->read($shared, 0, 100), true), 'expected result missing');
    $state = $store->stateOf($shared);
    assertTrue($state->computed_at !== null, 'computed_at not stamped');
    assertTrue(!empty($state->search_phrase), 'the search phrase should be kept for the "refine" link');
});

testCase('C04', 'an article with no keywords is computed and stores nothing', function () use ($store, $finder, $noKeywords) {
    $store->refresh([$noKeywords], CONTEXT_ID, $finder, 50);
    assertEquals([], $store->read($noKeywords, 0, 100), 'nothing to recommend');
    $state = $store->stateOf($noKeywords);
    assertTrue($state->computed_at !== null, 'it must still count as computed, or it is retried for ever');
    assertNull($state->search_phrase, 'no phrase to keep');
});

testCase('C05', 'maxRecommendations caps what is stored', function () use ($store, $finder, $shared) {
    $store->refresh([$shared], CONTEXT_ID, $finder, 1);
    assertTrue($store->total($shared) <= 1, 'the cap must be honoured');
    $store->refresh([$shared], CONTEXT_ID, $finder, 50);
});

testCase('C06', 'read pages through the stored list', function () use ($store, $shared) {
    $all = $store->read($shared, 0, 100);
    if (count($all) < 2) {
        return;
    }
    assertEquals(array_slice($all, 0, 1), $store->read($shared, 0, 1), 'first page');
    assertEquals(array_slice($all, 1, 1), $store->read($shared, 1, 1), 'second page');
    assertEquals(count($all), $store->total($shared), 'total must match');
});

testCase('C07', 'refreshing replaces, it does not accumulate', function () use ($store, $finder, $shared) {
    $before = $store->total($shared);
    $store->refresh([$shared], CONTEXT_ID, $finder, 50);
    assertEquals($before, $store->total($shared));
});

testCase('C08', 'invalidate re-queues and bumps the version', function () use ($store, $shared) {
    $before = $store->stateOf($shared);
    $store->invalidate([$shared]);
    $after = $store->stateOf($shared);
    assertNull($after->computed_at, 'computed_at should be cleared');
    assertTrue((int) $after->version > (int) $before->version, 'version should move');
});

testCase('C09', 'due returns the never-computed first and respects the batch', function () use ($store, $shared) {
    $due = $store->due([CONTEXT_ID], 500, 30);
    assertTrue(in_array($shared, $due[CONTEXT_ID] ?? [], true), 'the invalidated one should be due');
    assertEquals(1, count($store->due([CONTEXT_ID], 1, 30)[CONTEXT_ID] ?? []), 'batch size must bound the slice');
});

testCase('C10', 'queueLimit caps how many submissions are ever enrolled', function () use ($store) {
    $enrolled = DB::table('recommend_similarity_state')->count();
    assertEquals(0, $store->enqueueNew([CONTEXT_ID], 5000, $enrolled), 'no room left');
});

//
// D. End to end
//
block('D. From publishing to the reader');

testCase('D01', 'a newly published article becomes recommendable', function () use ($store, $finder) {
    $first = newPublishedSubmission('e2e-first', ['termorarissimo', 'outrotermorarissimo']);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$first], CONTEXT_ID, $finder, 50);
    assertEquals([], onlyOurs($store->read($first, 0, 100)), 'nothing similar yet');

    $second = newPublishedSubmission('e2e-second', ['termorarissimo', 'outrotermorarissimo']);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$first], CONTEXT_ID, $finder, 50);
    assertEquals([$second], onlyOurs($store->read($first, 0, 100)), 'the new article should be found');
});

testCase('D02', 'publishing queues the article for recomputation', function () use ($plugin, $store, $finder) {
    $id = newPublishedSubmission('hook', ['gancho', 'publicacao']);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$id], CONTEXT_ID, $finder, 50);
    assertTrue($store->stateOf($id)?->computed_at !== null, 'setup failed');

    $plugin->invalidateFromPublication('Publication::publish', [
        Repo::submission()->get($id)->getCurrentPublication(),
    ]);

    assertNull($store->stateOf($id)?->computed_at, 'the hook should have queued it');
});

testCase('D03', 'deleting a submission takes its rows with it', function () use ($store, $finder) {
    $id = newPublishedSubmission('to-delete', ['efemero', 'apagado']);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$id], CONTEXT_ID, $finder, 50);
    Repo::submission()->delete(Repo::submission()->get($id));

    assertEquals(0, DB::table('recommend_similarity_state')->where('submission_id', $id)->count(),
        'state row should have been cascaded away');
    assertEquals(0, DB::table('recommend_similarity_cache')->where('submission_id', $id)->count(),
        'cache rows should have been cascaded away');
});

testCase('D04', 'a deleted article stops being recommended to others', function () use ($store, $finder) {
    $keeper = newPublishedSubmission('keeper', ['sobrevivente', 'termocomum']);
    $doomed = newPublishedSubmission('doomed', ['sobrevivente', 'termocomum']);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$keeper], CONTEXT_ID, $finder, 50);
    assertTrue(in_array($doomed, $store->read($keeper, 0, 100), true), 'setup failed');

    Repo::submission()->delete(Repo::submission()->get($doomed));

    assertTrue(!in_array($doomed, $store->read($keeper, 0, 100), true),
        'the deleted article must not stay in anybody else list');
});

//
// E. Guards
//
block('E. Guards and edge cases');

testCase('E01', 'reading a submission that was never computed is harmless', function () use ($store) {
    assertEquals([], $store->read(99999999, 0, 10));
    assertEquals(0, $store->total(99999999));
    assertNull($store->stateOf(99999999), 'no state expected');
});

testCase('E02', 'an empty batch does nothing and does not fail', function () use ($store, $finder) {
    $store->refresh([], CONTEXT_ID, $finder, 50);
    assertEquals([], $finder->find(1, CONTEXT_ID, [], 50));
});

testCase('E03', 'terms that are in no article at all return nothing', function () use ($finder, $shared) {
    assertEquals([], $finder->find($shared, CONTEXT_ID, ['xyzzyqwertyuiopasdfgh'], 50));
});

testCase('E04', 'the plugin settings have safe defaults', function () use ($plugin) {
    assertEquals(0, (int) $plugin::DEFAULTS['computeOnDemand'],
        'searching while the reader waits must be off by default');
    assertTrue((int) $plugin::DEFAULTS['batchSize'] > 0, 'a batch size is required');
    assertTrue((int) $plugin::DEFAULTS['maxAgeDays'] > 0, 'a refresh age is required');
});

testCase('E05', 'no plugin table has rows pointing at submissions that no longer exist', function () {
    foreach (['recommend_similarity_state', 'recommend_similarity_cache'] as $table) {
        $orphans = DB::table($table . ' as t')
            ->leftJoin('submissions as s', 's.submission_id', '=', 't.submission_id')
            ->whereNull('s.submission_id')->count();
        assertEquals(0, $orphans, "{$table} has orphan rows");
    }
    $orphans = DB::table('recommend_similarity_cache as c')
        ->leftJoin('submissions as s', 's.submission_id', '=', 'c.recommended_submission_id')
        ->whereNull('s.submission_id')->count();
    assertEquals(0, $orphans, 'recommend_similarity_cache points at deleted submissions');
});

//
// Wrap up
//
if (!$keep) {
    cleanUp();
    echo "\n(test submissions removed)\n";
} else {
    echo "\n(--keep: test submissions left behind)\n";
}

$failed = array_values(array_filter($RESULTS, fn ($r) => !$r['ok']));
$passed = count($RESULTS) - count($failed);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d cases: %d passed, %d failed\n", count($RESULTS), $passed, count($failed));
foreach ($failed as $failure) {
    printf("  FAIL %-6s %s\n", $failure['id'], $failure['title']);
}
echo str_repeat('=', 78) . "\n";

file_put_contents(
    __DIR__ . '/results.json',
    json_encode(['passed' => $passed, 'failed' => count($failed), 'cases' => $RESULTS], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

exit(count($failed) === 0 ? 0 : 1);
