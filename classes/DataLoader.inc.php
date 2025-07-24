<?php

/**
 * @file classes/DataLoader.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DataLoader
 */

namespace APP\plugins\generic\frontEndCache\classes;

use APP\Services\GalleyService;
use APP\Services\PublicationService;
use DAO;
use DAORegistry;
use Exception;
use Generator;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Collection;
use PKP\Services\PKPAuthorService;
use Services;

class DataLoader {
	// WeakMap in PHP 8
	/** @var array<int,array{object,static}> */
	private static array $references = [];
	private array $dataSet = [];
	/** @var ?int */
	private $ownerId = null;

	private function __construct()
	{
	}

	public static function toGenerator(iterable $iterator, self $loader): Generator
	{
		$setOwner = function ($value) use (&$initialized, $loader) {
			$object = null;
			foreach (debug_backtrace() as $frame) {
				$object = $frame['object'] ?? null;
				// Attempts to assign the data loader to a good common ancestor
				// DAOResultFactory is great, as it will be released later on
				// DAO are not that great, because they are static (will keep old/invalid caches)
				if ($object instanceof DAO || $object instanceof DAOResultFactory) {
					break;
				}
			}

			$loader->setOwner($object);
			return $value;
		};
		foreach ($iterator as $key => $value) {
			yield $key => $loader->ownerId ? $value : $setOwner($value);
		}
	}

	public static function find(): ?self
	{
		$traceList = debug_backtrace();
		array_shift($traceList);
		foreach ($traceList as $trace) {
			if (!($object = $trace['object'] ?? null)) {
				continue;
			}

			$reference = static::$references[spl_object_id($object)] ?? null;
			if ($reference && $object === $reference[0]) {
				return $reference[1];
			}
		}

		return null;
	}

	public static function findInDataSets(string $table, $key, DataLoader &$foundDataLoader = null): ?Collection
	{
		$dataSet = null;
		foreach (static::$references as [, $dataLoader]) {
			if (($dataSet = $dataLoader->dataSet[$table] ?? null) !== null) {
				$foundDataLoader = $dataLoader;
				if ($list = $dataSet[$key] ?? null) {
					return collect($list);
				}
			}
		}

		return $dataSet === null ? null : collect();
	}

	public function setOwner(object $owner): void
	{
		if ($this->ownerId) {
			return;
		}
		$this->ownerId = spl_object_id($owner);
		static::$references[$this->ownerId] = [$owner, $this];
	}

	public static function create(): ?self
	{
		return new static();
	}

	public function getDataSet(string $table, $keys): ?Collection
	{
		$dataset = $this->dataSet[$table] ?? null;
		if (!isset($dataset)) {
			return null;
		}

		foreach ((array) $keys as $key) {
			if (!array_key_exists($key, $dataset)) {
				return null;
			}

			$dataset = $dataset[$key];
		}

		return collect($dataset);
	}

	private function addSettings(string $table, string $keyField, array $parentIds)
	{
		$this->dataSet[$table] = [];
		if (!count($parentIds)) {
			return;
		}

		$records = Manager::table($table)
			->whereIn($keyField, $parentIds)
			->get();
		foreach ($records as $record) {
			$this->dataSet[$table][$record->$keyField][] = $record;
		}

		// Setup empty arrays, just to flag that it was attempted to find dependent records
		$this->dataSet[$table] += array_fill_keys(array_diff($parentIds, array_keys($this->dataSet[$table])), []);
	}

	public function addDataSet(string $table, iterable $records, string $primaryKey, $groupBy, array $parentIds = []): Collection
	{
		if (isset($this->dataSet[$table])) {
			throw new Exception('Data set already defined');
		}

		$groupBy = (array) $groupBy;
		$this->dataSet[$table] = [];
		$ids = $output = [];
		foreach ($records as $record) {
			$output[] = $record;
			$ids[] = $record->$primaryKey;
			$current = &$this->dataSet[$table];
			foreach ($groupBy as $key) {
				if (!isset($current[$record->$key])) {
					$current[$record->$key] = [];
				}

				$current = &$current[$record->$key];
			}
			$current[] = $record;
		}

		// Setup empty arrays, just to flag that it was attempted to find dependent records
		$this->dataSet[$table] += array_fill_keys(array_diff($parentIds, array_keys($this->dataSet[$table])), []);
		$singularTable = preg_replace(['/ies$/', '/s$/'], ['y', ''], $table);
		if (!str_ends_with($table, '_settings') && Manager::connection()->getSchemaBuilder()->hasTable("{$singularTable}_settings")) {
			$this->addSettings("{$singularTable}_settings", $table === 'publication_galleys' ? 'galley_id' : "{$singularTable}_id", $ids);
		}

		if (!count($ids)) {
			return collect($output);
		}

		switch ($table) {
			case 'submissions':
				// Publications
				/** @var PublicationService */
				$publicationService = Services::get('publication');
				$records = $publicationService->getQueryBuilder()
					->filterBySubmissionIds($ids)
					->getQuery()
					->get();
				$this->addDataSet(
					'submissions.locale',
					collect($output)->map(
						function (object $row) {
							return (object) ['submission_id' => $row->submission_id, 'locale' => $row->locale];
						}
					),
					'submission_id',
					'submission_id'
				);
				$this->addDataSet('publications', $records, 'publication_id', 'submission_id', $ids);
				break;

			case 'publications':
				// Authors
				/** @var PKPAuthorService */
				$authorService = Services::get('author');
				$records = $authorService->getQueryBuilder()
					->filterByPublicationIds($ids)
					->getQuery()
					->get();
				$this->addDataSet('authors', $records, 'author_id', 'publication_id', $ids);

				// Controlled vocabularies
				foreach (['SubmissionKeywordDAO', 'SubmissionSubjectDAO', 'SubmissionDisciplineDAO', 'SubmissionLanguageDAO', 'SubmissionAgencyDAO'] as $dao) {
					// Load constant
					DAORegistry::getDAO($dao);
				}

				$records = Manager::table('controlled_vocabs')
					->whereIn('symbolic', [CONTROLLED_VOCAB_SUBMISSION_KEYWORD, CONTROLLED_VOCAB_SUBMISSION_SUBJECT, CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE, CONTROLLED_VOCAB_SUBMISSION_LANGUAGE, CONTROLLED_VOCAB_SUBMISSION_AGENCY])
					->where('assoc_type', ASSOC_TYPE_PUBLICATION)
					->whereIn('assoc_id', $ids)
					->get();
				$this->addDataSet('controlled_vocabs', $records, 'controlled_vocab_id', ['assoc_id', 'symbolic'], $ids);

				// Categories
				$records = Manager::table('categories', 'c')
					->join('publication_categories AS pc', 'pc.category_id', '=', 'c.category_id')
					->whereIn('pc.publication_id', $ids)
					->get();
				$this->addDataSet('categories', $records, 'category_id', 'publication_id', $ids);

				// Galleys
				/** @var GalleyService */
				$galleyService = Services::get('galley');
				$records = $galleyService->getQueryBuilder()
					->filterByPublicationIds($ids)
					->getQuery()
					->get();
				$this->addDataSet('publication_galleys', $records, 'galley_id', 'publication_id', $ids);
				break;

			case 'controlled_vocabs':
				// Keywords
				$records = Manager::table('controlled_vocab_entries')
					->whereIn('controlled_vocab_id', $ids)
					->orderBy('seq')
					->get();
				$this->addDataSet('controlled_vocab_entries', $records, 'controlled_vocab_entry_id', 'controlled_vocab_id', $ids);
				break;

			case 'publication_galleys':
				// Galleys
				$records = Manager::table('submission_files', 'sf')
					->leftJoin('submissions AS s', 's.submission_id', '=', 'sf.submission_id')
					->leftJoin('files as f', 'f.file_id', '=', 'sf.file_id')
					->whereIn(
						'sf.submission_file_id',
						Manager::table('publication_galleys', 'pg')
							->whereIn('pg.galley_id', $ids)
							->select('pg.submission_file_id')
					)
					->select(['sf.*', 'f.*', 's.locale as locale'])
					->get();
				$this->addDataSet('submission_files', $records, 'submission_file_id', 'submission_file_id');
				break;

			case 'issues':
				// Issue Galleys
				$records = Manager::table('issue_galleys', 'g')
					->leftJoin('issue_files AS f', 'g.file_id', '=', 'f.file_id')
					->whereIn('g.issue_id', $ids)
					->orderBy('g.seq')
					->select('g.*', 'f.file_name', 'f.original_file_name', 'f.file_type', 'f.file_size', 'f.content_type', 'f.date_uploaded', 'f.date_modified')
					->get();
				$this->addDataSet('issue_galleys', $records, 'galley_id', 'issue_id', $ids);

				// Issue Files
				$records = Manager::table('issue_files')
					->whereIn('issue_id', $ids)
					->get();
				$this->addDataSet('issue_files', $records, 'file_id', 'file_id');
				break;
		}

		return collect($output);
	}
}
