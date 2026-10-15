<?php

declare(strict_types=1);

/*
 * The number of processes PHPStan and Rector may start: ORBIT_CHECK_PROCESSES, or null to let the tool use the
 * machine's core count. bin/check-processes reads the same setting for Pest, Pint, and bin/review-check.
 */

$value = getenv('ORBIT_CHECK_PROCESSES');

if ($value === false || $value === '') {
    return null;
}

if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
    throw new RuntimeException('ORBIT_CHECK_PROCESSES must be a positive integer, not '.var_export($value, true).'.');
}

return (int) $value;
