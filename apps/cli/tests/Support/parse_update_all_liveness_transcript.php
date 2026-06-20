<?php

declare(strict_types=1);

use Orbit\Core\Progress\VirtualTerminalScreen;

if ($argc < 2) {
    fwrite(STDERR, "usage: parse_update_all_liveness_transcript.php <typescript-path>\n");
    exit(1);
}

$transcriptPath = $argv[1];
$transcript = is_readable($transcriptPath) ? (file_get_contents($transcriptPath) ?: '') : '';

if ($transcript === '') {
    exit(0);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * @return list<string>
 */
function repaintChunks(string $transcript): array
{
    if ($transcript === '') {
        return [];
    }

    $chunks = preg_split('/(?<=\r)/', $transcript, -1, PREG_SPLIT_NO_EMPTY);

    if (! is_array($chunks) || $chunks === []) {
        return [$transcript];
    }

    return array_values($chunks);
}

$screen = new VirtualTerminalScreen;
$lastObservation = null;

foreach (repaintChunks($transcript) as $chunk) {
    foreach ($screen->feedAndCollectMatchingSpinnerStates(
        $chunk,
        'gateway',
        'Replacing cli binary',
        $lastObservation,
    ) as $observation) {
        echo sprintf(
            "row=%d|%s|%s|%s\n",
            $observation['row'],
            $observation['label'],
            $observation['message'],
            $observation['spinner'],
        );
    }
}
