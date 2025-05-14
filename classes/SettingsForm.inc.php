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
		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_USER);
		parent::__construct($plugin->getTemplateResource('settings.tpl'));
		$this->plugin = $plugin;
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc Form::initData
	 */
	public function initData()
	{
		$contextId = $this->plugin->getCurrentContextId();

		$this->setData('useCacheHeader', (bool) $this->plugin->getSetting($contextId, 'useCacheHeader'));
		$this->setData('useCompression', (bool) $this->plugin->getSetting($contextId, 'useCompression'));
		$this->setData('useStatistics', (bool) $this->plugin->getSetting($contextId, 'useStatistics'));
		$this->setData('cacheCss', (bool) $this->plugin->getSetting($contextId, 'cacheCss'));
		$this->setData('timeToLiveInSeconds', (int) $this->plugin->getSetting($contextId, 'timeToLiveInSeconds'));
		$this->setData('cacheablePages', [AppLocale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'cacheablePages')) ?: []]);
		$this->setData('nonCacheableOperations', [AppLocale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'nonCacheableOperations')) ?: []]);

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
	public function readInputData()
	{
		$vars = ['timeToLiveInSeconds', 'useCacheHeader', 'useCompression', 'useStatistics', 'cacheCss', 'clearContexts'];
		$request = Application::get()->getRequest();
		$this->setData('cacheablePages', $request->getUserVar('keywords')['cacheablePages'] ?: []);
		$this->setData('nonCacheableOperations', $request->getUserVar('keywords')['nonCacheableOperations'] ?: []);
		$this->readUserVars($vars);
		parent::readInputData();
	}

	/**
	 * @copydoc Form::fetch
	 */
	public function fetch($request, $template = null, $display = false)
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
		$this->plugin->updateSetting($contextId, 'cacheablePages', json_encode($this->getData('cacheablePages')));
		$this->plugin->updateSetting($contextId, 'nonCacheableOperations', json_encode($this->getData('nonCacheableOperations')));

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
}
