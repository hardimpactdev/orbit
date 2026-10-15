<?php

declare(strict_types=1);

/*
 * PHPStan starts as many workers as tools/check-processes.php allows: ORBIT_CHECK_PROCESSES, or the core count up to 4.
 */

$processes = require __DIR__.'/../check-processes.php';

return ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => $processes]]];
