<?php

/**
 * @file classes/CacheRule.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CacheRule
 */

namespace APP\plugins\generic\frontEndCache\classes;

use Application;
use AppLocale;
use Exception;
use Illuminate\Database\Capsule\Manager;

class CacheRule
{
	/** @var string The regular expression pattern to match routes */
	private $pattern;

	/** @var string The SQL query to execute for cache validation */
	private $query;

	/**
	 * Constructor
	 *
	 * @param string $pattern The regular expression pattern
	 * @param string $query The SQL query
	 */
	public function __construct(string $pattern, string $query)
	{
		$this->pattern = $pattern;
		$this->query = $query;
	}

	/**
	 * Check if a route matches this rule
	 *
	 * @param string $route The route to check
	 * @return bool True if the route matches
	 */
	public function matches(string $route, ?array &$captures = []): bool
	{
		return preg_match($this->pattern, $route, $captures);
	}

	/**
	 * Execute the SQL query and return the integrity hash
	 *
	 * @param array $captures The captured groups from the regex match
	 * @return string|null The integrity hash or null if query fails
	 */
	public function getIntegrityHash(array $captures = []): ?string
	{
		try {
			// Replace regex capture groups in the query
			$query = $this->buildQuery($this->query, $captures);
			if (!preg_match('/^\s*select/i', $query)) {
				throw new Exception('Query must be a SELECT statement');
			}

			// Execute the query
			$result = Manager::connection()->select($query);
			if (empty($result)) {
				return null;
			}

			// Get the first row and first column value
			$row = reset($result);
			$column = null;
			foreach ($row as $column) {
				break;
			}

			return (string) crc32($column);
		} catch (Exception $e) {
			error_log("Cache rule query failed: {$e}");
			return null;
		}
	}

	/**
	 * Process the SQL query by replacing regex capture groups
	 *
	 * @param string $query The original query
	 * @param array $captures The captured groups
	 * @return string The processed query
	 */
	private function buildQuery(string $query, array $captures): string
	{
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		$query = str_replace(
			array_merge(array_map(function ($i) { return '$' . $i; }, array_keys($captures)), ['$contextId', '$userId', '$locale']),
			array_merge(array_map(function ($v) { return Manager::connection()->getPdo()->quote($v); }, $captures), [$context ? $context->getId() : 'null', $user ? $user->getId() : 'null', AppLocale::getLocale()]),
			$query
		);

		return $query;
	}

	/**
	 * Create a CacheRule from an array
	 *
	 * @param array $data Array with 'pattern' and 'query' keys
	 * @return static
	 */
	public static function create(string $pattern, string $query): self
	{
		return new static($pattern, $query);
	}
}
