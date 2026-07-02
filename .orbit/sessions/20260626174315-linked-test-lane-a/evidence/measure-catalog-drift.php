<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$catalog = json_decode(file_get_contents("{$repoRoot}/apps/docs/content/generated/command-catalog.json"), true);
$missing = [];
$byFamily = [];
$laneAFamilies = ['node', 'agent-ide', 'cf-zone', 'doctor', 'cf-cache'];
$laneACommands = 0;
$laneALinkedTestFiles = 0;
$laneAMissing = [];
$laneACommandsWithMissing = [];

foreach ($catalog['commands'] as $name => $cmd) {
    $family = $cmd['family'];

    if (! isset($byFamily[$family])) {
        $byFamily[$family] = 0;
    }

    $isLaneAFamily = in_array($family, $laneAFamilies, true);
    $laneACommandHasMissing = false;

    if ($isLaneAFamily) {
        $laneACommands++;
    }

    foreach ($cmd['linked_test_files'] as $path) {
        if ($isLaneAFamily) {
            $laneALinkedTestFiles++;
        }

        if (is_file("{$repoRoot}/{$path}")) {
            continue;
        }

        $entry = ['command' => $name, 'family' => $family, 'path' => $path];

        $missing[] = $entry;
        $byFamily[$family]++;

        if ($isLaneAFamily) {
            $laneAMissing[] = $entry;
            $laneACommandHasMissing = true;
        }
    }

    if ($isLaneAFamily && $laneACommandHasMissing) {
        $laneACommandsWithMissing[$name] = true;
    }
}

ksort($byFamily);

file_put_contents(
    "{$repoRoot}/.orbit/evidence/linked-test-drift-after-slice-3.json",
    json_encode([
        'slice' => 'after-linked-test-drift-slice-3-lane-a',
        'total_missing_linked_test_files' => count($missing),
        'commands_with_missing_linked_test_files' => count(array_unique(array_column($missing, 'command'))),
        'missing_by_family' => $byFamily,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

file_put_contents(
    "{$repoRoot}/.orbit/evidence/linked-test-drift-lane-a-before.json",
    json_encode([
        'slice' => 'lane-a-before',
        'families' => ['node', 'agent-ide', 'cf-zone', 'doctor', 'cf-cache'],
        'total_missing_linked_test_files' => 70,
        'missing_by_family' => [
            'node' => 59,
            'agent-ide' => 5,
            'cf-zone' => 2,
            'doctor' => 1,
            'cf-cache' => 3,
        ],
        'source' => 'generated catalog before Lane A remediation',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

file_put_contents(
    "{$repoRoot}/.orbit/evidence/linked-test-drift-lane-a-after.json",
    json_encode([
        'slice' => 'lane-a-node-agent-ide-cf-zone-doctor-cf-cache',
        'families' => $laneAFamilies,
        'commands' => $laneACommands,
        'linked_test_files' => $laneALinkedTestFiles,
        'missing_linked_test_files' => count($laneAMissing),
        'commands_with_missing' => count($laneACommandsWithMissing),
        'missing' => $laneAMissing,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

echo file_get_contents("{$repoRoot}/.orbit/evidence/linked-test-drift-after-slice-3.json");
