<?php

it('has database creation before migration in source code', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/InstallWebApp.php');

    // Find line numbers
    $lines = explode("\n", $sourceCode);
    $dbCreateLine = null;
    $migrateLine = null;

    foreach ($lines as $index => $line) {
        if (strpos($line, 'File::put($dbPath') !== false) {
            $dbCreateLine = $index + 1;
        }
        if (strpos($line, 'php artisan migrate') !== false) {
            $migrateLine = $index + 1;
        }
    }

    expect($dbCreateLine)->not->toBeNull();
    expect($migrateLine)->not->toBeNull();
    expect($dbCreateLine)->toBeLessThan($migrateLine);
});

it('includes error output in migration failure message', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/InstallWebApp.php');

    expect($sourceCode)->toContain('Failed to run web app migrations: \'.$migrateResult->errorOutput()');
});

it('sets APP_DEBUG=true in environment template', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/InstallWebApp.php');

    expect($sourceCode)->toContain('APP_DEBUG=true');
});

it('includes error output in orbit:init failure message', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/InstallWebApp.php');

    expect($sourceCode)->toContain('Failed to seed web app - it may need manual setup: \'.$seedResult->errorOutput()');
});

it('has orbit:init command in source code', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/InstallWebApp.php');

    expect($sourceCode)->toContain('php artisan orbit:init');
});
