<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array<string, array{string}> */
function bootstrapAndGateCases(): array
{
    $cases = [];
    $class = null;
    foreach (file(dirname(__DIR__, 2).'/Fixtures/bootstrap-and-gate-tests.py', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^class (\w+)\(RepositoryFixture\):/', $line, $match) === 1) {
            $class = $match[1];
        } elseif ($class !== null && preg_match('/^    def (test_\w+)\(self\):/', $line, $match) === 1) {
            $cases[$class.'.'.$match[1]] = [$class.'.'.$match[1]];
        }
    }

    return $cases;
}

// Each Python case runs alone, so each one is a single test with its own time limit.
it('runs bin/bootstrap and the candidate gate against real repositories', function (string $case): void {
    $repository = dirname(__DIR__, 5);
    $environment = ['PYTHONDONTWRITEBYTECODE' => '1'];
    // The fixture starts its own commands, outside this suite's worker state.
    foreach (array_keys($_SERVER + $_ENV) as $name) {
        if (is_string($name) && (str_starts_with($name, 'PEST_') || in_array($name, ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'], true))) {
            $environment[$name] = false;
        }
    }

    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/bootstrap-and-gate-tests.py', $repository, $case],
        $repository,
        $environment,
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
})->with(bootstrapAndGateCases());

it('finds every case in the fixture', function (): void {
    expect(bootstrapAndGateCases())->toHaveCount(21);
});
