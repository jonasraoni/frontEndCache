<?php

/**
 * @file classes/SettingsForm.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 */

namespace APP\plugins\generic\frontEndCache\classes;

use APP\plugins\generic\frontEndCache\FrontEndCachePlugin;
use Application;
use AppLocale;
use Context;
use Exception;
use Form;
use FormValidatorCSRF;
use FormValidatorPost;
use NotificationManager;
use TemplateManager;

import('lib.pkp.classes.form.Form');

class SettingsForm extends Form
{
	/** @var FrontEndCachePlugin */
	public $plugin;

	/**
	 * @copydoc Form::__construct
	 */
	public function __construct($plugin)
	{
		parent::__construct($plugin->getTemplateResource('settings.tpl'));
		$this->plugin = $plugin;
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc Form::initData
	 */
	public function initData(): void
	{
		$contextId = $this->plugin->getCurrentContextId();

		$this->setData('useCacheHeader', (bool) $this->plugin->getSetting($contextId, 'useCacheHeader'));
		$this->setData('useCompression', (bool) $this->plugin->getSetting($contextId, 'useCompression'));
		$this->setData('useStatistics', (bool) $this->plugin->getSetting($contextId, 'useStatistics'));
		$this->setData('cacheCss', (bool) $this->plugin->getSetting($contextId, 'cacheCss'));
		$this->setData('useEagerLoading', (bool) $this->plugin->getSetting($contextId, 'useEagerLoading'));
		$this->setData('timeToLiveInSeconds', (int) $this->plugin->getSetting($contextId, 'timeToLiveInSeconds'));
		$this->setData('cacheablePages', [AppLocale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'cacheablePages')) ?: []]);
		$this->setData('nonCacheableOperations', [AppLocale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'nonCacheableOperations')) ?: []]);
		$this->setData('cacheRules', json_decode($this->plugin->getSetting($contextId, 'cacheRules'), true) ?: []);

		/** @var Context */
		foreach (Application::getContextDAO()->getAll(false)->toIterator() as $context) {
			$contexts[$context->getId()] = $context->getLocalizedName();
		}

		// Include site context
		if (count($contexts) > 1) {
			$contexts = ['' => __('plugins.generic.frontEndCache.sharedContent')] + $contexts;
		}

		$this->setData('clearContexts', $contexts);
		parent::initData();
	}

	/**
	 * @copydoc Form::readInputData
	 */
	public function readInputData(): void
	{
		$vars = ['timeToLiveInSeconds', 'useCacheHeader', 'useCompression', 'useStatistics', 'cacheCss', 'useEagerLoading', 'clearContexts'];
		$request = Application::get()->getRequest();
		$this->setData('cacheablePages', $request->getUserVar('keywords')['cacheablePages'] ?: []);
		$this->setData('nonCacheableOperations', $request->getUserVar('keywords')['nonCacheableOperations'] ?: []);
		// Handle cache rules
		$cacheRules = [];
		$rulePatterns = $request->getUserVar('cacheRulePattern') ?: [];
		$ruleQueries = $request->getUserVar('cacheRuleQuery') ?: [];
		// Process cache rules
		foreach ($rulePatterns as $i => $pattern) {
			if (!empty($pattern) && !empty($ruleQueries[$i])) {
				$cacheRules[$pattern] = $ruleQueries[$i];
			}
		}

		$this->setData('cacheRules', $cacheRules);
		$this->readUserVars($vars);
		parent::readInputData();
	}

	/**
	 * @copydoc Form::fetch
	 */
	public function fetch($request, $template = null, $display = false): string
	{
		$templateManager = TemplateManager::getManager($request);
		$templateManager->assign('pluginName', $this->plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute
	 */
	public function execute(...$functionArgs)
	{
		$contextId = $this->plugin->getCurrentContextId();
		$this->plugin->updateSetting($contextId, 'timeToLiveInSeconds', (int) $this->getData('timeToLiveInSeconds'));
		$this->plugin->updateSetting($contextId, 'useCacheHeader', (bool) $this->getData('useCacheHeader'), 'bool');
		$this->plugin->updateSetting($contextId, 'useCompression', (bool) $this->getData('useCompression'), 'bool');
		$this->plugin->updateSetting($contextId, 'useStatistics', (bool) $this->getData('useStatistics'), 'bool');
		$this->plugin->updateSetting($contextId, 'cacheCss', (bool) $this->getData('cacheCss'), 'bool');
		$this->plugin->updateSetting($contextId, 'useEagerLoading', (bool) $this->getData('useEagerLoading'), 'bool');
		$this->plugin->updateSetting($contextId, 'cacheablePages', json_encode($this->getData('cacheablePages')));
		$this->plugin->updateSetting($contextId, 'nonCacheableOperations', json_encode($this->getData('nonCacheableOperations')));
		$this->plugin->updateSetting($contextId, 'cacheRules', json_encode($this->getData('cacheRules')));

		import('classes.notification.NotificationManager');
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			Application::get()->getRequest()->getUser()->getId(),
			NOTIFICATION_TYPE_SUCCESS,
			['contents' => __('common.changesSaved')]
		);

		$clearContexts = (array) $this->getData('clearContexts');
		import('lib.pkp.classes.file.FileManager');
		foreach ($clearContexts as $contextId) {
			$contextId = (int) $contextId;
			$basePath = \Core::getBaseDir() . '/cache/frontEndCache' . ($contextId ? "/{$contextId}" : '');
			foreach (glob("{$basePath}/*.php") as $file) {
				@unlink ($file);
			}
		}

		return parent::execute(...$functionArgs);
	}

	/**
	 * Get default cache rules for standard OJS routes
	 *
	 * @return array
	 */
	private function getDefaultCacheRules(): array
	{
		import('lib.pkp.classes.submission.PKPSubmission'); // STATUS_... constants
		switch (Application::getName()) {
			case 'ojs2':
				return [
					'#/index/sitemap/?$#' => "SELECT MD5(GROUP_CONCAT(j.path))"
						. "\nFROM journals j"
						. "\nWHERE j.enabled = 1"
						. "\nORDER BY j.path",
					'#/sitemap/?$#' => "SELECT CONCAT(COALESCE(("
						. "\n	SELECT CONCAT(COUNT(DISTINCT i.issue_id), '-', MIN(i.issue_id), '-', MAX(i.issue_id), '-', COUNT(0), '-', MIN(s.submission_id), '-', MAX(s.submission_id))"
						. "\n	FROM submissions s"
						. "\n	INNER JOIN journals j ON j.journal_id = s.context_id"
						. "\n	INNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\n	INNER JOIN publication_settings ps ON ps.publication_id = p.publication_id AND ps.setting_name = 'issueId' AND ps.locale = ''"
						. "\n	INNER JOIN issues i ON CAST(i.issue_id AS CHAR(20)) = ps.setting_value"
						. "\n	WHERE s.context_id = \$contextId"
						. "\n	AND s.status = " . STATUS_PUBLISHED
						. "\n	AND j.enabled = 1"
						. "\n	AND i.published = 1"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT MD5(GROUP_CONCAT(nmi.path))"
						. "\n	FROM navigation_menu_items nmi"
						. "\n	WHERE nmi.type = 'NMI_TYPE_CUSTOM'"
						. "\n	AND nmi.context_id = \$contextId"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT CONCAT(COUNT(0), '-', MIN(announcement_id), '-', MAX(announcement_id)) FROM announcements a"
						. "\n	WHERE COALESCE(a.date_expire, CURRENT_TIMESTAMP) > CURRENT_TIMESTAMP"
						. "\n	AND a.assoc_id = \$contextId"
						. "\n), ''))",
					'#/article/view/(.+)/?$#' => "SELECT CONCAT(i.last_modified, s.last_modified, p.last_modified)"
						. "\nFROM submissions s"
						. "\nINNER JOIN journals j ON j.journal_id = s.context_id"
						. "\nINNER JOIN publications p ON p.publication_id = s.current_publication_id"
						. "\nINNER JOIN publication_settings ps ON ps.publication_id = p.publication_id AND ps.setting_name = 'issueId' AND ps.locale = ''"
						. "\nINNER JOIN issues i ON CAST(i.issue_id AS CHAR(20)) = ps.setting_value"
						. "\nWHERE (s.submission_id = \$1 OR p.url_path = \$1)"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND j.enabled = 1"
						. "\nAND i.published = 1",
					'#/issue/view/(.*)/?$#' => "SELECT last_modified"
						. "\nFROM issues i"
						. "\nINNER JOIN journals j ON j.journal_id = i.journal_id"
						. "\nWHERE (i.issue_id = \$1 OR i.url_path = \$1)"
						. "\nAND j.enabled = 1"
						. "\nAND i.published = 1",
					'#/issue/archive#' => "SELECT CONCAT(i.issue_id, i.last_modified)"
						. "\nFROM issues i"
						. "\nINNER JOIN journals j ON j.journal_id = i.journal_id"
						. "\nWHERE i.journal_id = \$contextId"
						. "\nAND j.enabled = 1"
						. "\nAND i.published = 1",
					'#/issue/current/?$#' => "SELECT CONCAT(i.issue_id, i.last_modified)"
						. "\nFROM issues i"
						. "\nINNER JOIN journals j ON j.journal_id = i.journal_id"
						. "\nWHERE i.current = 1"
						. "\nAND i.journal_id = \$contextId"
						. "\nAND j.enabled = 1"
						. "\nAND i.published = 1",
					'#/catalog/#' => "SELECT CONCAT(MAX(s.last_modified), MAX(p.last_modified), MAX(i.last_modified))"
						. "\nFROM submissions s"
						. "\nINNER JOIN journals j ON j.journal_id = s.context_id"
						. "\nINNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\nINNER JOIN publication_settings ps ON ps.publication_id = p.publication_id AND ps.setting_name = 'issueId' AND ps.locale = ''"
						. "\nINNER JOIN issues i ON CAST(i.issue_id AS CHAR(20)) = ps.setting_value"
						. "\nWHERE s.context_id = \$contextId"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND j.enabled = 1"
						. "\nAND i.published = 1"
					];
			case 'omp':
				return [
					'#/index/sitemap/?$#' => "SELECT MD5(GROUP_CONCAT(p.path))"
						. "\nFROM presses p"
						. "\nWHERE p.enabled = 1"
						. "\nORDER BY p.path",
					'#/sitemap/?$#' => "SELECT CONCAT(COALESCE(("
						. "\n	SELECT CONCAT(COUNT(0), '-', MIN(s.submission_id), '-', MAX(s.submission_id))"
						. "\n	FROM submissions s"
						. "\n	INNER JOIN presses pr ON pr.press_id = s.context_id"
						. "\n	INNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\n	WHERE s.context_id = \$contextId"
						. "\n	AND s.status = " . STATUS_PUBLISHED
						. "\n	AND pr.enabled = 1"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT MD5(GROUP_CONCAT(nmi.path))"
						. "\n	FROM navigation_menu_items nmi"
						. "\n	WHERE nmi.type = 'NMI_TYPE_CUSTOM'"
						. "\n	AND nmi.context_id = \$contextId"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT CONCAT(COUNT(0), '-', MIN(announcement_id), '-', MAX(announcement_id)) FROM announcements a"
						. "\n	WHERE COALESCE(a.date_expire, CURRENT_TIMESTAMP) > CURRENT_TIMESTAMP"
						. "\n	AND a.assoc_id = \$contextId"
						. "\n), ''))",
					'#/book/(.*)/?$#' => "SELECT CONCAT(s.last_modified, p.last_modified)"
						. "\nFROM submissions s"
						. "\nINNER JOIN presses pr ON pr.press_id = s.context_id"
						. "\nINNER JOIN publications p ON p.publication_id = s.current_publication_id"
						. "\nWHERE (s.submission_id = \$1 OR p.url_path = \$1)"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND pr.enabled = 1",
					'#/catalog/#' => "SELECT CONCAT(MAX(s.last_modified), MAX(p.last_modified))"
						. "\nFROM submissions s"
						. "\nINNER JOIN presses pr ON pr.press_id = s.context_id"
						. "\nINNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\nWHERE s.context_id = \$contextId"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND pr.enabled = 1"
				];
			case 'ops':
				return [
					'#/index/sitemap/?$#' => "SELECT MD5(GROUP_CONCAT(j.path))"
						. "\nFROM journals j"
						. "\nWHERE j.enabled = 1"
						. "\nORDER BY j.path",
					'#/sitemap/?$#' => "SELECT CONCAT(COALESCE(("
						. "\n	SELECT CONCAT(COUNT(0), '-', MIN(s.submission_id), '-', MAX(s.submission_id))"
						. "\n	FROM submissions s"
						. "\n	INNER JOIN journals j ON j.journal_id = s.context_id"
						. "\n	INNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\n	WHERE s.context_id = \$contextId"
						. "\n	AND s.status = " . STATUS_PUBLISHED
						. "\n	AND j.enabled = 1"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT MD5(GROUP_CONCAT(nmi.path))"
						. "\n	FROM navigation_menu_items nmi"
						. "\n	WHERE nmi.type = 'NMI_TYPE_CUSTOM'"
						. "\n	AND nmi.context_id = \$contextId"
						. "\n), ''), '-', COALESCE(("
						. "\n	SELECT CONCAT(COUNT(0), '-', MIN(announcement_id), '-', MAX(announcement_id)) FROM announcements a"
						. "\n	WHERE COALESCE(a.date_expire, CURRENT_TIMESTAMP) > CURRENT_TIMESTAMP"
						. "\n	AND a.assoc_id = \$contextId"
						. "\n), ''))",
					'#/preprint/view/(.*)/?$#' => "SELECT CONCAT(s.last_modified, p.last_modified)"
						. "\nFROM submissions s"
						. "\nINNER JOIN journals j ON j.journal_id = s.context_id"
						. "\nINNER JOIN publications p ON p.publication_id = s.current_publication_id"
						. "\nWHERE (s.submission_id = \$1 OR p.url_path = \$1)"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND j.enabled = 1",
					'#/catalog/#' => "SELECT CONCAT(MAX(s.last_modified), MAX(p.last_modified))"
						. "\nFROM submissions s"
						. "\nINNER JOIN journals j ON j.journal_id = s.context_id"
						. "\nINNER JOIN publications p ON s.current_publication_id = p.publication_id"
						. "\nWHERE s.context_id = \$contextId"
						. "\nAND s.status = " . STATUS_PUBLISHED
						. "\nAND j.enabled = 1"

				];
			default:
				throw new Exception('Unsupported application: ' . Application::getName());
		}
	}

	/**
	 * Handle reset to default rules
	 */
	public function resetDefaultRules(): void
	{
		$contextId = $this->plugin->getCurrentContextId();
		$rules = json_decode($this->plugin->getSetting($contextId, 'cacheRules'), true) ?: [];

		$rules = $this->getDefaultCacheRules() + $rules;
		$this->plugin->updateSetting($contextId, 'cacheRules', json_encode($rules));
	}
}
