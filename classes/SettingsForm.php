<?php

/**
 * @file classes/SettingsForm.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 */

namespace APP\plugins\generic\frontEndCache\classes;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\frontEndCache\FrontEndCachePlugin;
use APP\template\TemplateManager;
use PKP\core\Core;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    /**
     * @copydoc Form::__construct
     */
    public function __construct(public FrontEndCachePlugin $plugin)
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
        $this->setData('timeToLiveInSeconds', (int) $this->plugin->getSetting($contextId, 'timeToLiveInSeconds'));
        $this->setData('cacheablePages', [Locale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'cacheablePages')) ?: []]);
        $this->setData('nonCacheableOperations', [Locale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'nonCacheableOperations')) ?: []]);

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
        $vars = ['timeToLiveInSeconds', 'useCacheHeader', 'useCompression', 'useStatistics', 'cacheCss', 'clearContexts'];
        $request = Application::get()->getRequest();
        $this->setData('cacheablePages', $request->getUserVar('keywords')['cacheablePages'] ?: []);
        $this->setData('nonCacheableOperations', $request->getUserVar('keywords')['nonCacheableOperations'] ?: []);
        $this->readUserVars($vars);
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch
     *
     * @param null|mixed $template
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
        $this->plugin->updateSetting($contextId, 'cacheablePages', json_encode($this->getData('cacheablePages')));
        $this->plugin->updateSetting($contextId, 'nonCacheableOperations', json_encode($this->getData('nonCacheableOperations')));

        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            Application::get()->getRequest()->getUser()->getId(),
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );

        $clearContexts = (array) $this->getData('clearContexts');
        foreach ($clearContexts as $contextId) {
            $contextId = (int) $contextId;
            $basePath = Core::getBaseDir() . '/cache/frontEndCache' . ($contextId ? "/{$contextId}" : '');
            foreach (glob("{$basePath}/*.php") as $file) {
                @unlink($file);
            }
        }

        return parent::execute(...$functionArgs);
    }
}
