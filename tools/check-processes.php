<?php

declare(strict_types=1);

/*
 * The number of processes PHPStan and Rector may start: ORBIT_CHECK_PROCESSES, or the core count up to 4.
 * bin/check-processes reads the same setting, with the same default, for Pest, Pint, bin/review-check, and bin/test.
 */

$value = getenv('ORBIT_CHECK_PROCESSES');

if ($value !== false && $value !== '') {
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new RuntimeException('ORBIT_CHECK_PROCESSES must be a positive integer, not '.var_export($value, true).'.');
    }

    return (int) $value;
}

// Each PHPStan worker may use up to 1 GB, and a task VM can have 2 GB. Raise the setting on a larger machine.
$cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
if ($cores < 1) {
    $cores = (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
}

return max(1, min($cores, 4));
