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
use APP\plugins\generic\frontEndCache\classes\ConnectionHooker;
use APP\plugins\generic\frontEndCache\classes\DataLoader;
use APP\plugins\generic\frontEndCache\classes\SettingsForm;
use APP\plugins\generic\frontEndCache\classes\CacheRule;
use Application;
use AppLocale;
use Config;
use Core;
use DAORegistry;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use FileManager;
use GenericPlugin;
use HookRegistry;
use Illuminate\Database\Capsule\Manager;
use Issue;
use IssueDAO;
use JSONMessage;
use LinkAction;
use NotificationManager;
use PKPPageRouter;
use Request;
use Series;
use SeriesDAO;
use Services;
use SplFileObject;
use Submission;
use TemplateManager;
use Throwable;
use Validation;

import('lib.pkp.classes.plugins.GenericPlugin');

class FrontEndCachePlugin extends GenericPlugin
{
	/** A value to invalidate the cache if its structure gets changed in the future */
	private const STRUCTURE_VERSION = 1;
	private const COUNTER_DUPLICATED_CLICK_THRESHOLD = 10;
	private const GZIP_HEADER = "\x1f\x8b";
	/** @var bool Whether to send cache headers to the client */
	private $useCacheHeader = true;
	/** @var bool Whether to use GZIP compression */
	private $useCompression = true;
	/** @var bool Whether to trigger statistics when serving cached content */
	private $useStatistics = true;
	/** @var bool Whether to cache CSS files */
	private $cacheCss = true;
	/** @var bool Whether to eager load database entities */
	private $useEagerLoading = true;
	/** @var int Time to live of the cache in seconds */
	private $timeToLiveInSeconds = 3600;
	/** @var string[] List of cacheable pages */
	private $cacheablePages = [
		'about', 'announcement', 'help', 'index', 'information', 'sitemap', 'catalog',
		// OJS
		'article', 'issue',
		// OPS
		'preprint', 'preprints'
	];
	/**
	 * @var string[] List of non-cacheable pages/operations */
	private $nonCacheableOperations = [
		'catalog/fullSize', 'catalog/thumbnail', 'catalog/download',
		// OJS
		'article/download',
		'issue/download',
		// OPS
		'preprint/download',
		'preprints/fullSize', 'preprints/thumbnail'
	];
	/** @var CacheRule[] List of cache rules for smart validation */
	private $cacheRules = [];
	/** @var ?string Cached filename */
	private $cacheFilename = null;
	/** @var bool Keeps track if statistics were generated for the current request */
	private $wasStatisticsTriggered = false;
	/** @var ?string Cached integrity hash */
	private $integrityHash = -1;
	/** @var ?bool Cached is route cacheable */
	private $isRouteCacheable = null;
	/** @var ?bool Cached has cache rule match */
	private $hasMatchedRuleCache = false;
	/** @var ?CacheRule Cached matched rule */
	private $matchedRule = null;

	/**
	 * @copydoc Plugin::register
	 *
	 * @param null|int $mainContextId
	 */
	public function register($category, $path, $mainContextId = null): bool
	{
		$success = parent::register($category, $path, $mainContextId);
		if (!$success || !$this->getEnabled() || !Config::getVar('general', 'installed')) {
			return $success;
		}

		$this->useAutoLoader();
		$this->useCacheHeader = (bool) $this->getSetting($this->getCurrentContextId(), 'useCacheHeader');
		$this->useCompression = function_exists('gzencode') && (bool) $this->getSetting($this->getCurrentContextId(), 'useCompression');
		$this->useStatistics = (bool) $this->getSetting($this->getCurrentContextId(), 'useStatistics');
		$this->cacheCss = (bool) $this->getSetting($this->getCurrentContextId(), 'cacheCss');
		$useEagerLoading = $this->getSetting($this->getCurrentContextId(), 'useEagerLoading');
		if ($useEagerLoading === null) {
			$this->updateSetting($this->getCurrentContextId(), 'useEagerLoading', true, 'bool');
			$useEagerLoading = true;
		}
		$this->useEagerLoading = (bool) $useEagerLoading;
		$this->timeToLiveInSeconds = (int) $this->getSetting($this->getCurrentContextId(), 'timeToLiveInSeconds');
		$this->cacheablePages = (array) json_decode($this->getSetting($this->getCurrentContextId(), 'cacheablePages')) ?: [];
		$this->nonCacheableOperations = (array) json_decode($this->getSetting($this->getCurrentContextId(), 'nonCacheableOperations')) ?: [];

		$cacheRules = $this->getSetting($this->getCurrentContextId(), 'cacheRules');
		if ($cacheRules === null) {
			(new SettingsForm($this))->resetDefaultRules();
			$cacheRules = $this->getSetting($this->getCurrentContextId(), 'cacheRules');
		}

		$this->cacheRules = [];
		foreach (json_decode($cacheRules, true) ?: [] as $pattern => $query) {
			$this->cacheRules[] = CacheRule::create($pattern, $query);
		}

		$this->installDispatcherHook();
		$this->installDatabaseCacheHook();
		return true;
	}

	private function retrieve($sql, $params = [], $dbResultRange = null) {
		if ($dbResultRange && $dbResultRange->isValid()) {
			$sql .= ' LIMIT ' . (int) $dbResultRange->getCount();
			$offset = (int) $dbResultRange->getOffset();
			$offset += max(0, $dbResultRange->getPage()-1) * (int) $dbResultRange->getCount();
			$sql .= ' OFFSET ' . $offset;
		}

		return Manager::cursor(Manager::raw($sql), $params);
	}

	/**
	 * Setups the database cache  hook
	 */
	private function installDatabaseCacheHook(): void
	{
		if (!$this->useEagerLoading) {
			return;
		}

		new ConnectionHooker();
		$controlledVocabEntries = [
			function (DataLoader $dataLoader, $controlledVocabId) {
				return $dataLoader->getDataSet('controlled_vocab_entries', $controlledVocabId);
			},
			function (DataLoader $dataLoader, iterable $records) {
				return $dataLoader->addDataSet('controlled_vocab_entries', $records, 'controlled_vocab_entry_id', 'controlled_vocab_id');
			}
		];
		foreach ([
			'pkp\services\issueservice::_getmany' => [
				function (DataLoader $dataLoader, $contextId) {
					return $dataLoader->getDataSet('issues', $contextId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('issues', $records, 'issue_id', 'journal_id');
				}
			],
			'pkp\services\pkpsubmissionservice::_getmany' => [
				function (DataLoader $dataLoader, $contextId) {
					return $dataLoader->getDataSet('submissions', $contextId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('submissions', $records, 'submission_id', 'context_id');
				}
			],
			'pkp\services\pkppublicationservice::_getmany' => [
				function (DataLoader $dataLoader, $submissionId) {
					return $dataLoader->getDataSet('publications', $submissionId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('publications', $records, 'publication_id', 'submission_id');
				}
			],
			'pkp\services\pkpauthorservice::_getmany' => [
				function (DataLoader $dataLoader, $publicationId) {
					return $dataLoader->getDataSet('authors', $publicationId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('authors', $records, 'author_id', 'publication_id');
				}
			],
			'controlledvocabdao::_getbysymbolic' => [
				function (DataLoader $dataLoader, $symbolic, $assocType, $assocId) {
					$dataSet = $dataLoader->getDataSet('controlled_vocabs', $assocId);
					return is_iterable($dataSet) ? ($dataSet[$symbolic] ?? []) : null;
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('controlled_vocabs', $records, 'controlled_vocab_id', ['assoc_id', 'symbolic']);
				}
			],
			'submissionkeywordentrydao::_getbycontrolledvocabid' => $controlledVocabEntries,
			'submissionsubjectentrydao::_getbycontrolledvocabid' => $controlledVocabEntries,
			'submissiondisciplineentrydao::_getbycontrolledvocabid' => $controlledVocabEntries,
			'submissionlanguageentrydao::_getbycontrolledvocabid' => $controlledVocabEntries,
			'submissionagencyentrydao::_getbycontrolledvocabid' => $controlledVocabEntries,
			'categorydao::_getbypublicationid' => [
				function (DataLoader $dataLoader, $publicationId) {
					return $dataLoader->getDataSet('categories', $publicationId);
				},
				function (DataLoader $dataLoader, iterable $records, $publicationId) {
					$records = collect($records)->map(function (object $record) use ($publicationId) {
						$record->publication_id = $publicationId;
						return $record;
					});
					return $dataLoader->addDataSet('categories', $records, 'category_id', 'publication_id');
				}
			],
			'app\services\galleyservice::_getmany' => [
				function (DataLoader $dataLoader, $publicationId) {
					return $dataLoader->getDataSet('publication_galleys', $publicationId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('publication_galleys', $records, 'galley_id', 'publication_id');
				}
			],
			// OJS > Issues
			'issuegalleydao::_getbyissueid' => [
				function (DataLoader $dataLoader, $issueId) {
					return $dataLoader->getDataSet('issue_galleys', $issueId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					return $dataLoader->addDataSet('issue_galleys', $records, 'galley_id', 'issue_id');
				}
			],
			'issuefiledao::_getbyid' => [
				function (DataLoader $dataLoader, $fileId) {
					return $dataLoader->getDataSet('issue_files', $fileId);
				},
				function (DataLoader $dataLoader, iterable $records) {
					$dataLoader->addDataSet('issue_files', $records, 'file_id', 'file_id');
				}
			]
		] as $hook => [$existingDataLoader, $newDataLoader]) {
			$dataLoaderHandler = function (string $hookName, array $args) use ($existingDataLoader, $newDataLoader): bool {
				try {
					if (count($args) === 4) { // retrieveRange()
						[&$sql, &$params, &$range, &$result] = $args;
					} elseif (count($args) === 3) { // retrieve()
						[&$sql, &$params, &$result] = $args;
						$range = null;
					} else {
						throw new Exception('Unexpected error at front end cache plugin');
					}

					$dataLoader = DataLoader::find();
					if ($dataLoader) {
						$iterator = $existingDataLoader($dataLoader, ...$params);
						if (is_iterable($iterator)) {
							$result = DataLoader::toGenerator($iterator, $dataLoader);
							return true;
						}
					}

					$dataLoader = DataLoader::create();
					$result = DataLoader::toGenerator($newDataLoader($dataLoader, $this->retrieve($sql, $params, $range), ...$params), $dataLoader);
					return true;
				} catch (Throwable $e) {
					error_log("Unexpected failure at front end cache plugin\n" . $e);
					return false;
				}
			};
			HookRegistry::register($hook, $dataLoaderHandler);
		}

		HookRegistry::register('HookedConnection::select', function (string $hookName, array $args): bool {
			[$sql, $params, $useReadPdo, &$return] = $args;

			foreach (debug_backtrace() as $frame) {
				if ([$frame['class'] ?? null, $frame['function'] ?? null] === ['PKPPublicationDAO', '_fromRow']) {
					$dataLoader = DataLoader::find();
					if ($dataLoader && ($locale = $dataLoader->getDataSet('submissions.locale', $params[0] ?? 0)[0]->locale ?? null)) {
						$return = [(object) ['locale' => $locale]];
					}

					break;
				}
				elseif ([$frame['class'] ?? null, $frame['function'] ?? null] === ['PKPSubmissionFileDAO', 'getById']) {
					$dataLoader = DataLoader::find();
					if ($dataLoader) {
						$return = $dataLoader->getDataSet('submission_files', $params[0]);
					} elseif ($this->isRouteCacheable()) {
						$return = DataLoader::findInDataSets('submission_files', $params[0], $dataLoader);
					}

					break;
				}
			}

			if (is_iterable($return) && count($return) > 0) {
				$return = DataLoader::toGenerator($return, $dataLoader);
				return true;
			}

			return false;
		});

		$settingsHandler = function (string $hookName, array $args): bool {
			[&$sql, &$params, &$result] = $args;
			if (!preg_match('/FROM\s+(\w+)_settings\s+/i', $sql, $match)) {
				return false;
			}

			$table = $match[1];
			$dataLoader = DataLoader::find();
			if ($dataLoader) {
				$result = $dataLoader->getDataSet($table, $params[0] ?? 0);
			}
			if (!is_iterable($result) && $this->isRouteCacheable()) {
				$result = DataLoader::findInDataSets($table, $params[0] ?? 0, $dataLoader);
			}

			if (is_iterable($result)) {
				$result = DataLoader::toGenerator($result, $dataLoader);
				return true;
			}

			return false;
		};

		foreach(['dao::_getdataobjectsettings', 'schemadao::__fromrow', 'schemadao::_getbyid'] as $hook) {
			HookRegistry::register($hook, $settingsHandler);
		}
	}

	/**
	 * Setups the main plugin hook
	 */
	private function installDispatcherHook(): void
	{
		HookRegistry::register('Dispatcher::dispatch', function (string $hookName){
			try {
				if (!$this->isCacheable()) {
					return false;
				}

				if ($this->trySendCache()) {
					exit;
				}

				$this->cacheContent();
				return false;
			} catch (Throwable $e) {
				error_log("Unexpected failure at front end cache plugin\n" . $e);
				return false;
			}
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
	private function isCacheable(): bool
	{
		if (
			defined('SESSION_DISABLE_INIT')
			|| !empty($_POST)
			|| Validation::isLoggedIn()
		) {
			return false;
		}

		$request = $this->getRequest();
		if ($request->isPathInfoEnabled()) {
			if ($this->cacheCss && strpos($request->getRequestPath(), COMPONENT_ROUTER_PATHINFO_MARKER . '/page/page/css')) {
				return true;
			}

			if (!empty($_GET)) {
				return false;
			}
		} else {
			if ($this->cacheCss && [$request->getUserVar('component'), $request->getUserVar('op')] === ['page.page', 'css']) {
				return true;
			}

			$params = array_merge(Application::get()->getContextList(), ['page', 'op', 'path']);
			if (!empty($_GET) && count(array_diff(array_keys($_GET), $params)) !== 0) {
				return false;
			}
		}

		if (!$this->isRouteCacheable()) {
			return false;
		}

		return true;
	}

	private function isRouteCacheable(): bool
	{
		if ($this->isRouteCacheable !== null) {
			return $this->isRouteCacheable;
		}

		$request = $this->getRequest();
		if (!($request->getRouter() instanceof PKPPageRouter)) {
			return $this->isRouteCacheable = false;
		}

		$page = $request->getRequestedPage() ?: 'index';
		$operation = $request->getRequestedOp();
		// Skip caching binary files/downloads
		if (in_array("{$page}/{$operation}", $this->nonCacheableOperations)) {
			return $this->isRouteCacheable = false;
		}

		// Standard cacheable pages check
		if(in_array($page, $this->cacheablePages)) {
			return $this->isRouteCacheable = true;
		}

		// Check if route has cache rules - if so, it's cacheable regardless of other settings
		if ($this->getMatchedCacheRule()) {
			return $this->isRouteCacheable = true;
		}

		$arguments = $request->getRequestedArgs();
		$path = $page . ($operation !== 'index' ? "/{$operation}" : '') . ($arguments ? '/' . implode('/', $arguments ?: []) : '');
		$context = $request->getContext();
		$isCustomNavigation = Manager::table('navigation_menu_items')
			->where('type', 'NMI_TYPE_CUSTOM')
			->where('context_id', $context ? $context->getId() : 0)
			->where('path', $path)
			->exists();
		if ($isCustomNavigation) {
			return $this->isRouteCacheable = true;
		}

		return $this->isRouteCacheable = false;
	}

	/**
	 * @copydoc PKPRouter::getCacheFilename()
	 */
	private function getCacheFilename(): string
	{
		if (isset($this->cacheFilename)) {
			return $this->cacheFilename;
		}

		$request = $this->getRequest();
		$context = $request->getContext();
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$basePath = Core::getBaseDir() . '/cache/frontEndCache' . ($context ? "/{$context->getId()}" : '');
		if (!$fileManager->fileExists($basePath)) {
			$fileManager->mkdir($basePath);
		}

		$themePluginPath = $request->getContext() ? $request->getContext()->getData('themePluginPath') : $request->getSite()->getData('themePluginPath');
		$id = md5(($_SERVER['PATH_INFO'] ?? 'index') . http_build_query($request->getUserVars()) . AppLocale::getLocale() . $themePluginPath);
		return $this->cacheFilename = "{$basePath}/{$id}.php";
	}

	/**
	 * Retrieves the cache
	 *
	 * @return ?array{time: int, headers: string[], content: string, hash: int, counted: bool, version: int, integrity: ?string}
	 */
	public function getCache(string $filename, bool $validateExpiration = false): ?array
	{
		try {
			if (!file_exists($filename)) {
				return null;
			}

			$cache = include $filename;
			if (($cache['version'] ?? null) !== static::STRUCTURE_VERSION) {
				return null;
			}

			// Check the integrity if available
			if ($this->getMatchedCacheRule()) {
				return $this->getIntegrityHash() === ($cache['integrity'] ?? null) ? $cache : null;
			}

			if ($validateExpiration && filemtime($filename) + $this->timeToLiveInSeconds <= time()) {
				return null;
			}

			// Validate that cached redirect won't cause a loop
			if ($this->hasRedirectLoop($request, $cache)) {
				error_log("FrontEndCache: Cache invalidated due to redirect loop");
				return null;
			}

			return $cache;
		} catch (Throwable $e) {
			error_log("Failure while including the cache file\n" . $e);
			return null;
		}
	}

	/**
	 * Validate the client cache
	 *
	 * @param array{time: int, headers: string[], content: string, hash: int, counted: bool, version: int} $cache
	 */
	private function validateClientCache(array $cache, DateTimeInterface $cacheDate, bool $triggerStatistics = true): bool
	{
		if (!$this->useCacheHeader) {
			return false;
		}

		$expiry = $cacheDate->getTimestamp() + $this->timeToLiveInSeconds;
		$timeToLiveInSeconds = $expiry - time();
		// According to the COUNTER specs, if the same URL receives N views under 30 seconds, then it be counted as a single view
		// In case the URL leads to an entry in the statistics, we setup the max-age to 30 seconds
		// Then, if the user visits the same URL, it won't trigger a new request, and the user will be able to benefit from a longer cache
		if ($cache['counted']) {
			$timeToLiveInSeconds = min(static::COUNTER_DUPLICATED_CLICK_THRESHOLD, $timeToLiveInSeconds);
		}

		header("cache-control: public, max-age={$timeToLiveInSeconds}, must-revalidate");
		header("etag: {$cache['hash']}");

		if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) !== (string) $cache['hash']) {
			return false;
		}

		if ($triggerStatistics) {
			$this->triggerStatistics($cache);
		}

		header('HTTP/1.1 304 Not Modified', true, 304);
		return true;
	}

	/**
	 * Attempts to send the cached data
	 */
	private function trySendCache(): bool
	{
		$filename = $this->getCacheFilename();
		// Cache is stale, need to revalidate
		if (!($cache = $this->getCache($filename, true))) {
			return false;
		}

		// Server cache is valid and can be used, so we just need to trigger/emulate the statistics
		$this->triggerStatistics($cache);
		// Here we use the modified date of the cached file as the cache might be very old (e.g. if it was regenerated a long time ago, but still has a valid content)
		$modifiedDate = DateTimeImmutable::createFromFormat('U', filemtime($filename));
		echo $this->sendHeaders($cache, $modifiedDate) ? '' : $cache['content'];
		return true;
	}

	/**
	 * Send the headers, returns true if the client has a valid cache
	 *
	 * @param ?array{time: int, headers: string[], content: string, hash: int, counted: bool, version: int} $cache
	 * @param ?DateTimeInterface $cacheDate If not specified, the function will use the date when the cache was generated
	 */
	private function sendHeaders(array $cache, ?DateTimeInterface $cacheDate = null, bool $triggerStatistics = true): bool
	{
		foreach ($cache['headers'] as $header) {
			header($header);
		}

		// Sends caching headers and checks whether the client has a valid version locally
		if ($this->validateClientCache($cache, $cacheDate ?? DateTimeImmutable::createFromFormat('U', $cache['time']), $triggerStatistics)) {
			return true;
		}

		// If the content is gzipped
		if (substr($cache['content'], 0, 2) === static::GZIP_HEADER) {
			// If the client doesn't accept gzip, then we've got to decode the content
			if (!preg_match('/\bgzip\b/i', $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')) {
				$cache['content'] = gzdecode($cache['content']);
			} else {
				header('content-encoding: gzip');
			}
		}

		header('content-length: ' . strlen($cache['content']));
		return false;
	}

	/**
	 * Feed data for the statistics plugin
	 *
	 * @param ?array{time: int, headers: string[], content: string, hash: int, counted: bool, version: int} $cache
	 */
	private function triggerStatistics(array $cache): void
	{
		if (!$this->useStatistics || !$cache['counted']) {
			return;
		}

		$templateManager = TemplateManager::getManager();
		$templateManager->assign('currentContext', $this->getRequest()->getContext());

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
	private function cacheContent(): void
	{
		$cache = [];
		// Retrieve and store useful IDs from the template at the end of the processing
		HookRegistry::register('UsageEventPlugin::getUsageEvent', function (string $hookName, array $args) use (&$cache) {
			$templateManager = TemplateManager::getManager($this->getRequest());
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

		ob_start(function (string $output) use (&$cache): string {
			// Only cache 200-300 statuses
			if (!in_array((int) (http_response_code() / 100), [2, 3])) {
				return $output;
			}

			if (stripos($output, '<!doctype html') !== false) {
				$output = preg_replace_callback(
					'/<img\s+([^>]*)>/i',
					function (array $matches) {
						$attrs = $matches[1];
						// Skip if already has loading attribute
						return preg_match('/\bloading\s*=/i', $attrs) ? $matches[0] : "<img loading=\"lazy\" {$attrs}>";
					},
					$output
				);
			}

			$integrityHash = $this->getIntegrityHash();

			// Filter out Set-Cookie headers to prevent session sharing
			$headers = array_filter(headers_list(), function (string $header) {
				return stripos($header, 'Set-Cookie:') !== 0;
			});

			$cache += [
				'time' => time(),
				'headers' => $headers,
				'content' => $output = $this->useCompression ? gzencode($output) : $output,
				'hash' => crc32($output),
				'counted' => $this->wasStatisticsTriggered,
				'version' => static::STRUCTURE_VERSION,
				'integrity' => $integrityHash
			];

			try {
				$filename = $this->getCacheFilename();
				$cacheExists = file_exists($filename);
				$file = new SplFileObject($filename, 'c');
				try {
					// Acquire a shared lock for reading
					if ($file->flock(LOCK_SH)) {
						$existingCache = null;
						try {
							$existingCache = $cacheExists ? include $filename : null;
							if (($existingCache['version'] ?? null) !== static::STRUCTURE_VERSION) {
								$existingCache = null;
							}
						} catch (Throwable $e) {
							error_log("Failure while including the cache file\n" . $e);
						}

						// If the cache is still valid, we don't need to rewrite the file, but we update its modified date as a way to specify that it was revalidated
						if (($existingCache['hash'] ?? null) === $cache['hash'] && ($existingCache['integrity'] ?? null) === $cache['integrity']) {
							$file->flock(LOCK_UN);
							touch($filename);
						} elseif ($file->flock(LOCK_EX | LOCK_NB)) { // Upgrade to an exclusive lock for rewriting the cache
							$file->ftruncate(0);
							$file->fwrite('<?php return ' . var_export($cache, true) . ';');
							$file->fflush();
							$file->flock(LOCK_UN);
						}
					}
				} finally {
					$file = null;
				}
			} catch (Exception $e) {
				error_log("Failure when generating the cache\n" . $e);
			}

			// It's not needed to trigger the statistics at this point
			return $this->sendHeaders($cache, null, false) ? '' : $output;
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
		import('classes.notification.NotificationManager');
		$form = new SettingsForm($this);
		$request = $this->getRequest();
		if ($request->getUserVar('resetRules')) {
			$form->resetDefaultRules();
			$notificationMgr = new NotificationManager();
			$notificationMgr->createTrivialNotification(
				$request->getUser()->getId(),
				NOTIFICATION_TYPE_SUCCESS,
				['contents' => __('plugins.generic.frontEndCache.rulesReset')]
			);
			return new JSONMessage(true);
		}
		elseif ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				$notificationManager = new NotificationManager();
				$notificationManager->createTrivialNotification($request->getUser()->getId());
				return new JSONMessage(true);
			}
		}
		else {
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

	/**
	 * Get integrity hash for a route using cache rules
	 *
	 * @return string|null
	 */
	private function getIntegrityHash(): ?string
	{
		if ($this->integrityHash !== -1) {
			return $this->integrityHash;
		}

		$matchedRule = $this->getMatchedCacheRule();
		if (!$matchedRule) {
			return $this->integrityHash = null;
		}

		$route = $this->getRequest()->getRequestUrl();
		$matchedRule->matches($route, $captures);
		// Get the captured groups from the regex match
		return $this->integrityHash = $matchedRule->getIntegrityHash($captures);
	}

	/**
	 * Check if a route has cache rules defined
	 */
	private function getMatchedCacheRule(): ?CacheRule
	{
		if ($this->hasMatchedRuleCache) {
			return $this->matchedRule;
		}

		$this->hasMatchedRuleCache = true;
		$route = $this->getRequest()->getRequestUrl();
		foreach ($this->cacheRules as $rule) {
			if ($rule->matches($route)) {
				return $this->matchedRule = $rule;
			}
		}

		return null;
	}

	/**
	 * Invalidate cache for the current request
	 */
	private function invalidateCache(Request $request): void
	{
		$filename = $this->getCacheFilename($request);
		if (file_exists($filename)) {
			unlink($filename);
			error_log("FrontEndCache: Invalidated cache due to redirect loop detected: {$filename}");
		}
	}

	/**
	 * Check if the cached content contains a redirect to the current URL (which would cause a loop)
	 */
	private function hasRedirectLoop(Request $request, array $cache): bool
	{
		foreach ($cache['headers'] ?? [] as $header) {
			if (($i = stripos($header, 'Location:')) !== 0) {
				continue;
			}

			$redirectUrl = strtolower(trim(substr($header, $i + 9), " \n\r\t\v\x00\\"));
			$currentUrl = strtolower(trim($request->getCompleteUrl(), " \n\r\t\v\x00\\"));

			return $currentUrl === $redirectUrl;
		}

		return true;
	}
}
