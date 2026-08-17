<?php

/**
 * @file plugins/generic/recommendBySimilarity/RecommendBySimilarityPlugin.inc.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendBySimilarityPlugin
 * @ingroup plugins_generic_recommendBySimilarity
 *
 * @brief Plugin to recommend similar articles.
 *
 * Same feature as the original plugin, computed somewhere else. The article
 * page reads a list that is already written down; a scheduled task keeps that
 * list current, a slice per run. See classes/RecommendBySimilarityFinder.inc.php for why
 * the original is expensive.
 *
 * Ported from the 3.5 branch. Everything that differs is a 3.3 API, never a
 * change of behaviour.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.recommendBySimilarity.classes.RecommendBySimilarityFinder');
import('plugins.generic.recommendBySimilarity.classes.RecommendBySimilarityStore');

class RecommendBySimilarityPlugin extends GenericPlugin {
	/**
	 * The defaults are the safe ones: a submission that has not been computed
	 * yet shows nothing at all rather than searching while a reader waits
	 * (computeOnDemand), and each run of the task does a bounded amount of
	 * work (batchSize) whatever the size of the journal.
	 */
	const DEFAULTS = array(
		'recommendationCount' => 10,
		'maxRecommendations' => 50,
		'batchSize' => 250,
		'queueLimit' => 0,
		'maxAgeDays' => 30,
		'computeOnDemand' => 0,
		'htmlCacheHours' => 168,
		// Bumped whenever the settings are saved, so that a change shows up on
		// the next page view instead of whenever the cache happens to expire.
		'cacheStamp' => 1,
	);

	/** @var array issue id => Issue, for one render */
	private $issueCache = array();

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		// 3.3 has no Application::isUnderMaintenance(); this is the pair of
		// conditions the core plugins of this release test instead.
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
			return $success;
		}

		if ($success && $this->getEnabled()) {
			HookRegistry::register('Templates::Article::Footer::PageFooter', array($this, 'callbackTemplateArticlePageFooter'));

			HookRegistry::register('Publication::publish', array($this, 'invalidateFromPublication'));
			HookRegistry::register('Publication::unpublish', array($this, 'invalidateFromPublication'));
			HookRegistry::register('Publication::delete', array($this, 'invalidateFromPublication'));
		}

		// Registered whether or not the plugin is on for the current journal:
		// the crontab is parsed once for the whole installation.
		HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));

		return $success;
	}

	/**
	 * @copydoc Plugin::getInstallMigration()
	 */
	function getInstallMigration() {
		import('plugins.generic.recommendBySimilarity.classes.RecommendBySimilaritySchemaMigration');
		return new RecommendBySimilaritySchemaMigration();
	}

	/**
	 * @see AcronPlugin::parseCronTab()
	 */
	function callbackParseCronTab($hookName, $args) {
		$taskFilesPath =& $args[0];
		$taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
		return false;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.recommendBySimilarity.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.recommendBySimilarity.description');
	}

	/**
	 * A setting of this plugin, or its default.
	 */
	function getPluginSetting($name) {
		$value = $this->getSetting($this->settingsContextId(), $name);
		return ($value === null || $value === '') ? self::DEFAULTS[$name] : $value;
	}

	/**
	 * In 3.3 the site context is CONTEXT_ID_NONE, which is 0 rather than the
	 * null of later releases.
	 */
	function settingsContextId() {
		return $this->isSitePlugin() ? CONTEXT_ID_NONE : $this->getCurrentContextId();
	}

	/**
	 * The journals this plugin is enabled for.
	 *
	 * The scheduled task runs with no journal in the request, so getEnabled()
	 * has nothing to answer about; reading the settings directly is the only
	 * thing that is true regardless of who is asking.
	 *
	 * @return array int[]
	 */
	function enabledContextIds() {
		$enabled = Capsule::table('plugin_settings')
			->where('plugin_name', $this->getName())
			->where('setting_name', 'enabled')
			->whereIn('setting_value', array('1', 'true'))
			->pluck('context_id');

		$forWholeSite = false;
		foreach ($enabled as $contextId) {
			if ($contextId === null || (int) $contextId === CONTEXT_ID_NONE) {
				$forWholeSite = true;
				break;
			}
		}

		if ($forWholeSite) {
			$all = array();
			$contexts = Application::getContextDAO()->getAll(true);
			while ($context = $contexts->next()) {
				$all[] = (int) $context->getId();
			}
			return $all;
		}

		$ids = array();
		foreach ($enabled as $contextId) {
			$ids[] = (int) $contextId;
		}
		return $ids;
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $actionArgs) {
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}

		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$url = $request->getRouter()->url($request, null, null, 'manage', null, array(
			'verb' => 'settings',
			'plugin' => $this->getName(),
			'category' => 'generic',
		));
		array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings'), null));

		return $actions;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		if ($request->getUserVar('verb') !== 'settings') {
			return parent::manage($args, $request);
		}

		$this->import('RecommendBySimilaritySettingsForm');
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
		$notificationManager = new NotificationManager();
		$notificationManager->createTrivialNotification($request->getUser()->getId());

		return new JSONMessage(true);
	}

	/**
	 * Add content to the article footer.
	 */
	function callbackTemplateArticlePageFooter($hookName, $params) {
		$smarty =& $params[1];
		$output =& $params[2];

		$submission = $smarty->getTemplateVars('article');
		if (!is_a($submission, 'Submission')) {
			return false;
		}

		$submissionId = (int) $submission->getId();
		$store = new RecommendBySimilarityStore();
		$state = $store->stateOf($submissionId);

		if (!$state || $state->computed_at === null) {
			if (!$this->getPluginSetting('computeOnDemand')) {
				return false;
			}
			$contextId = (int) $submission->getData('contextId');
			$store->enqueueOne($submissionId, $contextId);
			$store->refresh(array($submissionId), $contextId, new RecommendBySimilarityFinder(), (int) $this->getPluginSetting('maxRecommendations'));
			$state = $store->stateOf($submissionId);
			if (!$state) {
				return false;
			}
		}

		$request = Application::get()->getRequest();
		$rangeInfo = Handler::getRangeInfo($request, 'articlesBySimilarity');
		$page = ($rangeInfo && $rangeInfo->isValid()) ? $rangeInfo->getPage() : 1;

		$output .= $this->cachedRender($store, $state, $submissionId, $page);

		return false;
	}

	/**
	 * The rendered section, from the file cache when it is there.
	 *
	 * 3.3 has no Cache facade, and the file cache is one file per cache id
	 * rather than one entry per key, so the whole set of renderings of one
	 * submission lives in a single file, keyed by version, settings stamp,
	 * locale and page. Entries from an older version are dropped on write, so
	 * the file cannot grow without bound.
	 */
	private function cachedRender($store, $state, $submissionId, $page) {
		$version = (int) $state->version;
		$hours = (int) $this->getPluginSetting('htmlCacheHours');
		$key = implode(':', array($version, $this->getPluginSetting('cacheStamp'), AppLocale::getLocale(), $page));

		$cache = CacheManager::getManager()->getFileCache(
			'recommendBySimilarity',
			'html-' . $submissionId,
			function ($cache, $id) { $cache->setEntireCache(array()); return array(); }
		);

		$cachedAt = $cache->getCacheTime();
		if ($cachedAt !== null && $hours > 0 && (time() - $cachedAt) > $hours * 3600) {
			$cache->flush();
		}

		$contents = $cache->getContents();
		if (!is_array($contents)) {
			$contents = array();
		}
		if (isset($contents[$key])) {
			return $contents[$key];
		}

		$html = $this->render($store, $state, $submissionId, $page);

		$prefix = $version . ':';
		foreach (array_keys($contents) as $existing) {
			if (strpos($existing, $prefix) !== 0) {
				unset($contents[$existing]);
			}
		}
		$contents[$key] = $html;
		$cache->setEntireCache($contents);

		return $html;
	}

	/**
	 * Queues the changed submission for the next run.
	 */
	function invalidateFromPublication($hookName, $args) {
		$publication = $args[0];
		$submissionId = (int) $publication->getData('submissionId');
		if ($submissionId) {
			$store = new RecommendBySimilarityStore();
			$store->invalidate(array($submissionId));
		}
		return false;
	}

	/**
	 * The section as the reader sees it, built from the stored list.
	 *
	 * 3.4 introduced the Repo collectors the 3.5 branch reads through; here the
	 * submissions are fetched one by one from the DAO. They are primary-key
	 * reads of at most "recommendations per page" rows, so this is not the
	 * query the plugin exists to avoid.
	 */
	private function render($store, $state, $submissionId, $page) {
		$perPage = max(1, (int) $this->getPluginSetting('recommendationCount'));
		$offset = ($page - 1) * $perPage;

		$recommendedIds = $store->read($submissionId, $offset, $perPage);
		if (!$recommendedIds) {
			return '';
		}

		$request = Application::get()->getRequest();
		$context = $request->getRouter()->getContext($request);
		if (!$context) {
			return '';
		}

		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$ordered = array();
		foreach ($recommendedIds as $recommendedId) {
			$recommended = $submissionDao->getById($recommendedId);
			// A submission may have been unpublished or moved between the last
			// refresh and now; showing it would be a broken link.
			if ($recommended && (int) $recommended->getData('contextId') === (int) $context->getId()
					&& (int) $recommended->getData('status') === STATUS_PUBLISHED) {
				$ordered[] = $recommended;
			}
		}
		if (!$ordered) {
			return '';
		}

		$total = $store->total($submissionId);
		$templateManager = TemplateManager::getManager($request);
		$templateManager->assign('articlesBySimilarity', (object) array(
			'submissions' => $ordered,
			'plugin' => $this,
			'query' => (string) (isset($state->search_phrase) ? $state->search_phrase : ''),
			'start' => $offset + 1,
			'end' => $offset + count($ordered),
			'total' => $total,
			'previousUrl' => $page > 1 ? $this->pageUrl($request, $submissionId, $page - 1) : null,
			'nextUrl' => ($offset + $perPage) < $total ? $this->pageUrl($request, $submissionId, $page + 1) : null,
		));

		return $templateManager->fetch($this->getTemplateResource('articleFooter.tpl'));
	}

	/**
	 * Retrieves an issue, with a small cache for one render.
	 * Used by the template.
	 */
	function getIssue($issueId) {
		if (!$issueId) {
			return null;
		}
		if (!array_key_exists($issueId, $this->issueCache)) {
			$this->issueCache[$issueId] = DAORegistry::getDAO('IssueDAO')->getById($issueId);
		}
		return $this->issueCache[$issueId];
	}

	/**
	 * The paging link. 3.3 takes the dispatcher arguments positionally.
	 */
	private function pageUrl($request, $submissionId, $page) {
		$context = $request->getRouter()->getContext($request);
		return $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$context ? $context->getPath() : null,
			'article',
			'view',
			array($submissionId),
			array('articlesBySimilarityPage' => $page)
		);
	}
}
