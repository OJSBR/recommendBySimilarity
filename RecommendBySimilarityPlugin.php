<?php

/**
 * @file plugins/generic/recommendBySimilarity/RecommendBySimilarityPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendBySimilarityPlugin
 *
 * @brief Plugin to recommend similar articles.
 *
 * Same feature as the original plugin, computed somewhere else. The article
 * page reads a list that is already written down; a scheduled task keeps that
 * list current, a slice per run. See classes/SimilarityFinder.php for why the
 * original search costs twenty seconds and this one costs a hundredth of that.
 */

namespace APP\plugins\generic\recommendBySimilarity;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\recommendBySimilarity\classes\migration\install\SchemaMigration;
use APP\plugins\generic\recommendBySimilarity\classes\RecommendationStore;
use APP\plugins\generic\recommendBySimilarity\classes\SimilarityFinder;
use APP\plugins\generic\recommendBySimilarity\classes\tasks\RefreshRecommendations;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\NotificationManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class RecommendBySimilarityPlugin extends GenericPlugin implements HasTaskScheduler
{
    public const DEFAULTS = [
        'recommendationCount' => 10,
        'maxRecommendations' => 50,
        'batchSize' => 250,
        'queueLimit' => 0,
        'maxAgeDays' => 30,
        'computeOnDemand' => 0,
        'htmlCacheHours' => 168,
        'cacheStamp' => 1,
    ];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if (!Application::isUnderMaintenance() && $this->getEnabled($mainContextId)) {
            Hook::add('Templates::Article::Footer::PageFooter', $this->callbackTemplateArticlePageFooter(...));

            // A published or withdrawn article changes what its own keywords
            // find. What it changes for *other* articles is picked up by the
            // rolling refresh: there is no cheap way to name them, and unlike
            // shared authorship, one new article rarely reorders anybody's top
            // ten.
            Hook::add('Publication::publish', $this->invalidateFromPublication(...));
            Hook::add('Publication::unpublish', $this->invalidateFromPublication(...));
            Hook::add('Publication::delete', $this->invalidateFromPublication(...));
        }

        return true;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration(): SchemaMigration
    {
        return new SchemaMigration();
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        if (!$this->enabledContextIds()) {
            return;
        }

        $scheduler
            ->addSchedule(new RefreshRecommendations([]))
            ->everyFifteenMinutes()
            ->name(RefreshRecommendations::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.recommendBySimilarity.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.recommendBySimilarity.description');
    }

    /**
     * A setting of this plugin, or its default.
     *
     * @return mixed
     */
    public function getPluginSetting(string $name)
    {
        $value = $this->getSetting($this->settingsContextId(), $name);

        return $value === null || $value === '' ? self::DEFAULTS[$name] : $value;
    }

    public function settingsContextId(): ?int
    {
        return $this->isSitePlugin() ? Application::SITE_CONTEXT_ID : $this->getCurrentContextId();
    }

    /**
     * The journals this plugin is enabled for.
     *
     * The scheduled task runs with no journal in the request, so getEnabled()
     * has nothing to answer about; the settings are the only thing that is
     * true regardless of who is asking.
     *
     * @return int[]
     */
    public function enabledContextIds(): array
    {
        $enabled = DB::table('plugin_settings')
            ->where('plugin_name', $this->getName())
            ->where('setting_name', 'enabled')
            ->whereIn('setting_value', ['1', 'true'])
            ->pluck('context_id');

        if ($enabled->contains(null)) {
            return Application::getContextDAO()->getAll(true)
                ->map(fn ($context) => (int) $context->getId())
                ->toArray();
        }

        return $enabled->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);

        // A site administrator reaches this grid with no journal in the
        // request, and the plugin is enabled per journal; asking getEnabled()
        // there answers "no" and the Settings link disappears for exactly the
        // person most likely to want it.
        $contextId = $this->getCurrentContextId();
        $enabled = $contextId ? $this->getEnabled($contextId) : (bool) $this->enabledContextIds();
        if (!$enabled) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new RecommendBySimilaritySettingsForm($this);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * Add content to the article footer.
     */
    public function callbackTemplateArticlePageFooter($hookName, $params): bool
    {
        $smarty = & $params[1];
        $output = & $params[2];

        $submission = $smarty->getTemplateVars('article');
        if (!$submission instanceof Submission) {
            return Hook::CONTINUE;
        }

        $submissionId = $submission->getId();
        $store = new RecommendationStore();
        $state = $store->stateOf($submissionId);

        if (!$state || $state->computed_at === null) {
            if (!$this->getPluginSetting('computeOnDemand')) {
                return Hook::CONTINUE;
            }
            $store->enqueueOne($submissionId, $submission->getData('contextId'));
            $store->refresh(
                [$submissionId],
                (int) $submission->getData('contextId'),
                new SimilarityFinder(),
                (int) $this->getPluginSetting('maxRecommendations')
            );
            $state = $store->stateOf($submissionId);
        }

        $request = Application::get()->getRequest();
        $rangeInfo = Handler::getRangeInfo($request, 'articlesBySimilarity');
        $page = $rangeInfo && $rangeInfo->isValid() ? $rangeInfo->getPage() : 1;

        $key = implode(':', [
            'recommendBySimilarity', 'html', $submissionId, $state->version,
            $this->getPluginSetting('cacheStamp'), Locale::getLocale(), $page,
        ]);
        $output .= Cache::remember(
            $key,
            now()->addHours((int) $this->getPluginSetting('htmlCacheHours')),
            fn () => $this->render($store, $state, $submissionId, $page)
        );

        return Hook::CONTINUE;
    }

    /**
     * Queues the changed submission for the next run.
     */
    public function invalidateFromPublication(string $hookName, array $args): bool
    {
        $publication = $args[0];
        if ($submissionId = (int) $publication->getData('submissionId')) {
            (new RecommendationStore())->invalidate([$submissionId]);
        }

        return Hook::CONTINUE;
    }

    /**
     * The section as the reader sees it, built from the stored list.
     */
    private function render(RecommendationStore $store, object $state, int $submissionId, int $page): string
    {
        $perPage = max(1, (int) $this->getPluginSetting('recommendationCount'));
        $offset = ($page - 1) * $perPage;

        $recommendedIds = $store->read($submissionId, $offset, $perPage);
        if (!$recommendedIds) {
            return '';
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        // The collector refuses to run without a context, and its result is a
        // lazy collection: walking it twice would run the query again.
        $byId = [];
        foreach (
            Repo::submission()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterBySubmissionIds($recommendedIds)
                ->getMany() as $recommended
        ) {
            $byId[$recommended->getId()] = $recommended;
        }

        $ordered = [];
        foreach ($recommendedIds as $recommendedId) {
            if (isset($byId[$recommendedId])) {
                $ordered[] = $byId[$recommendedId];
            }
        }
        if (!$ordered) {
            return '';
        }

        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByIssueIds(array_values(array_unique(array_filter(array_map(
                fn (Submission $submission) => $submission->getCurrentPublication()?->getData('issueId'),
                $ordered
            )))))
            ->getMany();

        $total = $store->total($submissionId);
        $templateManager = TemplateManager::getManager($request);
        $templateManager->assign('articlesBySimilarity', (object) [
            'submissions' => $ordered,
            'issues' => $issues,
            'query' => (string) ($state->search_phrase ?? ''),
            'start' => $offset + 1,
            'end' => $offset + count($ordered),
            'total' => $total,
            'previousUrl' => $page > 1 ? $this->pageUrl($request, $submissionId, $page - 1) : null,
            'nextUrl' => $offset + $perPage < $total ? $this->pageUrl($request, $submissionId, $page + 1) : null,
        ]);

        return $templateManager->fetch($this->getTemplateResource('articleFooter.tpl'));
    }

    private function pageUrl($request, int $submissionId, int $page): string
    {
        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            newContext: $request->getContext()?->getPath(),
            handler: 'article',
            op: 'view',
            path: [$submissionId],
            params: ['articlesBySimilarityPage' => $page],
            urlLocaleForPage: ''
        );
    }
}
