{**
 * templates/settings.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings page
 *}
<script>
	$(function () {ldelim}
		$('#frontEndCache').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});

	document.querySelectorAll('.checkNumbers').forEach(function (input) {ldelim}
		input.addEventListener("input", e => input.value = (isNaN(input.value)) ? input.value.replace(e.data, '') : input.value);
	{rdelim})

	document.getElementById('selectAllContexts').addEventListener('change', function() {ldelim}
		const checkboxes = document.querySelectorAll('input[name="clearContexts[]"]');
		checkboxes.forEach(checkbox => checkbox.checked = this.checked);
	{rdelim});
</script>

<form class="pkp_form" id="frontEndCacheSettings" method="POST" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	<p>{translate key="plugins.generic.frontEndCache.description"}</p>
	{fbvFormArea id="frontEndCacheFormArea"}
		{fbvFormSection title="plugins.generic.frontEndCache.general" list="true"}
			{fbvElement type="checkbox" id="useCacheHeader" checked=$useCacheHeader label="plugins.generic.frontEndCache.useCacheHeader" translate="true"}
			{fbvElement type="checkbox" id="useCompression" checked=$useCompression label="plugins.generic.frontEndCache.useCompression" translate="true"}
			{fbvElement type="checkbox" id="useStatistics" checked=$useStatistics label="plugins.generic.frontEndCache.useStatistics" translate="true"}
			<p>{fbvElement type="text" id="timeToLiveInSeconds" class="checkNumbers" value=$timeToLiveInSeconds label="plugins.generic.frontEndCache.timeToLiveInSeconds"}</p>
			<p>{fbvElement type="keyword" id="cacheablePages" current=$cacheablePages label="plugins.generic.frontEndCache.cacheablePages"}</p>
			<p>{fbvElement type="keyword" id="nonCacheableOperations" current=$nonCacheableOperations label="plugins.generic.frontEndCache.nonCacheableOperations"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="frontEndCacheContexts"}
		{fbvFormSection title="plugins.generic.frontEndCache.clearCacheInstruction" for="clearContexts[]" list="true"}
			{fbvElement type="checkbox" id="selectAllContexts" name="selectAllContexts" label="common.selectAll"}
			{fbvElement type="checkboxgroup" name="clearContexts" id="clearContexts" from=$clearContexts selected=[] translate=false}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
