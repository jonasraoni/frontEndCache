<?php

/**
 * @file FrontEndCachePlugin.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FrontEndCachePlugin
 */

namespace APP\plugins\generic\frontEndCache;

use AjaxModal;
use APP\plugins\generic\frontEndCache\classes\SettingsForm;
use Application;
use AppLocale;
use Config;
use Core;
use DAORegistry;
use FileManager;
use GenericPlugin;
use HookRegistry;
use Issue;
use IssueDAO;
use JSONMessage;
use LinkAction;
use NotificationManager;
use Request;
use Series;
use SeriesDAO;
use Services;
use Submission;
use TemplateManager;
use Validation;

import('lib.pkp.classes.plugins.GenericPlugin');

class FrontEndCachePlugin extends GenericPlugin
{
	private const COUNTER_DUPLICATED_CLICK_THRESHOLD = 30;
	private const GZIP_HEADER = "\x1f\x8b";
	/** Whether to send cache headers to the client */
	private bool $useCacheHeader = true;
	/** Whether to use GZIP compression */
	private bool $useCompression = true;
	/** Whether to trigger statistics when serving cached content */
	private bool $useStatistics = true;
	/** Time to live of the cache in seconds */
	private int $timeToLiveInSeconds = 3600;
	/**
	 * List of cacheable pages
	 * @var string[]
	 */
	private array $cacheablePages = [
		'about', 'announcement', 'help', 'index', 'information', 'sitemap', 'catalog',
		// OJS
		'article', 'issue',
		// OPS
		'preprint', 'preprints'
	];
	/**
	 * List of non-cacheable pages/operations
	 * @var string[]
	 */
	private array $nonCacheableOperations = [
		'catalog/fullSize', 'catalog/thumbnail', 'catalog/download',
		// OJS
		'article/download',
		'issue/download',
		// OPS
		'preprint/download',
		'preprints/fullSize', 'preprints/thumbnail'
	];
	private ?string $cacheFilename;
	private bool $wasStatisticsTriggered = false;

	/**
	 * @copydoc Plugin::register
	 *
	 * @param null|int $mainContextId
	 */
	public function register($category, $path, $mainContextId = null): bool
	{
		$success = parent::register($category, $path, $mainContextId);
		if (!$success || !$this->getEnabled()) {
			return $success;
		}

		$this->useCacheHeader = (bool) $this->getSetting($this->getCurrentContextId(), 'useCacheHeader');
		$this->useCompression = function_exists('gzencode') && (bool) $this->getSetting($this->getCurrentContextId(), 'useCompression');
		$this->useStatistics = (bool) $this->getSetting($this->getCurrentContextId(), 'useStatistics');
		$this->timeToLiveInSeconds = (int) $this->getSetting($this->getCurrentContextId(), 'timeToLiveInSeconds');
		$this->cacheablePages = json_decode($this->getSetting($this->getCurrentContextId(), 'cacheablePages')) ?: [];
		$this->nonCacheableOperations = json_decode($this->getSetting($this->getCurrentContextId(), 'nonCacheableOperations')) ?: [];
		$this->useAutoLoader();
		$this->installDispatcherHook();
		return $success;
	}


	/**
	 * Setups the main plugin hook
	 */
	public function installDispatcherHook(): void
	{
		HookRegistry::register('Dispatcher::dispatch', function (string $hookName, Request $request){
			if (!$this->isCacheable($request)) {
				return false;
			}

			if ($this->trySendCache($request)) {
				exit;
			}

			$this->cacheContent($request);
			return false;
		});
	}

	/**
	 * Registers a custom autoloader to handle the plugin namespace
	 */
	private function useAutoLoader(): void
	{
		spl_autoload_register(function ($className) {
			// Removes the base namespace from the class name
			$path = explode(__NAMESPACE__ . '\\', $className, 2);
			if (!reset($path)) {
				// Breaks the remaining class name by \ to retrieve the folder and class name
				$path = explode('\\', end($path));
				$class = array_pop($path);
				$path = array_map(function ($name) {
					return strtolower($name[0]) . substr($name, 1);
				}, $path);
				$path[] = $class;
				// Uses the internal loader
				$this->import(implode('.', $path));
			}
		});
	}

	/**
	 * Determine whether or not the request is cacheable.
	 */
	public function isCacheable(Request $request): bool
	{
		if (
			defined('SESSION_DISABLE_INIT')
			|| !Config::getVar('general', 'installed')
			|| !empty($_POST)
			|| Validation::isLoggedIn()
		) {
			return false;
		}

		if ($request->isPathInfoEnabled()) {
			if (!empty($_GET)) {
				return false;
			}
		} else {
			$params = array_merge(Application::get()->getContextList(), ['page', 'op', 'path']);
			if (!empty($_GET) && count(array_diff(array_keys($_GET), $params)) !== 0) {
				return false;
			}
		}

		$page = $request->getRequestedPage() ?: 'index';
		if(!in_array($page, $this->cacheablePages)) {
			return false;
		}

		// Skip caching binary files/downloads
		$operation = $request->getRequestedOp();
		if (in_array("{$page}/{$operation}", $this->nonCacheableOperations)) {
			return false;
		}

		return true;
	}

	/**
	 * @copydoc PKPRouter::getCacheFilename()
	 */
	public function getCacheFilename(Request $request): string
	{
		if (isset($this->cacheFilename)) {
			return $this->cacheFilename;
		}

		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$basePath = Core::getBaseDir() . '/cache/frontEndCache';
		if (!$fileManager->fileExists($basePath)) {
			$fileManager->mkdir($basePath);
		}

		$context = $request->getContext();
		$id = md5(implode('/', [
			$context ? $context->getPath() : '',
			$request->isPathInfoEnabled()
				? ($_SERVER['PATH_INFO'] ?? 'index')
				: $request->getUserVar('page') . '/' . $request->getUserVar('op') . '/' . $request->getUserVar('path'),
			AppLocale::getLocale()
		]));
		return $this->cacheFilename = "{$basePath}/{$id}.php";
	}

	/**
	 * Attempts to send the cached data
	 */
	public function trySendCache(Request $request): bool
	{
		$filename = $this->getCacheFilename($request);
		if (!file_exists($filename)) {
			return false;
		}

		$cache = require_once $filename;
		// Validates the browser cache
		if ($this->useCacheHeader && ((int) $request->getIfModifiedSince() >= $cache['time'] || ($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === (string) $cache['hash'])) {
			$this->triggerStatistics($request, $cache);
			header('HTTP/1.1 304 Not Modified', true, 304);
			return true;
		}

		$expiry = $cache['time'] + $this->timeToLiveInSeconds;
		$timeToLiveInSeconds = $expiry - time();
		if ($timeToLiveInSeconds <= 0) {
			return false;
		}

		$this->triggerStatistics($request, $cache);
		$this->sendHeaders($cache);
		echo $cache['output'];
		return true;
	}

	/**
	 * Send the headers
	 */
	public function sendHeaders(array &$cache): void
	{
		foreach ($cache['headers'] as $header) {
			header($header);
		}

		$expiry = $cache['time'] + $this->timeToLiveInSeconds;
		$timeToLiveInSeconds = $expiry - time();
		// According to the COUNTER specs, if the same URL receives N views under 30 seconds, then it be counted as a single view
		// In case the URL leads to an entry in the statistics, we setup the max-age to 30 seconds
		// Then, if the user visits the same URL, it won't trigger a new request, and the user will be able to benefit from a longer cache
		if ($cache['counted']) {
			$timeToLiveInSeconds = min(static::COUNTER_DUPLICATED_CLICK_THRESHOLD, $timeToLiveInSeconds);
		}

		if ($this->useCacheHeader) {
			header("cache-control: public, max-age={$timeToLiveInSeconds}, must-revalidate");
			header("etag: {$cache['hash']}");
		}

		// If the content is gzipped
		if (substr($cache['content'], 0, 2) === static::GZIP_HEADER) {
			// If the client doesn't accept gzip, then we've got to decode the content
			if (!preg_match('/\bgzip\b/i', $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')) {
				$cache['content'] = gzdecode($cache['content']);
			} else {
				header('content-encoding: gzip');
				header('content-length: ' . strlen($cache['content']));
			}
		}
	}

	/**
	 * Feed data for the statistics plugin
	 */
	public function triggerStatistics(Request $request, array $cache): void
	{
		if (!$this->useStatistics) {
			return;
		}

		$templateManager = TemplateManager::getManager();
		$templateManager->assign('currentContext', $request->getContext());

		// OJS
		if (($issueId = $cache['issue'] ?? null) && class_exists(Issue::class)) {
			$issueDao = DAORegistry::getDAO('IssueDAO'); /** @var IssueDAO $issueDao */
			$templateManager->assign('issue', $issueDao->getById($issueId));
		}

		// OMP
		if (($seriesId = $cache['series'] ?? null) && class_exists(Series::class)) {
			$seriesDao = DAORegistry::getDAO('SeriesDAO'); /** @var SeriesDAO Dao */
			$templateManager->assign('series', $seriesDao->getById($seriesId));
		}

		// Submission
		foreach (['article', 'publishedSubmission', 'preprint'] as $variable) {
			if ($id = $cache[$variable] ?? null) {
				$templateManager->assign($variable, Services::get('submission')->get($id));
			}
		}

		$output = $template = '';
		HookRegistry::call('TemplateManager::display', [$templateManager, &$template, &$output]);
	}

	/**
	 * Cache the output in a local file
	 */
	public function cacheContent(Request $request): void
	{
		$cache = [];
		// Retrieve and store useful IDs from the template at the end of the processing
		HookRegistry::register('UsageEventPlugin::getUsageEvent', function (string $hookName, array $args) use ($request, &$cache) {
			$templateManager = TemplateManager::getManager($request);
			$this->wasStatisticsTriggered = (bool) ($args[1] ?? false);
			$variableClassMap = [
				// OJS
				'issue' => Issue::class,
				'article' => Submission::class,
				// OMP
				'series' => Series::class,
				'publishedSubmission' => Submission::class,
				// OPS
				'preprint' => Submission::class
			];

			foreach ($variableClassMap as $variable => $className) {
				if (($object = $templateManager->getTemplateVars($variable)) && class_exists($className) && is_a($object, $className)) {
					$cache += [$variable => $object->getId()];
				}
			}

			return false;
		}, HOOK_SEQUENCE_LAST);

		ob_start(function (string $content) use (&$cache): string {
			if (!$content) {
				return '';
			}

			$filename = $this->getCacheFilename(Application::get()->getRequest());
			$output = $this->useCompression ? gzencode($content) : $content;
			$cache += [
				'time' => time(),
				'headers' => headers_list(),
				'hash' => crc32($output),
				'content' => $output,
				'counted' => $this->wasStatisticsTriggered
			];
			file_put_contents($filename, '<?php return ' . var_export($cache, true) . ';', LOCK_EX);
			$this->sendHeaders($cache);
			return $output;
		});
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs): array
	{
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}

		$router = $request->getRouter();
		array_unshift(
			$actions,
			new LinkAction(
				'settings',
				new AjaxModal($router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']), $this->getDisplayName()),
				__('manager.plugins.settings'),
				null
			)
		);
		return $actions;
	}

		/**
	 * Generate a JSONMessage response to display the settings
	 */
	private function displaySettings(): JSONMessage
	{
		$form = new SettingsForm($this);
		$request = Application::get()->getRequest();
		if ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				$notificationManager = new NotificationManager();
				$notificationManager->createTrivialNotification($request->getUser()->getId());
				return new JSONMessage(true);
			}
		} else {
			$form->initData();
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request)
	{
		if ($request->getUserVar('verb') === 'settings') {
			return $this->displaySettings();
		}

		return parent::manage($args, $request);
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	public function getName(): string
	{
		$class = explode('\\', __CLASS__);
		return end($class);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName(): string
	{
		return __('plugins.generic.frontEndCache.name');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription(): string
	{
		return __('plugins.generic.frontEndCache.description');
	}

	/**
	 * @copydoc Plugin::getSeq()
	 */
	public function getSeq(): int
	{
		return -1;
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	public function isSitePlugin() : bool
	{
		return true;
	}

	/**
	 * Overrides to always return the site context
	 * @copydoc Plugin::getCurrentContextId(()
	 */
	public function getCurrentContextId(): int
	{
		return 0;
	}

	/**
	 * @copydoc Plugin::getInstallSitePluginSettingsFile()
	 */
	public function getInstallSitePluginSettingsFile(): string
	{
		return $this->getPluginPath() . '/settings.xml';
	}
}
