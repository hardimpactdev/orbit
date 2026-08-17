<?php

declare(strict_types=1);

use App\Console\Commands\E2ESupportTreeSyncCommand;
use App\E2E\Support\E2EPestSupportTree;

it('is hidden', function (): void {
    $command = app(E2ESupportTreeSyncCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('regenerates the runner Pest support tree from the canonical tree', function (): void {
    $canonicalDirectory = E2EPestSupportTree::canonicalDirectory();
    $runnerDirectory = E2EPestSupportTree::generatedRunnerDirectory();
    $runnerPest = $runnerDirectory.'/Pest.php';
    $runnerFixture = $runnerDirectory.'/SqliteDatabaseFixture.php';
    $originalPest = file_get_contents($runnerPest);
    $originalFixture = file_get_contents($runnerFixture);

    expect($originalPest)->not->toBeFalse();
    expect($originalFixture)->not->toBeFalse();

    try {
        file_put_contents($runnerPest, "<?php\n// drifted runner Pest.php\n");
        file_put_contents($runnerFixture, "<?php\n// drifted runner SqliteDatabaseFixture.php\n");

        $this
            ->artisan('e2e:support-tree:sync')
            ->expectsOutputToContain($runnerPest)
            ->expectsOutputToContain($runnerFixture)
            ->assertSuccessful();

        expect(file_get_contents($runnerPest))
            ->toBe(file_get_contents($canonicalDirectory.'/Pest.php'));
        expect(file_get_contents($runnerFixture))
            ->toBe(file_get_contents($canonicalDirectory.'/SqliteDatabaseFixture.php'));
    } finally {
        file_put_contents($runnerPest, $originalPest);
        file_put_contents($runnerFixture, $originalFixture);
    }
});
