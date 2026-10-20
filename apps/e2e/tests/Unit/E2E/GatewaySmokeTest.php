<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('checks the fake Gateway', function (string $case): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/gateway-smoke-tests.py', 'GatewaySmokeTest.'.$case],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'SSL_CERT_FILE' => false],
    );
    $process->setTimeout(240);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    expect($process->getErrorOutput())->toContain('Ran 1 test', 'OK');
})->with(function (): array {
    $repository = dirname(__DIR__, 5);
    $discovery = new Process([
        'python3', '-c',
        'import json, runpy, sys, unittest; module = runpy.run_path(sys.argv.pop(1)); print(json.dumps(unittest.defaultTestLoader.getTestCaseNames(module["GatewaySmokeTest"])))',
        dirname(__DIR__, 2).'/Fixtures/gateway-smoke-tests.py',
    ], $repository, ['PYTHONDONTWRITEBYTECODE' => '1']);
    $discovery->mustRun();
    $cases = json_decode($discovery->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    return array_combine($cases, $cases);
});
