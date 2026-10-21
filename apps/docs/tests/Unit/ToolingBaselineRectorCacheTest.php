<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ToolingBaselineFixture;

pest()->group('subprocess');

$root = sys_get_temp_dir().'/rector-cache-regression-'.bin2hex(random_bytes(8));
$project = dirname(__DIR__, 2);
$parent = "<?php\nnamespace RectorCacheRegression;\nclass ParentClass {\n%s\n}\n";
$child = "<?php\nnamespace RectorCacheRegression;\nfinal class ChildClass extends ParentClass\n{\n    public string \$name = '';\n}\n";
/** @var array{scripts: array{rector: string}} $composer */
$composer = json_decode((string) file_get_contents($project.'/../gateway/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$command = explode(' ', $composer['scripts']['rector']);
$command[0] = $project.'/'.$command[0];
$command[2] = $project.'/'.$command[2];
$command = [...$command, '--config', $root.'/rector.php'];
// A plain run checks every configured path, also when an outer gate runs this suite.
$environment = ['PAO_DISABLE' => '1', 'ORBIT_CHECK_SCOPE' => false];

beforeAll(function () use ($root, $project, $parent, $child, $command, $environment): void {
    mkdir($root.'/src', 0777, true);
    mkdir($root.'/container');
    file_put_contents($root.'/src/ParentClass.php', sprintf($parent, ''));
    file_put_contents($root.'/src/ChildClass.php', $child);
    // Container compilation is fixture setup. Keep it private and separate from
    // the file-result cache that the production command must clear on each run.
    file_put_contents($root.'/rector.php', <<<'PHP'
<?php
use Rector\Config\RectorConfig;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
return RectorConfig::configure()
    ->withCache(__DIR__.'/cache', null, __DIR__.'/container')
    ->withoutParallel()
    ->withPaths([__DIR__.'/src'])
    ->withRules([AddOverrideAttributeToOverriddenPropertiesRector::class]);
PHP);
    expect(is_dir($root.'/cache'))->toBeFalse();
    // Avoid unrelated Docs application source discovery in both invocations.
    $warm = new Process($command, $project.'/vendor/rector', $environment);
    $warm->run();
    expect($warm->getExitCode())->toBe(0, $warm->getOutput().$warm->getErrorOutput())
        ->and(glob($root.'/cache/*/*/*.php'))->not->toBeEmpty();
});

afterAll(function () use ($root): void {
    ToolingBaselineFixture::remove($root);
});

it('detects an unchanged child after only its parent gains a property with a warm Rector cache', function () use ($root, $project, $parent, $child, $command, $environment): void {
    file_put_contents($root.'/src/ParentClass.php', sprintf($parent, "    public string \$name = '';"));
    $check = new Process($command, $project.'/vendor/rector', $environment);
    $check->run();

    expect($check->getExitCode())->toBe(2, $check->getOutput().$check->getErrorOutput())
        ->and($check->getOutput())->toContain('ChildClass.php', 'AddOverrideAttributeToOverriddenPropertiesRector', '+    #[\\Override]')
        ->and(file_get_contents($root.'/src/ChildClass.php'))->toBe($child);
});
