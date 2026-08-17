{**
 * plugins/generic/recommendBySimilarity/templates/articleFooter.tpl
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A template to be included via Templates::Article::Footer::PageFooter hook.
 *
 * The element ids are the ones the original plugin used, so themes that style
 * this section keep working. The submissions arrive as a plain array now --
 * they are read from the cache table, not from a collector.
 *
 * Ported from the 3.5 branch: 3.3 has no frontend/components/pagination.tpl,
 * so the two links the plugin already computed are written out here; the
 * issue comes from the plugin rather than from a collection; and {url} takes
 * neither a router nor urlLocaleForPage in this release.
 *}
{if $articlesBySimilarity->submissions}
	<section id="articlesBySimilarityList">
		<h2 class="label" id="articlesBySimilarity">
			{translate key="plugins.generic.recommendBySimilarity.heading"}
		</h2>
		<ul>
			{foreach from=$articlesBySimilarity->submissions item=submission}
				{assign var=publication value=$submission->getCurrentPublication()}
				{assign var=issue value=$articlesBySimilarity->plugin->getIssue((int) $publication->getData('issueId'))}
				<li>
					{foreach from=$publication->getData('authors') item=author}
						{$author->getFullName()|escape},
					{/foreach}
					<a href="{url journal=$currentContext->getPath() page="article" op="view" path=$submission->getBestId()}">
						{$publication->getLocalizedFullTitle()|strip_unsafe_html}
					</a>
					{if $issue},
					<a href="{url journal=$currentContext->getPath() page="issue" op="view" path=$issue->getBestIssueId()}">
						{$currentContext->getLocalizedName()|escape}: {$issue->getIssueIdentification()|escape}
					</a>
					{/if}
				</li>
			{/foreach}
		</ul>
		{if $articlesBySimilarity->previousUrl || $articlesBySimilarity->nextUrl}
			<p id="articlesBySimilarityPages">
				{if $articlesBySimilarity->previousUrl}
					<a href="{$articlesBySimilarity->previousUrl|escape}#articlesBySimilarity" class="prev">
						{translate key="plugins.generic.recommendBySimilarity.previous"}
					</a>
				{/if}
				<span class="current">
					{translate key="plugins.generic.recommendBySimilarity.showing" start=$articlesBySimilarity->start end=$articlesBySimilarity->end total=$articlesBySimilarity->total}
				</span>
				{if $articlesBySimilarity->nextUrl}
					<a href="{$articlesBySimilarity->nextUrl|escape}#articlesBySimilarity" class="next">
						{translate key="plugins.generic.recommendBySimilarity.next"}
					</a>
				{/if}
			</p>
		{/if}
		{if $articlesBySimilarity->query}
			<p id="articlesBySimilaritySearch">
				{capture assign="articlesBySimilaritySearchLink"}{strip}
					<a href="{url page="search" op="search" query=$articlesBySimilarity->query}">
						{translate key="plugins.generic.recommendBySimilarity.advancedSearch"}
					</a>
				{/strip}{/capture}
				{translate key="plugins.generic.recommendBySimilarity.advancedSearchIntro" advancedSearchLink=$articlesBySimilaritySearchLink}
			</p>
		{/if}
	</section>
{/if}
