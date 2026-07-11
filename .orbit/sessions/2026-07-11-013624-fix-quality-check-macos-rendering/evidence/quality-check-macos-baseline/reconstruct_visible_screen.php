<?php

declare(strict_types=1);

$path = $argv[1] ?? __DIR__.'/transcript.txt';
$elapsedLimit = isset($argv[2]) ? (float) $argv[2] : null;
$contents = file_get_contents($path);

if ($contents === false) {
    fwrite(STDERR, "Unable to read {$path}.\n");
    exit(1);
}

if (str_ends_with($path, '.jsonl')) {
    $chunks = '';

    foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
        $chunk = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        if ($elapsedLimit !== null && (float) $chunk['elapsed'] > $elapsedLimit) {
            break;
        }

        $chunks .= (string) $chunk['text'];
    }

    $contents = $chunks;
}

$tokens = preg_split(
    '/(\x1b\[[0-?]*[ -\\/]*[@-~]|\r|\n)/',
    $contents,
    flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
);

$row = 0;
$column = 0;
$screen = [];

foreach ($tokens ?: [] as $token) {
    if ($token === "\r") {
        $column = 0;
        continue;
    }

    if ($token === "\n") {
        $row++;
        continue;
    }

    if (str_starts_with($token, "\x1b[")) {
        if (preg_match('/^\x1b\[(?<params>[0-9;?]*)(?<command>[A-Za-z])$/', $token, $matches) !== 1) {
            continue;
        }

        $parameter = ltrim($matches['params'], '?');

        if ($matches['command'] === 'A') {
            $row = max(0, $row - max(1, (int) $parameter));
        } elseif ($matches['command'] === 'K' && ($parameter === '2' || $parameter === '')) {
            $screen[$row] = [];
        }

        continue;
    }

    foreach (preg_split('//u', $token, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        $screen[$row][$column] = $character;
        $column++;
    }
}

ksort($screen);

foreach ($screen as $lineNumber => $cells) {
    if ($cells === []) {
        continue;
    }

    ksort($cells);
    $lastColumn = (int) array_key_last($cells);
    $line = '';

    for ($index = 0; $index <= $lastColumn; $index++) {
        $line .= $cells[$index] ?? ' ';
    }

    echo str_pad((string) ($lineNumber + 1), 4, ' ', STR_PAD_LEFT).': '.rtrim($line).PHP_EOL;
}
