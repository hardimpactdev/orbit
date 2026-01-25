<?php

declare(strict_types=1);

it('checks for environments table in database', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('environments');
    expect($sourceCode)->toContain('DB::table(\'environments\')->count()');
});

it('checks for projects table in database', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('projects');
    expect($sourceCode)->toContain('DB::table(\'projects\')->count()');
});

it('checks for local environment record', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('Environment::getLocal()');
    expect($sourceCode)->toContain('Local environment record not found');
});

it('checks web app accessibility', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('orbit.{$tld}');
    expect($sourceCode)->toContain('Web app accessible');
});

it('checks PHP-FPM services are running', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('getInstalledVersions()');
    expect($sourceCode)->toContain('isRunning($version)');
    expect($sourceCode)->toContain('PHP-FPM');
});

it('checks Horizon queue worker', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('horizon');
    expect($sourceCode)->toContain('Horizon');
    expect($sourceCode)->toContain('pgrep -f "artisan horizon"');
});

it('checks Reverb service', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('reverb');
    expect($sourceCode)->toContain('"orbit-{$service}"');
});

it('returns success when all checks pass', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('All health checks passed');
    expect($sourceCode)->toContain('StepResult::success()');
});

it('returns failure with specific error messages', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('Database tables not found');
    expect($sourceCode)->toContain('Local environment record not found');
    expect($sourceCode)->toContain('Web app not accessible');
    expect($sourceCode)->toContain('PHP-FPM services not running');
    expect($sourceCode)->toContain('Required Docker services not running');
});

it('uses curl with proper timeout and insecure flag for web check', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('curl -s -o /dev/null -w \'%{http_code}\'');
    expect($sourceCode)->toContain('--max-time 10');
    expect($sourceCode)->toContain('--insecure');
});
