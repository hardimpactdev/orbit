<?php

declare(strict_types=1);

/*
 * PHPStan starts as many workers as the machine has cores. ORBIT_CHECK_PROCESSES lowers that number.
 */

$processes = require __DIR__.'/../check-processes.php';

return is_int($processes) ? ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => $processes]]] : [];
