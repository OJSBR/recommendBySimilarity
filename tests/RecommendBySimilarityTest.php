<?php

/**
 * @file plugins/generic/recommendBySimilarity/tests/RecommendBySimilarityTest.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendBySimilarityTest
 *
 * @brief The search phrase of an article, what it is recommended next to, and
 *        what the store keeps.
 *
 *        These checks create and delete submissions of their own, so they run
 *        only where that is acceptable: an installation with many published
 *        articles looks like a live journal and the suite skips itself. The
 *        term rules alone are in SimilarityFinderTest.
 */

namespace APP\plugins\generic\recommendBySimilarity\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\recommendBySimilarity\classes\RecommendationStore;
use APP\plugins\generic\recommendBySimilarity\classes\SimilarityFinder;
use APP\plugins\generic\recommendBySimilarity\RecommendBySimilarityPlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\PKPRequest;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\tests\PKPTestCase;
use PKP\userGroup\UserGroup;

#[CoversClass(SimilarityFinder::class)]
#[CoversClass(RecommendationStore::class)]
class RecommendBySimilarityTest extends PKPTestCase
{
    private const CONTEXT_ID = 1;
    private const MARKER = '[RBS-TESTS]';
    private const PRODUCTION_LOOKS_LIKE = 100;

    /** Submission ids created by this class. */
    private static array $created = [];

    /** The fixtures shared by the tests, by name. */
    private static array $ids = [];

    private SimilarityFinder $finder;
    private RecommendationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessTestInstallation();
        $this->pinContext();

        $this->finder = new SimilarityFinder();
        $this->store = new RecommendationStore();

        if (!self::$ids) {
            $this->buildFixtures();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeCreated();
        self::$ids = [];
        parent::tearDownAfterClass();
    }

    /**
     * The suite creates and deletes submissions: acceptable on a test
     * installation, never on a live journal.
     */
    private function skipUnlessTestInstallation(): void
    {
        foreach (['recommend_similarity_cache', 'recommend_similarity_state'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped('the plugin has never been enabled here, so its tables are missing');
            }
        }
        if (!Application::getContextDAO()->getById(self::CONTEXT_ID)) {
            $this->markTestSkipped('journal ' . self::CONTEXT_ID . ' does not exist');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published articles: it looks like a live journal');
        }
    }

    /** There is no URL on the command line, so the journal is pinned on the router. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $request->setRouter($router);
    }

    /** A published article with the given keywords, in the search index. */
    private function publishedSubmission(string $title, array $keywords): int
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $userGroupId = UserGroup::withContextIds([self::CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first()?->id
            ?? UserGroup::withContextIds([self::CONTEXT_ID])->first()?->id;
        $sectionId = DB::table('sections')->where('journal_id', self::CONTEXT_ID)->value('section_id');
        $issueId = DB::table('issues')->where('journal_id', self::CONTEXT_ID)->value('issue_id');

        $submission = Repo::submission()->newDataObject([
            'contextId' => self::CONTEXT_ID,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => '',
            'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
            'locale' => 'en',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => ['en' => self::MARKER . ' ' . $title],
            'sectionId' => $sectionId,
            'issueId' => $issueId,
            'locale' => 'en',
            'status' => Submission::STATUS_QUEUED,
            'datePublished' => '2026-01-15',
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $context);
        self::$created[] = $submissionId;

        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();

        $author = Repo::author()->newDataObject([
            'publicationId' => $publication->getId(),
            'givenName' => ['en' => 'Rbs'],
            'familyName' => ['en' => 'Tests'],
            'userGroupId' => $userGroupId,
            'seq' => 0,
            'includeInBrowse' => true,
            'email' => 'rbs.tests@example.org',
            'country' => 'BR',
        ]);
        $authorId = Repo::author()->add($author);
        Repo::publication()->edit($publication, ['primaryContactId' => $authorId]);
        $publication = Repo::publication()->get($publication->getId());

        if ($keywords) {
            Repo::publication()->edit($publication, ['keywords' => ['en' => $keywords]]);
            $publication = Repo::publication()->get($publication->getId());
        }

        Repo::publication()->publish($publication);

        // The plugin reads the search index, so the article has to be in it.
        $submission = Repo::submission()->get($submissionId);
        Application::getSubmissionSearchIndex()->submissionMetadataChanged($submission);
        Application::getSubmissionSearchIndex()->submissionChangesFinished();

        return $submissionId;
    }

    /** Three articles that overlap on purpose, one that does not, one with no keywords. */
    private function buildFixtures(): void
    {
        self::removeCreated();

        self::$ids = [
            'shared' => $this->publishedSubmission('shared one', ['active methodologies', 'teacher education', 'higher education']),
            'overlapTwo' => $this->publishedSubmission('shared two', ['teacher education', 'higher education']),
            'overlapOne' => $this->publishedSubmission('shared three', ['teacher education', 'school management']),
            'unrelated' => $this->publishedSubmission('unrelated', ['zebrafish', 'bioluminescence']),
            'noKeywords' => $this->publishedSubmission('no keywords', []),
        ];
    }

    private static function removeCreated(): void
    {
        foreach (array_unique(self::$created) as $submissionId) {
            if ($submission = Repo::submission()->get($submissionId)) {
                Repo::submission()->delete($submission);
            }
        }
        $stale = DB::table('publication_settings as ps')
            ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
            ->where('ps.setting_name', 'title')
            ->where('ps.setting_value', 'like', '%' . self::MARKER . '%')
            ->distinct()->pluck('p.submission_id');
        foreach ($stale as $submissionId) {
            if ($submission = Repo::submission()->get((int) $submissionId)) {
                Repo::submission()->delete($submission);
            }
        }
        self::$created = [];
    }

    /** Only this class's own submissions, so a populated journal does not disturb an assertion. */
    private function onlyOurs(array $ids): array
    {
        return array_values(array_intersect($ids, self::$created));
    }

    private function termsOf(int $submissionId): array
    {
        return $this->finder->terms($this->finder->searchPhrase($submissionId));
    }

    public function testTheSearchPhraseOfAnArticleIsItsKeywords(): void
    {
        $phrase = $this->finder->searchPhrase(self::$ids['shared']);
        foreach (['methodologies', 'teacher', 'higher'] as $word) {
            $this->assertStringContainsString($word, $phrase);
        }

        $this->assertSame('', $this->finder->searchPhrase(self::$ids['noKeywords']));
        $this->assertSame([], $this->finder->terms(''));
    }

    public function testTermsComeFromTheCoreLowercasedAndWithoutRepetition(): void
    {
        $terms = $this->termsOf(self::$ids['shared']);

        $this->assertNotEmpty($terms);
        foreach ($terms as $term) {
            $this->assertSame(mb_strtolower($term, 'UTF-8'), $term, 'terms are indexed in lower case');
        }
        $this->assertSame(count(array_unique($terms)), count($terms), 'a term is asked for once');

        $many = [];
        for ($i = 0; $i < SimilarityFinder::MAX_SEARCH_KEYWORDS + 20; $i++) {
            $many[] = 'term' . $i;
        }
        $this->assertLessThanOrEqual(
            SimilarityFinder::MAX_SEARCH_KEYWORDS,
            count($this->finder->terms(implode(' ', $many))),
            'no more terms than the original plugin asked the index for'
        );
    }

    public function testArticlesSharingTermsAreFoundAndRankedByHowMuchTheyShare(): void
    {
        $found = $this->onlyOurs($this->finder->find(self::$ids['shared'], self::CONTEXT_ID, $this->termsOf(self::$ids['shared']), 50));

        $this->assertContains(self::$ids['overlapTwo'], $found);
        $this->assertContains(self::$ids['overlapOne'], $found);
        $this->assertNotContains(self::$ids['shared'], $found, 'an article must not recommend itself');
        $this->assertNotContains(self::$ids['unrelated'], $found, 'nothing in common');

        $positionOfTwo = array_search(self::$ids['overlapTwo'], $found, true);
        $positionOfOne = array_search(self::$ids['overlapOne'], $found, true);
        $this->assertLessThan($positionOfOne, $positionOfTwo, 'sharing more terms must rank higher');
    }

    public function testNoTermsMeansNoResultsAndTheLimitIsHonoured(): void
    {
        $this->assertSame([], $this->finder->find(self::$ids['shared'], self::CONTEXT_ID, [], 50));

        $terms = $this->termsOf(self::$ids['shared']);
        $this->assertLessThanOrEqual(1, count($this->finder->find(self::$ids['shared'], self::CONTEXT_ID, $terms, 1)));

        // A term no article carries brings nothing back.
        $this->assertSame([], $this->finder->find(self::$ids['shared'], self::CONTEXT_ID, ['zzzznothinghasthis'], 50));
    }

    public function testAnUnpublishedArticleIsNotRecommended(): void
    {
        $id = $this->publishedSubmission('unpublished', ['teacher education', 'higher education']);
        $terms = $this->termsOf(self::$ids['shared']);
        $this->assertContains($id, $this->onlyOurs($this->finder->find(self::$ids['shared'], self::CONTEXT_ID, $terms, 50)));

        $submission = Repo::submission()->get($id);
        Repo::publication()->unpublish($submission->getCurrentPublication());
        Application::getSubmissionSearchIndex()->submissionMetadataChanged(Repo::submission()->get($id));
        Application::getSubmissionSearchIndex()->submissionChangesFinished();

        $this->assertNotContains($id, $this->onlyOurs($this->finder->find(self::$ids['shared'], self::CONTEXT_ID, $terms, 50)));
    }

    public function testTheStoreKeepsTheListAndThePhraseItCameFromAndPagesThroughIt(): void
    {
        $this->store->enqueueNew([self::CONTEXT_ID]);
        $this->store->refresh([self::$ids['shared']], self::CONTEXT_ID, $this->finder, 50);

        $total = $this->store->total(self::$ids['shared']);
        $this->assertGreaterThan(0, $total);

        $all = $this->store->read(self::$ids['shared'], 0, $total);
        $this->assertNotContains(self::$ids['shared'], $all);
        $paged = array_merge(
            $this->store->read(self::$ids['shared'], 0, 1),
            $this->store->read(self::$ids['shared'], 1, $total)
        );
        $this->assertSame($all, $paged);

        // Computing again replaces the list, it does not add to it.
        $this->store->refresh([self::$ids['shared']], self::CONTEXT_ID, $this->finder, 50);
        $this->assertSame($total, $this->store->total(self::$ids['shared']));

        $this->assertNotNull($this->store->stateOf(self::$ids['shared']));
    }

    public function testAnArticleWithoutKeywordsIsComputedAndStoresNothing(): void
    {
        $this->store->refresh([self::$ids['noKeywords']], self::CONTEXT_ID, $this->finder, 50);

        $this->assertSame(0, $this->store->total(self::$ids['noKeywords']));
        $this->assertNotNull($this->store->stateOf(self::$ids['noKeywords']), 'it must still count as computed');
    }

    public function testTheStoredListIsCappedByTheConfiguredMaximum(): void
    {
        $this->store->refresh([self::$ids['shared']], self::CONTEXT_ID, $this->finder, 1);
        $this->assertLessThanOrEqual(1, $this->store->total(self::$ids['shared']));
    }

    public function testInvalidatingAnArticleQueuesItAgain(): void
    {
        $this->store->refresh([self::$ids['shared']], self::CONTEXT_ID, $this->finder, 50);
        $before = $this->store->stateOf(self::$ids['shared']);

        $this->store->invalidate([self::$ids['shared']]);
        $after = $this->store->stateOf(self::$ids['shared']);

        $this->assertNotNull($after);
        $this->assertNotEquals($before, $after, 'invalidating changed nothing');
    }

    public function testAnArticleThatWasNeverComputedAnswersEmptyAndAnEmptyBatchIsHarmless(): void
    {
        $unknown = (int) DB::table('submissions')->max('submission_id') + 100000;

        $this->assertSame([], $this->store->read($unknown, 0, 10));
        $this->assertSame(0, $this->store->total($unknown));
        $this->assertNull($this->store->stateOf($unknown));

        $this->store->refresh([], self::CONTEXT_ID, $this->finder, 50);
        $this->assertTrue(true, 'an empty batch must not fail');
    }

    public function testDeletingASubmissionTakesItsRowsWithIt(): void
    {
        $id = $this->publishedSubmission('deleted', ['teacher education']);
        $this->store->refresh([$id], self::CONTEXT_ID, $this->finder, 50);

        Repo::submission()->delete(Repo::submission()->get($id));

        foreach (['recommend_similarity_cache', 'recommend_similarity_state'] as $table) {
            $this->assertSame(0, DB::table($table)->where('submission_id', $id)->count(), $table . ' kept rows of a deleted article');
        }
        $this->assertSame(0, DB::table('recommend_similarity_cache')->where('recommended_submission_id', $id)->count());
    }

    public function testNoTableKeepsRowsOfArticlesThatNoLongerExist(): void
    {
        foreach (['recommend_similarity_cache', 'recommend_similarity_state'] as $table) {
            $orphans = DB::table($table . ' as t')
                ->leftJoin('submissions as s', 's.submission_id', '=', 't.submission_id')
                ->whereNull('s.submission_id')->count();
            $this->assertSame(0, $orphans, $table . ' has orphan rows');
        }
        $orphans = DB::table('recommend_similarity_cache as c')
            ->leftJoin('submissions as s', 's.submission_id', '=', 'c.recommended_submission_id')
            ->whereNull('s.submission_id')->count();
        $this->assertSame(0, $orphans, 'the cache points at deleted articles');
    }

    public function testTheDefaultsCannotSlowAJournalDown(): void
    {
        $defaults = RecommendBySimilarityPlugin::DEFAULTS;

        $this->assertSame(0, (int) $defaults['computeOnDemand'], 'computing while the reader waits must be off by default');
        $this->assertGreaterThan(0, (int) $defaults['batchSize']);
        $this->assertGreaterThan(0, (int) $defaults['maxAgeDays']);

        PluginRegistry::loadCategory('generic', false, self::CONTEXT_ID);
        $plugin = PluginRegistry::getPlugin('generic', 'recommendbysimilarityplugin');
        $this->assertNotNull($plugin, 'the plugin is not installed here');
        $this->assertIsArray($plugin->enabledContextIds());
    }
}
