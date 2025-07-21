<?php

/**
 * @file classes/ConnectionHooker.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ConnectionHooker
 */

namespace APP\plugins\generic\frontEndCache\classes;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

class ConnectionHooker extends Manager {
	public function __construct()
	{
		/** @var Manager */
		$manager = Manager::$instance;

		Connection::resolverFor('mysql', function (...$args) {
			return new HookedMySqlConnection(...$args);
		});

		Connection::resolverFor('pgsql', function (...$args) {
			return new HookedPostgresConnection(...$args);
		});

		$manager->manager->purge('default');
		$manager->connection('default');
	}
}
