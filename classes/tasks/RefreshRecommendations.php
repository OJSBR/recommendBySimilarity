<?php

/**
 * @file plugins/generic/recommendBySimilarity/classes/tasks/RefreshRecommendations.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RefreshRecommendations
 *
 * @brief Recomputes a slice of the similar articles on every run.
 */

namespace APP\plugins\generic\recommendBySimilarity\classes\tasks;

use APP\plugins\generic\recommendBySimilarity\classes\RecommendationStore;
use APP\plugins\generic\recommendBySimilarity\classes\SimilarityFinder;
use APP\plugins\generic\recommendBySimilarity\RecommendBySimilarityPlugin;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class RefreshRecommendations extends ScheduledTask
{
    /**
     * How long a run may take on the command line, where it has a cron slot to
     * itself.
     */
    private const TIME_BUDGET_CLI = 120;

    /**
     * How long it may take when there is no cron at all.
     *
     * With [schedule] task_runner on -- which is the default, and what replaced
     * the old acron plugin -- scheduled tasks run in a shutdown function at the
     * end of a web request. The reader already has the page by then, but the
     * PHP-FPM worker is still busy, and workers are few. Refreshing a smaller
     * slice more often is the right trade there: the queue rolls forward either
     * way, just in smaller steps.
     */
    private const TIME_BUDGET_WEB = 10;

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.generic.recommendBySimilarity.task.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    protected function executeActions(): bool
    {
        /** @var RecommendBySimilarityPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'recommendbysimilarityplugin');
        if (!$plugin || !($contextIds = $plugin->enabledContextIds())) {
            return true;
        }

        $store = new RecommendationStore();
        $finder = new SimilarityFinder();

        $enqueued = $store->enqueueNew($contextIds, 5000, (int) $plugin->getPluginSetting('queueLimit'));
        $due = $store->due(
            $contextIds,
            (int) $plugin->getPluginSetting('batchSize'),
            (int) $plugin->getPluginSetting('maxAgeDays')
        );

        $deadline = time() + (PHP_SAPI === 'cli' ? self::TIME_BUDGET_CLI : self::TIME_BUDGET_WEB);
        $refreshed = 0;
        foreach ($due as $contextId => $submissionIds) {
            foreach (array_chunk($submissionIds, 25) as $chunk) {
                $store->refresh($chunk, $contextId, $finder, (int) $plugin->getPluginSetting('maxRecommendations'));
                $refreshed += count($chunk);
                if (time() >= $deadline) {
                    break 2;
                }
            }
        }

        $this->addExecutionLogEntry(
            __('plugins.generic.recommendBySimilarity.task.result', ['refreshed' => $refreshed, 'queued' => $enqueued]),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
        );

        return true;
    }
}
