<?php

/**
 * @file classes/HookedPostgresConnection.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HookedPostgresConnection
 */

namespace APP\plugins\generic\frontEndCache\classes;

use HookRegistry;
use Illuminate\Database\PostgresConnection;

class HookedPostgresConnection extends PostgresConnection {
	public function select($query, $bindings = [], $useReadPdo = true) {
		if (HookRegistry::call('HookedMySqlConnection::select', [&$query, &$bindings, &$useReadPdo, &$return]) === true) {
			return $return;
		}

		return parent::select(...func_get_args());
	}
}
