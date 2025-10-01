{**
 * templates/settings.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings page
 *}
<style>
.pkp_form_group {
	margin: 15px 0;
	border: 1px solid #ccc;
	padding: 15px;
	border-radius: 5px;
}
</style>
<script>
	{literal}
	// Cache rules management - global functions
	var ruleIndex = {/literal}{count($cacheRules)}{literal};

	function addCacheRule() {
		ruleIndex++;
		const rulesContainer = document.getElementById('cacheRulesContainer');
		const ruleDiv = createRuleElement(ruleIndex);
		rulesContainer.insertBefore(ruleDiv, rulesContainer.lastChild);
		updateRuleButtons();
	}

	function addCacheRuleBelow(button) {
		ruleIndex++;
		const rulesContainer = document.getElementById('cacheRulesContainer');
		const currentRule = button.closest('.pkp_form_group');
		const ruleDiv = createRuleElement(ruleIndex);
		rulesContainer.insertBefore(ruleDiv, currentRule.nextSibling);
		updateRuleButtons();
	}

	function createRuleElement(index) {
		const ruleDiv = document.createElement('div');
		ruleDiv.className = 'pkp_form_group';
		ruleDiv.innerHTML = `
			<div class="pkp_form_row">
				<div class="pkp_form_label">
					<label for="cacheRulePattern_${index}">{/literal}{translate key="plugins.generic.frontEndCache.cacheRulePattern"}{literal}</label>
				</div>
				<div class="pkp_form_control">
					<input type="text" id="cacheRulePattern_${index}" name="cacheRulePattern[]" class="textField" placeholder="issue/(\d+)" />
					<div class="pkp_form_help">{/literal}{translate key="plugins.generic.frontEndCache.cacheRulePatternHelp"}{literal}</div>
				</div>
			</div>
			<div class="pkp_form_row">
				<div class="pkp_form_label">
					<label for="cacheRuleQuery_${index}">{/literal}{translate key="plugins.generic.frontEndCache.cacheRuleQuery"}{literal}</label>
				</div>
				<div class="pkp_form_control">
					<textarea id="cacheRuleQuery_${index}" name="cacheRuleQuery[]" class="textArea" rows="3" placeholder="SELECT date_modified FROM issues WHERE issue_id = $1"></textarea>
					<div class="pkp_form_help">{/literal}{translate key="plugins.generic.frontEndCache.cacheRuleQueryHelp"}{literal}</div>
				</div>
			</div>
			<div class="pkp_form_row">
				<div class="pkp_form_control">
					<button type="button" class="pkp_button pkp_button_primary" onclick="moveRuleUp(this)">⮝</button>
					<button type="button" class="pkp_button pkp_button_primary" onclick="moveRuleDown(this)">⮟</button>
					<button type="button" class="pkp_button pkp_button_secondary" onclick="addCacheRuleBelow(this)">✚</button>
					<button type="button" class="pkp_button pkp_button_secondary" onclick="removeCacheRule(this)">━</button>
				</div>
			</div>
		`;
		return ruleDiv;
	}

	function removeCacheRule(button) {
		button.closest('.pkp_form_group').remove();
		updateRuleButtons();
	}

	function moveRuleUp(button) {
		const ruleDiv = button.closest('.pkp_form_group');
		const prevRule = ruleDiv.previousElementSibling;
		if (prevRule) {
			ruleDiv.parentNode.insertBefore(ruleDiv, prevRule);
			updateRuleButtons();
		}
	}

	function moveRuleDown(button) {
		const ruleDiv = button.closest('.pkp_form_group');
		const nextRule = ruleDiv.nextElementSibling;
		if (nextRule) {
			ruleDiv.parentNode.insertBefore(nextRule, ruleDiv);
			updateRuleButtons();
		}
	}

	function updateRuleButtons() {
		const rules = document.querySelectorAll('#cacheRulesContainer .pkp_form_group');
		rules.forEach((rule, index) => {
			const upBtn = rule.querySelector('button[onclick="moveRuleUp(this)"]');
			const downBtn = rule.querySelector('button[onclick="moveRuleDown(this)"]');

			if (upBtn) upBtn.disabled = index === 0;
			if (downBtn) downBtn.disabled = index === rules.length - 1;
		});
	}

	function toggleAllRules() {
		const rulesContainer = document.getElementById('cacheRulesContainer');
		const toggleBtn = document.getElementById('toggleRulesBtn');
		toggleBtn.remove();
		rulesContainer.removeAttribute('hidden');
	}

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

		// Initialize rule buttons on page load
		updateRuleButtons();
	});

	{/literal}
</script>


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

			<div class="pkp_form_row">
				<div class="pkp_form_control">
					<button type="button" class="pkp_button pkp_button_secondary" id="toggleRulesBtn" onclick="toggleAllRules()">Show All Rules</button>
					<button type="submit" class="pkp_button pkp_button_secondary" value="1" name="resetRules">{translate key="plugins.generic.frontEndCache.resetRules"}</button>
				</div>
			</div>

			<div id="cacheRulesContainer" hidden>
				{foreach from=$cacheRules item=query key=pattern name=patterns}
					{assign var=index value=$smarty.foreach.patterns.iteration}
					<div class="pkp_form_group">
						<div class="pkp_form_row">
							<div class="pkp_form_label">
								<label for="cacheRulePattern_{$index}">{translate key="plugins.generic.frontEndCache.cacheRulePattern"}</label>
							</div>
							<div class="pkp_form_control">
								<input type="text" id="cacheRulePattern_{$index}" name="cacheRulePattern[]" class="textField" value="{$pattern|escape}" placeholder="issue/(\d+)" />
								<div class="pkp_form_help">{translate key="plugins.generic.frontEndCache.cacheRulePatternHelp"}</div>
							</div>
						</div>
						<div class="pkp_form_row">
							<div class="pkp_form_label">
								<label for="cacheRuleQuery_{$index}">{translate key="plugins.generic.frontEndCache.cacheRuleQuery"}</label>
							</div>
							<div class="pkp_form_control">
								<textarea id="cacheRuleQuery_{$index}" name="cacheRuleQuery[]" class="textArea" rows="3" placeholder="SELECT date_modified FROM issues WHERE issue_id = $1">{$query|escape}</textarea>
								<div class="pkp_form_help">{translate key="plugins.generic.frontEndCache.cacheRuleQueryHelp"}</div>
							</div>
						</div>
						<div class="pkp_form_row">
							<div class="pkp_form_control">
								<button type="button" class="pkp_button pkp_button_primary" onclick="moveRuleUp(this)">⮝</button>
								<button type="button" class="pkp_button pkp_button_primary" onclick="moveRuleDown(this)">⮟</button>
								<button type="button" class="pkp_button pkp_button_secondary" onclick="addCacheRuleBelow(this)">✚</button>
								<button type="button" class="pkp_button pkp_button_secondary" onclick="removeCacheRule(this)">━</button>
							</div>
						</div>
					</div>
				{/foreach}

				<div class="pkp_form_row">
					<div class="pkp_form_control">
						<button type="button" class="pkp_button pkp_button_primary" onclick="addCacheRule()">✚</button>
					</div>
				</div>

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
