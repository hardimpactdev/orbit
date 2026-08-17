<?php

declare(strict_types=1);

use App\Console\Commands\E2ETestCommand;
use App\E2E\Support\E2EPestSupportTree;

it('keeps the runner Pest support tree byte-identical to the canonical tree', function (): void {
    $canonicalDirectory = E2EPestSupportTree::canonicalDirectory();
    $runnerDirectory = E2EPestSupportTree::generatedRunnerDirectory();
    $canonicalFiles = e2ePestSupportPhpBasenames($canonicalDirectory);
    $runnerFiles = e2ePestSupportPhpBasenames($runnerDirectory);
    $commandSource = file_get_contents(repo_path('apps/e2e/app/Console/Commands/E2ETestCommand.php'));

    expect($canonicalDirectory)->toBe(repo_path('apps/e2e/tests/E2E/Support'));
    expect($runnerDirectory)->toBe(repo_path('apps/e2e/tests/Feature/Commands/Support'));
    expect($canonicalDirectory)->not->toBe($runnerDirectory);
    expect($commandSource)->toContain('E2EPestSupportTree::copyTo');
    expect($commandSource)->not->toContain('tests/Feature/Commands/Support');
    expect($runnerFiles)->toBe($canonicalFiles);
    expect(file_get_contents($runnerDirectory.'/Pest.php'))
        ->toBe(file_get_contents($canonicalDirectory.'/Pest.php'));
    expect(file_get_contents($runnerDirectory.'/SqliteDatabaseFixture.php'))
        ->toBe(file_get_contents($canonicalDirectory.'/SqliteDatabaseFixture.php'));
});

it('stages runner support files from the canonical Pest support tree', function (): void {
    $command = app(E2ETestCommand::class);
    $testPath = 'tests/Feature/Commands/.docker-feature-tests/run_support_contract_'.bin2hex(random_bytes(4));
    $plans = [
        'docker' => [
            'lane' => 'docker',
            'command' => ['php', 'artisan', 'test'],
            'environment' => [
                'ORBIT_E2E' => '1',
            ],
            'test_path' => $testPath,
            'test_files' => [
                'tests/Feature/Commands/NodeListAgentTopologyTest.php',
            ],
        ],
    ];

    try {
        $method = new ReflectionMethod(E2ETestCommand::class, 'preparePlanArtifacts');
        $method->invokeArgs($command, [&$plans]);

        $canonicalPest = file_get_contents(E2EPestSupportTree::canonicalDirectory().'/Pest.php');
        $stagedPestPath = base_path($testPath.'/Support/Pest.php');
        $stagedPest = file_get_contents($stagedPestPath);
        $canonicalFixture = file_get_contents(E2EPestSupportTree::canonicalDirectory().'/SqliteDatabaseFixture.php');
        $stagedFixture = file_get_contents(base_path($testPath.'/Support/SqliteDatabaseFixture.php'));

        expect($stagedPest)
            ->toBe($canonicalPest)
            ->and($stagedFixture)
            ->toBe($canonicalFixture)
            ->and($stagedPest)
            ->toContain('function e2eJsonCommandError(array $payload): array');

        $autoload = repo_path('apps/e2e/vendor/autoload.php');
        $script = sprintf(
            'require %s; require %s; echo function_exists("e2eJsonCommandError") ? "yes" : "no";',
            var_export($autoload, true),
            var_export($stagedPestPath, true),
        );
        $resolution = run_e2e_script(['php', '-r', $script]);

        expect($resolution['exit_code'])
            ->toBe(0)
            ->and($resolution['stdout'])
            ->toBe('yes');
    } finally {
        $cleanup = new ReflectionMethod(E2ETestCommand::class, 'cleanupPlanArtifacts');
        $cleanup->invoke($command, $plans);
    }
});

/**
 * @return list<string>
 */
function e2ePestSupportPhpBasenames(string $directory): array
{
    $files = array_map(basename(...), glob($directory.'/*.php') ?: []);
    sort($files);

    return array_values($files);
}
