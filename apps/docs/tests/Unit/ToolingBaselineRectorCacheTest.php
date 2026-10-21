<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\MediumTests;
use Tests\Support\ToolingBaselineFixture;

pest()->group('subprocess');

it('detects an unchanged child after only its parent gains a property with a warm Rector cache', function (): void {
    expect($this->size()->isMedium())->toBeTrue();

    $root = ToolingBaselineFixture::create();
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
        ToolingBaselineFixture::remove($root);
    }
});

MediumTests::in(__FILE__);
