<?php

/**
 * @file classes/UserGroupDAO.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UserGroupDAO
 */

namespace APP\plugins\generic\frontEndCache\classes;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\MySqlConnection;

class UserGroupDAO extends \UserGroupDAO {
	/**
	 * Overwrites the function due to a bad string comparison of get_class(Capsule::connection()) with MySqlConnection::class
	 * @copydoc \UserGroupDAO::_getSearchSQL()
	 */
	public function _getSearchSql($searchType, $search, $searchMatch, &$params)
	{
		$hasUserSetting = "EXISTS(
			SELECT 0
			FROM user_settings
			WHERE user_id = u.user_id
				AND setting_name = '%s'
				AND LOWER(setting_value) LIKE LOWER(?)
		)";
		$searchTypeMap = [
			IDENTITY_SETTING_GIVENNAME => sprintf($hasUserSetting, IDENTITY_SETTING_GIVENNAME),
			IDENTITY_SETTING_FAMILYNAME => sprintf($hasUserSetting, IDENTITY_SETTING_FAMILYNAME),
			USER_FIELD_USERNAME => 'LOWER(u.username) LIKE LOWER(?)',
			USER_FIELD_EMAIL => 'LOWER(u.email) LIKE LOWER(?)',
			USER_FIELD_AFFILIATION => sprintf($hasUserSetting, USER_FIELD_AFFILIATION)
		];

		$searchSql = '';
		$search = trim($search ?? '');
		if (!empty($search)) {
			if (!isset($searchTypeMap[$searchType])) {
				$terms = array_map(function ($term) {
					return "%$term%";
				}, \PKPString::regexp_split('/\s+/', $search));
				$filters = [];

				if (Capsule::connection() instanceof MySqlConnection) {
					$concatSettingValue = "GROUP_CONCAT(setting_value SEPARATOR '')";
				}
				else {
					$concatSettingValue = "STRING_AGG(setting_value, '')";
				}

				$userSetting = "COALESCE((
					SELECT $concatSettingValue
					FROM user_settings
					WHERE user_id = u.user_id
					AND setting_name = '%s'
				), '')";

				// Concat key user fields to search
				$filters[] = '(1 = 1' . str_repeat(' AND LOWER(' . $this->concat(
					sprintf($userSetting, IDENTITY_SETTING_GIVENNAME),
					sprintf($userSetting, IDENTITY_SETTING_FAMILYNAME),
					'u.email',
					sprintf($userSetting, USER_FIELD_AFFILIATION),
					'u.username'
				) . ') LIKE LOWER(?)', count($terms)) . ')';
				array_push($params, ...$terms);

				// Search the user interests
				$filters[] = '
					EXISTS(
						SELECT 0
						FROM user_interests ui
						INNER JOIN controlled_vocab_entry_settings cves
							ON ui.controlled_vocab_entry_id = cves.controlled_vocab_entry_id
						WHERE
							u.user_id = ui.user_id
							' . str_repeat(' AND LOWER(cves.setting_value) LIKE LOWER(?)', count($terms)) . '
					)';
				array_push($params, ...$terms);

				$searchSql .= 'AND (' . implode(' OR ', $filters) . ') ';
			} else {
				$filter = $searchTypeMap[$searchType];
				$searchSql = "AND $filter";
				switch ($searchMatch) {
					case 'is':
						$params[] = $search;
						break;
					case 'contains':
						$params[] = '%' . $search . '%';
						break;
					case 'startsWith':
						$params[] = $search . '%';
						break;
				}
			}
		} else {
			switch ($searchType) {
				case USER_FIELD_USERID:
					$searchSql = 'AND u.user_id = ?';
					break;
			}
		}

		return $searchSql;
	}
}
