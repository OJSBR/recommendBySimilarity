{**
 * plugins/generic/recommendBySimilarity/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * How many recommendations are shown, and how much work the refresh may do.
 *}
<script>
	$(function() {ldelim}
		$('#recommendBySimilaritySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<style>
	.rbsStatus {ldelim}
		margin:1em 0; padding:.9em 1.1em; border:1px solid #b9d3e6; border-left:4px solid #3a6ea5;
		background:#f2f7fb; border-radius:6px; line-height:1.5;
	{rdelim}
	.rbsStatus strong {ldelim} color:#20486e; {rdelim}
	.rbsHeading {ldelim} margin:1.4em 0 .3em; font-size:1.05em; font-weight:700; color:#16232f; {rdelim}
	.rbsHint {ldelim} color:#61707e; margin:.4em 0 .8em; font-size:.93em; line-height:1.5; {rdelim}
</style>

<form
	class="pkp_form"
	id="recommendBySimilaritySettingsForm"
	method="post"
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="recommendBySimilaritySettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.recommendBySimilarity.settings.description"}</div>

	<div class="rbsStatus">
		<strong>{translate key="plugins.generic.recommendBySimilarity.settings.status.title"}</strong><br />
		{translate key="plugins.generic.recommendBySimilarity.settings.status.body" computed=$queueStatus.computed pending=$queueStatus.pending total=$queueStatus.total}
	</div>

	{fbvFormArea id="recommendBySimilarityDisplayArea"}
		<p class="rbsHeading">{translate key="plugins.generic.recommendBySimilarity.settings.display.title"}</p>

		{fbvFormSection}
			{fbvElement type="text" id="recommendationCount" value=$recommendationCount label="plugins.generic.recommendBySimilarity.settings.recommendationCount" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxRecommendations" value=$maxRecommendations label="plugins.generic.recommendBySimilarity.settings.maxRecommendations" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

	{/fbvFormArea}

	{fbvFormArea id="recommendBySimilarityRefreshArea"}
		<p class="rbsHeading">{translate key="plugins.generic.recommendBySimilarity.settings.refresh.title"}</p>
		<p class="rbsHint">{translate key="plugins.generic.recommendBySimilarity.settings.refresh.hint"}</p>

		{fbvFormSection}
			{fbvElement type="text" id="batchSize" value=$batchSize label="plugins.generic.recommendBySimilarity.settings.batchSize" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="maxAgeDays" value=$maxAgeDays label="plugins.generic.recommendBySimilarity.settings.maxAgeDays" size=$fbvStyles.size.SMALL}
			{fbvElement type="text" id="queueLimit" value=$queueLimit label="plugins.generic.recommendBySimilarity.settings.queueLimit" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection}
			{fbvElement type="text" id="htmlCacheHours" value=$htmlCacheHours label="plugins.generic.recommendBySimilarity.settings.htmlCacheHours" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="computeOnDemand" label="plugins.generic.recommendBySimilarity.settings.computeOnDemand" checked=$computeOnDemand}
		{/fbvFormSection}
		<p class="rbsHint">{translate key="plugins.generic.recommendBySimilarity.settings.computeOnDemand.hint"}</p>
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
