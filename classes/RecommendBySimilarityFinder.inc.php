<?php

/**
 * @file plugins/generic/recommendBySimilarity/classes/RecommendBySimilarityFinder.inc.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendBySimilarityFinder
 *
 * @brief Finds the articles that share the most search terms with a given one.
 *
 * The question is the same one the original plugin asks, and the terms are
 * extracted by the very same code (SubmissionSearchIndex::filterKeywords), so
 * the answer is the same. What changes is how it is asked.
 *
 * The submission collector orders by search ranking with two *correlated*
 * subqueries -- "how many of these keywords does this submission match" and
 * "how many times" -- evaluated once per candidate row. On a journal whose
 * search index holds three and a half million rows that is twenty seconds of
 * work for one article. The same ranking, expressed as a single grouped scan
 * of the rows that actually contain the terms, is a hundredth of a second.
 * The ordering is deliberately identical to the core's: distinct keywords
 * matched first, then total matches.
 */


use Illuminate\Database\Capsule\Manager as Capsule;

import('classes.submission.Submission');
import('classes.search.ArticleSearch');
import('classes.search.ArticleSearchIndex');

class RecommendBySimilarityFinder
{
    /** The original plugin's cap on how many terms take part in the search. */
    public const MAX_SEARCH_KEYWORDS = 20;

    /**
     * The search phrase of a submission: its keywords, in every locale they
     * were entered in. Empty when the article has no keywords at all, which is
     * when the original plugin shows nothing.
     */
    public function searchPhrase(int $submissionId): string
    {
        return implode(' ', (new ArticleSearch())->getSimilarityTerms($submissionId));
    }

    /**
     * The terms of that phrase as the search index understands them --
     * lowercased, stripped of stopwords and of anything too short or too long.
     *
     * @return string[]
     */
    public function terms(string $searchPhrase): array
    {
        if ($searchPhrase === '') {
            return [];
        }

        return array_slice(
            array_unique(Application::getSubmissionSearchIndex()->filterKeywords($searchPhrase)),
            0,
            self::MAX_SEARCH_KEYWORDS
        );
    }

    /**
     * The most similar submissions, best first.
     *
     * @return int[] submission ids
     */
    public function find(int $submissionId, int $contextId, array $terms, int $limit): array
    {
        if (!$terms) {
            return [];
        }

        $keywordIds = Capsule::table('submission_search_keyword_list')
            ->whereIn('keyword_text', $terms)
            ->pluck('keyword_id')
            ->all();
        if (!$keywordIds) {
            return [];
        }

        return Capsule::table('submission_search_object_keywords as ok')
            ->join('submission_search_objects as o', 'o.object_id', '=', 'ok.object_id')
            ->join('submissions as s', 's.submission_id', '=', 'o.submission_id')
            ->whereIn('ok.keyword_id', $keywordIds)
            ->where('o.submission_id', '<>', $submissionId)
            ->where('s.context_id', $contextId)
            ->where('s.status', STATUS_PUBLISHED)
            ->groupBy('o.submission_id')
            // The core's own ranking: distinct terms matched, then total matches.
            ->orderByRaw('COUNT(DISTINCT ok.keyword_id) DESC, COUNT(0) DESC')
            ->orderBy('o.submission_id')
            ->limit($limit)
            ->pluck('o.submission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
