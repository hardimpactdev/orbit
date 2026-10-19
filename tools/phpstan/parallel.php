<?php

declare(strict_types=1);

/*
 * PHPStan reads the shared worker budget through tools/check-processes.php.
 */

$processes = require __DIR__.'/../check-processes.php';

return ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => $processes]]];
