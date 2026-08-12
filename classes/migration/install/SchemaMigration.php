<?php

/**
 * @file plugins/generic/recommendBySimilarity/classes/migration/install/SchemaMigration.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SchemaMigration
 *
 * @brief The two tables that let the article page read its similar articles
 *        instead of searching for them.
 *
 * There is no index table here, unlike recommendByAuthor: the search index OJS
 * already maintains is exactly the right index for this question. What was
 * missing was somewhere to write the answer down.
 */

namespace APP\plugins\generic\recommendBySimilarity\classes\migration\install;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaMigration extends Migration
{
    public function up(): void
    {
        Schema::create('recommend_similarity_cache', function (Blueprint $table) {
            $table->bigInteger('submission_id');
            $table->smallInteger('seq');
            $table->bigInteger('recommended_submission_id');

            $table->primary(['submission_id', 'seq'], 'recommend_similarity_cache_pkey');
            $table->index(['recommended_submission_id'], 'recommend_similarity_cache_recommended');

            $table->foreign('submission_id', 'recommend_similarity_cache_submission_id_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
            $table->foreign('recommended_submission_id', 'recommend_similarity_cache_recommended_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
        });

        Schema::create('recommend_similarity_state', function (Blueprint $table) {
            $table->bigInteger('submission_id');
            $table->bigInteger('context_id');
            $table->datetime('computed_at')->nullable();
            $table->bigInteger('version')->default(1);
            // The search phrase the recommendations were built from, shown to
            // the reader as the "refine this search" link, exactly as the
            // original plugin does.
            $table->text('search_phrase')->nullable();

            $table->primary(['submission_id'], 'recommend_similarity_state_pkey');
            $table->index(['context_id', 'computed_at'], 'recommend_similarity_state_queue');

            $table->foreign('submission_id', 'recommend_similarity_state_submission_id_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::drop('recommend_similarity_cache');
        Schema::drop('recommend_similarity_state');
    }
}
