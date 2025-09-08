<?php

/**
 * @file classes/HookedMySqlConnection.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HookedMySqlConnection
 */

namespace APP\plugins\generic\frontEndCache\classes;

use HookRegistry;
use Illuminate\Database\MySqlConnection;

class HookedMySqlConnection extends MySqlConnection {
	public function select($query, $bindings = [], $useReadPdo = true) {
		if (HookRegistry::call('HookedConnection::select', [&$query, &$bindings, &$useReadPdo, &$return]) === true) {
			return $return;
		}

		return parent::select(...func_get_args());
	}
}
