<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('checks complete Conn clone stage names in the chart', function (string $suffix, int $exitCode, string $message): void {
    $process = new Process(['node', '-', dirname(__DIR__, 4), $suffix]);
    $process->setInput(<<<'JS'
const fs = require('node:fs')
const read = fs.readFileSync
fs.readFileSync = function (path, ...args) {
  const text = read.call(this, path, ...args)
  return String(path).endsWith('/instance/container/create.mmd')
    ? text.replace('native clone-register', 'native clone-register' + process.argv[3])
    : text
}
require(process.argv[2] + '/bin/check-flows')
JS);
    $process->run();

    expect($process->getExitCode())->toBe($exitCode, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain($message);
})->with([
    'end of line' => ['', 0, 'all flows ok'],
    'whitespace' => [' ', 0, 'all flows ok'],
    'detail separator' => [' · vault', 0, 'all flows ok'],
    'name suffix' => ['_v2', 1, 'Conn clone stage contract:'],
]);
