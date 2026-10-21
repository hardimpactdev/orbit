<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ToolingBaselineFixture;

function toolingBaselineReplace(string $root, string $relative, string $search, string $replace): void
{
    $path = $root.'/'.$relative;
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain($search);

    file_put_contents($path, str_replace($search, $replace, $contents));
}

function toolingBaselineCheck(string $root): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/tooling-baseline', '--root', $root]);
    $process->run();

    return $process;
}

it('fails when a project drifts from the baseline', function (string $relative, string $search, string $replace, string $finding): void {
    $root = ToolingBaselineFixture::create();
    try {
        toolingBaselineReplace($root, $relative, $search, $replace);

        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain($finding);
    } finally {
        ToolingBaselineFixture::remove($root);
    }
})->with([
    'renamed script' => ['apps/cli/composer.json', '"guidance:update"', '"guidance:generate"', 'apps/cli/composer.json is missing script guidance:update.'],
    'changed script' => ['apps/e2e/composer.json', '--processes=$(../../bin/check-processes) --compact', '--processes=4 --compact', 'apps/e2e/composer.json script test is'],
    'serial Pint check' => ['apps/cli/composer.json', 'pint --test --parallel', 'pint --test', 'apps/cli/composer.json script format:check is'],
    'inline guidance check' => ['packages/php-sdk/composer.json', '"../../bin/guidance-check"', '"vendor/bin/pest --configuration=phpunit.guidance.xml"', 'packages/php-sdk/composer.json script guidance:check is'],
    'unsafe Rector cache reuse' => ['apps/gateway/composer.json', ' --clear-cache', '', 'apps/gateway/composer.json script rector is'],
    'missing check step' => ['apps/docs/composer.json', '"@rector",', '', 'apps/docs/composer.json check is missing @rector.'],
    'Rector before PHPStan' => ['apps/cli/composer.json', "\"@analyse\",\n            \"@rector\",", "\"@rector\",\n            \"@analyse\",", 'apps/cli/composer.json check must run @analyse before @rector.'],
    'undeclared check step' => ['apps/e2e/composer.json', '"@analyse"', '"@analyse", "@extra"', 'apps/e2e/composer.json check runs undeclared step "@extra".'],
    'lower PHPStan level' => ['apps/gateway/phpstan.neon', 'level: 9', 'level: 8', 'apps/gateway/phpstan.neon level is 8, expected 9.'],
    'changed PHPStan paths' => ['apps/e2e/phpstan.neon', "        - app\n", "        - app\n        - config\n", 'apps/e2e/phpstan.neon checks'],
    'changed Rector paths' => ['apps/gateway/rector.php', "__DIR__.'/app',", "__DIR__.'/app',\n        __DIR__.'/config',", 'apps/gateway/rector.php checks'],
    'Pint skips a checked folder' => ['apps/cli/pint.json', 'config|design|tests', 'config|tests', 'apps/cli/pint.json does not format design, which Rector or PHPStan checks.'],
]);

it('fails when a tooling file is missing', function (): void {
    $root = ToolingBaselineFixture::create();
    try {
        unlink($root.'/packages/php-sdk/rector.php');

        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('packages/php-sdk/rector.php is missing.');
    } finally {
        ToolingBaselineFixture::remove($root);
    }
});

it('accepts the declared differences', function (): void {
    $root = ToolingBaselineFixture::create();
    try {
        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        ToolingBaselineFixture::remove($root);
    }
});

it('accepts this repository', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/tooling-baseline']);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
