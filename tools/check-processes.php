<?php

declare(strict_types=1);

$output = [];
exec(escapeshellarg(__DIR__.'/../bin/check-processes').' 2>&1', $output, $status);
$value = trim(implode("\n", $output));

if ($status !== 0 || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
    throw new RuntimeException('Cannot determine check processes: '.$value);
}

return (int) $value;
