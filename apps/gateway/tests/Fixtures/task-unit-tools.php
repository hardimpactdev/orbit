#!/usr/bin/env php
<?php

declare(strict_types=1);

$home = getenv('HOME');
if (basename($argv[0]) === 'sudo') {
    if (array_slice($argv, 2, 2) === ['systemctl', 'show']) {
        echo "Version=259\n";
        exit(0);
    }
    if (($argv[2] ?? '') === 'systemd-run') {
        file_get_contents($home.'/finish');
        exit(0);
    }
    file_put_contents($home.'/stops', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
    if (file_exists($home.'/finish')) {
        file_put_contents($home.'/finish', "done\n");
    }
    exit(0);
}
if (($argv[1] ?? '') === 'is-active') {
    exit(3);
}
if (($argv[1] ?? '') === 'list-units') {
    echo json_encode([['unit' => 'orbit-workspace-operation-'.str_repeat('a', 32).'.service']], JSON_THROW_ON_ERROR);
    exit(0);
}
$state = json_decode(file_get_contents($home.'/unit.json'), true, flags: JSON_THROW_ON_ERROR);
if (is_file($home.'/queued-once')) {
    unlink($home.'/queued-once');
    $state = [...$state, 'ActiveState' => 'inactive', 'SubState' => 'dead', 'Job' => '45809123', 'ExecMainCode' => '0'];
}
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--property=')) {
        foreach (explode(',', substr($argument, strlen('--property='))) as $key) {
            if (isset($state[$key])) {
                echo $key.'='.$state[$key]."\n";
            }
        }
    }
}
