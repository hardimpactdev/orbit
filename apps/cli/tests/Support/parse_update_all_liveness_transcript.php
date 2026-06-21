<?php

declare(strict_types=1);

use Orbit\Core\Progress\VirtualTerminalScreen;

if ($argc < 2) {
    fwrite(STDERR, "usage: parse_update_all_liveness_transcript.php <typescript-path> [label] [message]\n");
    exit(1);
}

$transcriptPath = $argv[1];
$label = $argv[2] ?? 'gateway';
$message = $argv[3] ?? 'Replacing cli binary';
$transcript = is_readable($transcriptPath) ? (file_get_contents($transcriptPath) ?: '') : '';

if ($transcript === '') {
    exit(0);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

$screen = new VirtualTerminalScreen;
$screen->feed($transcript);
$row = $screen->rowsMatching($label, $message)[0] ?? null;

if ($row === null) {
    exit(0);
}

echo sprintf(
    "row=%d|%s|%s|%s\n",
    $row['row'],
    $label,
    $message,
    $row['spinner'],
);
