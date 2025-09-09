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
	{literal}
	$(function () {
		const container = $('#frontEndCacheSettings');
		container.pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		container[0].querySelectorAll('.checkNumbers').forEach(function (input) {
			input.addEventListener("input", e => input.value = (isNaN(input.value)) ? input.value.replace(e.data, '') : input.value);
		});

		document.getElementById('selectAllContexts').addEventListener('change', function() {
			const checkboxes = container[0].querySelectorAll('input[name="clearContexts[]"]');
			checkboxes.forEach(checkbox => checkbox.checked = this.checked);
		});

		// Cache rules management
		{/literal}
		let ruleIndex = {count($cacheRules)};
		{literal}
		function addCacheRule() {
			ruleIndex++;
			const rulesContainer = document.getElementById('cacheRulesContainer');
			const ruleDiv = document.createElement('div');
			ruleDiv.className = 'cache-rule-item';
			ruleDiv.innerHTML = `
				<div class="cache-rule-fields">
					<div class="cache-rule-field">
						<label for="cacheRulePattern_$\{ruleIndex}">{/literal}{translate key="plugins.generic.frontEndCache.cacheRulePattern"}{literal}</label>
						<input type="text" id="cacheRulePattern_$\{ruleIndex}" name="cacheRulePattern[]" placeholder="issue/(\d+)" />
						<small>{/literal}{translate key="plugins.generic.frontEndCache.cacheRulePatternHelp"}{literal}</small>
					</div>
					<div class="cache-rule-field">
						<label for="cacheRuleQuery_$\{ruleIndex}">{/literal}{translate key="plugins.generic.frontEndCache.cacheRuleQuery"}{literal}</label>
						<textarea id="cacheRuleQuery_$\{ruleIndex}" name="cacheRuleQuery[]" rows="3" placeholder="SELECT date_modified FROM issues WHERE issue_id = $1"></textarea>
						<small>{/literal}{translate key="plugins.generic.frontEndCache.cacheRuleQueryHelp"}{literal}</small>
					</div>
					<button type="button" class="remove-rule-btn" onclick="this.closest('.cache-rule-item').remove()">{/literal}{translate key="plugins.generic.frontEndCache.removeRule"}{literal}</button>
				</div>
			`;
			rulesContainer.appendChild(ruleDiv);
		}
	});

	{/literal}
</script>

<style>
.cache-rules-section {
	margin-top: 20px;
}

.cache-rule-item {
	border: 1px solid #ddd;
	padding: 15px;
	margin-bottom: 10px;
	border-radius: 4px;
	background-color: #f9f9f9;
}

.cache-rule-fields {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.cache-rule-field {
	display: flex;
	flex-direction: column;
}

.cache-rule-field label {
	font-weight: bold;
	margin-bottom: 5px;
}

.cache-rule-field input,
.cache-rule-field textarea {
	padding: 8px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-family: monospace;
}

.cache-rule-field small {
	color: #666;
	font-size: 0.9em;
	margin-top: 5px;
}

.remove-rule-btn {
	background-color: #dc3545;
	color: white;
	border: none;
	padding: 8px 12px;
	border-radius: 4px;
	cursor: pointer;
	align-self: flex-start;
}

.remove-rule-btn:hover {
	background-color: #c82333;
}

.add-rule-btn {
	background-color: #28a745;
	color: white;
	border: none;
	padding: 10px 15px;
	border-radius: 4px;
	cursor: pointer;
	margin-bottom: 15px;
}

.add-rule-btn:hover {
	background-color: #218838;
}

.reset-rules-btn {
	background-color: #ffc107;
	color: #212529;
	border: none;
	padding: 10px 15px;
	border-radius: 4px;
	cursor: pointer;
	margin-bottom: 15px;
}

.reset-rules-btn:hover {
	background-color: #e0a800;
}
</style>

<form class="pkp_form" id="frontEndCacheSettings" method="POST" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	<p>{translate key="plugins.generic.frontEndCache.description"}</p>
	{fbvFormArea id="frontEndCacheFormArea"}
		{fbvFormSection title="plugins.generic.frontEndCache.general" list="true"}
			{fbvElement type="checkbox" id="useCacheHeader" checked=$useCacheHeader label="plugins.generic.frontEndCache.useCacheHeader" translate="true"}
			{fbvElement type="checkbox" id="useCompression" checked=$useCompression label="plugins.generic.frontEndCache.useCompression" translate="true"}
			{fbvElement type="checkbox" id="useStatistics" checked=$useStatistics label="plugins.generic.frontEndCache.useStatistics" translate="true"}
			{fbvElement type="checkbox" id="cacheCss" checked=$cacheCss label="plugins.generic.frontEndCache.cacheCss" translate="true"}
			{fbvElement type="checkbox" id="useEagerLoading" checked=$useEagerLoading label="plugins.generic.frontEndCache.useEagerLoading" translate="true"}
			<p>{fbvElement type="text" id="timeToLiveInSeconds" class="checkNumbers" value=$timeToLiveInSeconds label="plugins.generic.frontEndCache.timeToLiveInSeconds"}</p>
			<p>{fbvElement type="keyword" id="cacheablePages" current=$cacheablePages label="plugins.generic.frontEndCache.cacheablePages"}</p>
			<p>{fbvElement type="keyword" id="nonCacheableOperations" current=$nonCacheableOperations label="plugins.generic.frontEndCache.nonCacheableOperations"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="frontEndCacheRules"}
		{fbvFormSection title="plugins.generic.frontEndCache.cacheRules" list="true"}
			<p>{translate key="plugins.generic.frontEndCache.cacheRulesDescription"}</p>

			<div class="cache-rules-section">
				<div id="cacheRulesContainer">
					{foreach from=$cacheRules item=query key=pattern name=patterns}
						{assign var=index value=$smarty.foreach.patterns.iteration}
						<div class="cache-rule-item">
							<div class="cache-rule-fields">
								<div class="cache-rule-field">
									<label for="cacheRulePattern_{$index}">{translate key="plugins.generic.frontEndCache.cacheRulePattern"}</label>
									<input type="text" id="cacheRulePattern_{$index}" name="cacheRulePattern[]" value="{$pattern|escape}" placeholder="#article/view/(\d+)#" />
									<small>{translate key="plugins.generic.frontEndCache.cacheRulePatternHelp"}</small>
								</div>
								<div class="cache-rule-field">
									<label for="cacheRuleQuery_{$index}">{translate key="plugins.generic.frontEndCache.cacheRuleQuery"}</label>
									<textarea id="cacheRuleQuery_{$index}" name="cacheRuleQuery[]" rows="3" placeholder="SELECT last_modified FROM submissions WHERE submission_id = $1">{$query|escape}</textarea>
									<small>{translate key="plugins.generic.frontEndCache.cacheRuleQueryHelp"}</small>
								</div>
								<button type="button" class="remove-rule-btn" onclick="this.closest('.cache-rule-item').remove()">{translate key="plugins.generic.frontEndCache.removeRule"}</button>
							</div>
						</div>
					{/foreach}
				</div>
				<button type="button" class="add-rule-btn" onclick="addCacheRule()">{translate key="plugins.generic.frontEndCache.addRule"}</button>
				<button type="submit" class="reset-rules-btn" value="1" name="resetRules">{translate key="plugins.generic.frontEndCache.resetRules"}</button>
			</div>
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
