<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ToolingBaselineFixture;

pest()->group('subprocess');

it('detects an unchanged child after only its parent gains a property with a warm Rector cache', function (): void {
    $root = ToolingBaselineFixture::create();
    try {
        mkdir($root.'/src');
        $project = dirname(__DIR__, 2);
        $parent = "<?php\nnamespace RectorCacheRegression;\nclass ParentClass {\n%s\n}\n";
        file_put_contents($root.'/src/ParentClass.php', sprintf($parent, ''));
        $child = "<?php\nnamespace RectorCacheRegression;\nfinal class ChildClass extends ParentClass\n{\n    public string \$name = '';\n}\n";
        file_put_contents($root.'/src/ChildClass.php', $child);
        // Share only the ordinary, task-local DI container cache, not file results.
        file_put_contents($root.'/rector.php', sprintf(<<<'PHP'
<?php
use Rector\Config\RectorConfig;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
return RectorConfig::configure()
    ->withCache(__DIR__.'/cache', null, %s)
    ->withoutParallel()
    ->withPaths([__DIR__.'/src'])
    ->withRules([AddOverrideAttributeToOverriddenPropertiesRector::class]);
PHP, var_export($project.'/vendor/rector', true)));
        /** @var array{scripts: array{rector: string}} $composer */
        $composer = json_decode((string) file_get_contents($root.'/apps/gateway/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $command = explode(' ', $composer['scripts']['rector']);
        // Run from the installed tool, not the Docs Composer source tree. Keep
        // this working directory stable so PHPStan can reuse its DI container;
        // the two source files and their result cache still start fresh.
        $command[0] = $project.'/'.$command[0];
        $command[2] = $project.'/'.$command[2];
        $command = [...$command, '--config', $root.'/rector.php'];
        // A plain run checks every configured path, also when an outer gate runs this suite.
        $environment = ['PAO_DISABLE' => '1', 'ORBIT_CHECK_SCOPE' => false];
        expect(is_dir($root.'/cache'))->toBeFalse();
        $warm = new Process($command, $project.'/vendor/rector', $environment);
        $warm->run();
        expect($warm->getExitCode())->toBe(0, $warm->getOutput().$warm->getErrorOutput())
            ->and(glob($root.'/cache/*/*/*.php'))->not->toBeEmpty();

        file_put_contents($root.'/src/ParentClass.php', sprintf($parent, "    public string \$name = '';"));
        $check = new Process($command, $project.'/vendor/rector', $environment);
        $check->run();

        expect($check->getExitCode())->toBe(2, $check->getOutput().$check->getErrorOutput())
            ->and($check->getOutput())->toContain('ChildClass.php', 'AddOverrideAttributeToOverriddenPropertiesRector', '+    #[\\Override]')
            ->and(file_get_contents($root.'/src/ChildClass.php'))->toBe($child);
    } finally {
        ToolingBaselineFixture::remove($root);
    }
});
