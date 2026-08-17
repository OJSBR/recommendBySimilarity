<?php

/**
 * @file plugins/generic/recommendBySimilarity/classes/RecommendBySimilarityStore.inc.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendBySimilarityStore
 *
 * @brief Reads and writes the materialised similar articles.
 */


use Illuminate\Database\Capsule\Manager as Capsule;

import('classes.submission.Submission');

class RecommendBySimilarityStore
{
    /**
     * Enrols published submissions that have never been seen before.
     */
    public function enqueueNew(array $contextIds, int $limit = 5000, int $queueLimit = 0): int
    {
        if (!$contextIds) {
            return 0;
        }

        if ($queueLimit > 0) {
            $room = $queueLimit - Capsule::table('recommend_similarity_state')->count();
            if ($room <= 0) {
                return 0;
            }
            $limit = min($limit, $room);
        }

        $rows = Capsule::table('submissions as s')
            ->leftJoin('recommend_similarity_state as state', 'state.submission_id', '=', 's.submission_id')
            ->where('s.status', STATUS_PUBLISHED)
            ->whereIn('s.context_id', $contextIds)
            ->whereNull('state.submission_id')
            ->limit($limit)
            ->select('s.submission_id', 's.context_id')
            ->get()
            ->map(fn ($row) => [
                'submission_id' => $row->submission_id,
                'context_id' => $row->context_id,
                'computed_at' => null,
                'version' => 1,
                'search_phrase' => null,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Capsule::table('recommend_similarity_state')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    public function enqueueOne(int $submissionId, int $contextId): void
    {
        Capsule::table('recommend_similarity_state')->insertOrIgnore([[
            'submission_id' => $submissionId,
            'context_id' => $contextId,
            'computed_at' => null,
            'version' => 1,
            'search_phrase' => null,
        ]]);
    }

    /**
     * The next submissions to compute: never-computed ones first, then the
     * ones computed longest ago, so that a refresh is always a slice and never
     * a stampede.
     *
     * @return array context id => submission ids
     */
    public function due(array $contextIds, int $limit, int $maxAgeDays): array
    {
        if (!$contextIds) {
            return [];
        }

        $rows = Capsule::table('recommend_similarity_state')
            ->whereIn('context_id', $contextIds)
            ->where(function ($query) use ($maxAgeDays) {
                $query->whereNull('computed_at')
                    ->orWhere('computed_at', '<', now()->subDays($maxAgeDays));
            })
            ->orderByRaw('(computed_at IS NULL) DESC')
            ->orderBy('computed_at')
            ->limit($limit)
            ->select('submission_id', 'context_id')
            ->get();

        $byContext = [];
        foreach ($rows as $row) {
            $byContext[(int) $row->context_id][] = (int) $row->submission_id;
        }

        return $byContext;
    }

    /**
     * Recomputes the similar articles of the given submissions.
     */
    public function refresh(array $submissionIds, int $contextId, RecommendBySimilarityFinder $finder, int $max): void
    {
        foreach ($submissionIds as $submissionId) {
            $phrase = $finder->searchPhrase($submissionId);
            $similar = $finder->find($submissionId, $contextId, $finder->terms($phrase), $max);

            $rows = [];
            foreach ($similar as $seq => $recommendedId) {
                $rows[] = [
                    'submission_id' => $submissionId,
                    'seq' => $seq,
                    'recommended_submission_id' => $recommendedId,
                ];
            }

            Capsule::connection()->transaction(function () use ($submissionId, $rows, $phrase) {
                Capsule::table('recommend_similarity_cache')->where('submission_id', $submissionId)->delete();
                foreach (array_chunk($rows, 500) as $insert) {
                    Capsule::table('recommend_similarity_cache')->insert($insert);
                }
                Capsule::table('recommend_similarity_state')
                    ->where('submission_id', $submissionId)
                    ->update([
                        'computed_at' => now(),
                        'version' => Capsule::raw('version + 1'),
                        'search_phrase' => $phrase !== '' ? $phrase : null,
                    ]);
            });
        }
    }

    /**
     * @return int[] submission ids, in display order
     */
    public function read(int $submissionId, int $offset, int $limit): array
    {
        return Capsule::table('recommend_similarity_cache')
            ->where('submission_id', $submissionId)
            ->orderBy('seq')
            ->offset($offset)
            ->limit($limit)
            ->pluck('recommended_submission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function total(int $submissionId): int
    {
        return Capsule::table('recommend_similarity_cache')->where('submission_id', $submissionId)->count();
    }

    public function stateOf(int $submissionId): ?object
    {
        return Capsule::table('recommend_similarity_state')->where('submission_id', $submissionId)->first();
    }

    /**
     * Marks submissions for recomputation at the next run.
     */
    public function invalidate(array $submissionIds): void
    {
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            Capsule::table('recommend_similarity_state')
                ->whereIn('submission_id', $chunk)
                ->update(['computed_at' => null, 'version' => Capsule::raw('version + 1')]);
        }
    }
}
