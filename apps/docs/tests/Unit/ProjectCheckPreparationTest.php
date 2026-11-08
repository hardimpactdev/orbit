<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('prepares ignored API outputs before the read-only Project gate', function (bool $failGenerator): void {
    $root = sys_get_temp_dir().'/orbit-check-preparation-'.bin2hex(random_bytes(8));
    mkdir($root.'/bin', 0777, true);
    mkdir($root.'/apps/docs', 0777, true);

    try {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 4).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'orbit/check-preparation-fixture',
            'scripts' => $manifest['scripts'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/openapi', 'old');
        file_put_contents($root.'/mcp', 'stale');
        $programs = [
            'docs-openapi' => "file_put_contents('openapi', 'fresh');",
            'mcp-tools' => $failGenerator ? 'exit(7);' : "file_put_contents('mcp', file_get_contents('openapi'));",
            'docs-navigation' => '',
            'docs-flows' => '',
            'review-check' => <<<'PHP'
file_put_contents('gate-ran', 'yes');
exit(file_get_contents('openapi') === 'fresh' && file_get_contents('mcp') === 'fresh' ? 0 : 1);
PHP,
        ];
        foreach ($programs as $name => $source) {
            file_put_contents($root.'/bin/'.$name, "#!/usr/bin/env php\n<?php\n".$source);
            chmod($root.'/bin/'.$name, 0755);
        }
        file_put_contents($root.'/apps/docs/artisan', '<?php');

        $check = new Process(['composer', '--no-interaction', 'run-script', 'check'], $root);
        $check->run();

        if ($failGenerator) {
            expect($check->getExitCode())->not->toBe(0)
                ->and(file_exists($root.'/gate-ran'))->toBeFalse()
                ->and(file_get_contents($root.'/mcp'))->toBe('stale');
        } else {
            expect($check->getExitCode())->toBe(0, $check->getErrorOutput())
                ->and(file_get_contents($root.'/gate-ran'))->toBe('yes')
                ->and(file_get_contents($root.'/mcp'))->toBe('fresh');
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['stale output' => [false], 'failed generation' => [true]]);
