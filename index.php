<?php

/**
 * @file index.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Wrapper for FrontEndCachePlugin plugin
 */

namespace APP\plugins\generic\frontEndCache;

require_once 'FrontEndCachePlugin.inc.php';

return new FrontEndCachePlugin();
