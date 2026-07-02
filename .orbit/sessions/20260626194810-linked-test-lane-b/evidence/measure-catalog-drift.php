<?php

declare(strict_types=1);

$repoRoot = realpath(__DIR__.'/../../');

if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$catalogPath = $argv[1] ?? "{$repoRoot}/apps/docs/content/generated/command-catalog.json";

if (! is_file($catalogPath)) {
    fwrite(STDERR, "Catalog not found: {$catalogPath}\n");
    exit(1);
}

/** @var array<string, mixed> $catalog */
$catalog = json_decode(file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);

$laneFFamilies = [
    'schedule',
    'firewall',
    'tool',
    'vpn-client',
    'cf-ssl',
    'vpn-web-ui',
];

$missingByFamily = [];
$missingByCommand = [];
$totalMissing = 0;
$commandsWithMissing = 0;
$laneFMissing = 0;
$laneFCommandsWithMissing = 0;
$laneFMissingByFamily = [];

foreach ($catalog['commands'] as $commandName => $command) {
    $family = $command['family'];
    $commandMissing = [];

    foreach ($command['linked_test_files'] as $path) {
        if (is_file("{$repoRoot}/{$path}")) {
            continue;
        }

        $commandMissing[] = $path;
        $missingByFamily[$family] = ($missingByFamily[$family] ?? 0) + 1;
        $totalMissing++;
    }

    if ($commandMissing === []) {
        continue;
    }

    $commandsWithMissing++;
    $missingByCommand[$commandName] = $commandMissing;

    if (! in_array($family, $laneFFamilies, strict: true)) {
        continue;
    }

    $laneFCommandsWithMissing++;
    $laneFMissing += count($commandMissing);
    $laneFMissingByFamily[$family] = ($laneFMissingByFamily[$family] ?? 0) + count($commandMissing);
}

ksort($missingByFamily);
ksort($missingByCommand);
ksort($laneFMissingByFamily);

$result = [
    'catalog_path' => $catalogPath,
    'total_missing_linked_test_files' => $totalMissing,
    'commands_with_missing_linked_test_files' => $commandsWithMissing,
    'missing_by_family' => $missingByFamily,
    'missing_by_command' => $missingByCommand,
    'lane_f' => [
        'families' => $laneFFamilies,
        'total_missing_linked_test_files' => $laneFMissing,
        'commands_with_missing_linked_test_files' => $laneFCommandsWithMissing,
        'missing_by_family' => $laneFMissingByFamily,
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";