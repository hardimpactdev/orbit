<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** Copy the tooling files of every project from this repository into a fixture root. */
function toolingBaselineFixture(): string
{
    $repository = dirname(__DIR__, 4);
    $root = sys_get_temp_dir().'/tooling-baseline-'.bin2hex(random_bytes(6));
    foreach (['apps/cli', 'apps/gateway', 'apps/docs', 'apps/e2e', 'packages/php-sdk'] as $project) {
        mkdir($root.'/'.$project, 0777, true);
        foreach (['composer.json', 'pint.json', 'rector.php', 'phpstan.neon', 'phpunit.xml', 'phpunit.xml.dist'] as $file) {
            if (is_file($repository.'/'.$project.'/'.$file)) {
                copy($repository.'/'.$project.'/'.$file, $root.'/'.$project.'/'.$file);
            }
        }
    }

    return $root;
}

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

function toolingBaselineRemove(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

it('fails when a project drifts from the baseline', function (string $relative, string $search, string $replace, string $finding): void {
    $root = toolingBaselineFixture();
    try {
        toolingBaselineReplace($root, $relative, $search, $replace);

        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain($finding);
    } finally {
        toolingBaselineRemove($root);
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
    $root = toolingBaselineFixture();
    try {
        unlink($root.'/packages/php-sdk/rector.php');

        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('packages/php-sdk/rector.php is missing.');
    } finally {
        toolingBaselineRemove($root);
    }
});

it('accepts the declared differences', function (): void {
    $root = toolingBaselineFixture();
    try {
        $process = toolingBaselineCheck($root);

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        toolingBaselineRemove($root);
    }
});

it('detects an unchanged child after only its parent gains a property with a warm Rector cache', function (): void {
    $root = toolingBaselineFixture();
    try {
        mkdir($root.'/src');
        mkdir($root.'/container');
        $parent = "<?php\nnamespace RectorCacheRegression;\nclass ParentClass {\n%s\n}\n";
        file_put_contents($root.'/src/ParentClass.php', sprintf($parent, ''));
        $child = "<?php\nnamespace RectorCacheRegression;\nfinal class ChildClass extends ParentClass\n{\n    public string \$name = '';\n}\n";
        file_put_contents($root.'/src/ChildClass.php', $child);
        file_put_contents($root.'/rector.php', <<<'PHP'
<?php
use Rector\Config\RectorConfig;
return RectorConfig::configure()
    ->withCache(__DIR__.'/cache', null, __DIR__.'/container')
    ->withoutParallel()
    ->withPaths([__DIR__.'/src'])
    ->withPhpSets();
PHP);
        $composer = json_decode((string) file_get_contents($root.'/apps/gateway/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $command = [...explode(' ', $composer['scripts']['rector']), '--config', $root.'/rector.php'];
        $project = dirname(__DIR__, 2);
        // A plain run checks every configured path, also when an outer gate runs this suite.
        $environment = ['PAO_DISABLE' => '1', 'ORBIT_CHECK_SCOPE' => false];
        $warm = new Process($command, $project, $environment);
        $warm->run();
        expect($warm->getExitCode())->toBe(0, $warm->getOutput().$warm->getErrorOutput());

        file_put_contents($root.'/src/ParentClass.php', sprintf($parent, "    public string \$name = '';"));
        $check = new Process($command, $project, $environment);
        $check->run();

        expect($check->getExitCode())->toBe(2, $check->getOutput().$check->getErrorOutput())
            ->and($check->getOutput())->toContain('ChildClass.php', 'AddOverrideAttributeToOverriddenPropertiesRector', '+    #[\\Override]')
            ->and(file_get_contents($root.'/src/ChildClass.php'))->toBe($child);
    } finally {
        toolingBaselineRemove($root);
    }
});

it('accepts this repository', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/tooling-baseline']);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
