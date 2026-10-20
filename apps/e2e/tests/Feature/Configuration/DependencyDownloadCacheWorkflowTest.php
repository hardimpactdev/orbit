<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('caches Composer downloads through stock actions with main as the only writer', function (): void {
    $action = Yaml::parseFile(base_path('../../.github/actions/php/action.yml'));
    $steps = array_column($action['runs']['steps'], null, 'name');
    $locate = $steps['Locate Composer download cache'];
    $restore = $steps['Restore Composer downloads'];
    $cache = $steps['Cache Composer downloads on main'];

    expect($locate['run'])->toContain('composer config cache-files-dir --global', 'composer --version');
    expect($restore['uses'])->toBe('actions/cache/restore@v6');
    expect($restore['if'])->toBe("github.ref != 'refs/heads/main' || github.event_name == 'pull_request'");
    expect($cache['uses'])->toBe('actions/cache@v6');
    expect($cache['if'])->toBe("github.ref == 'refs/heads/main' && github.event_name != 'pull_request'");
    expect($cache['with'])->toBe($restore['with'])->toBe([
        'path' => '${{ steps.composer-cache.outputs.path }}',
        'key' => 'composer-${{ runner.os }}-${{ runner.arch }}-${{ steps.composer-cache.outputs.version }}-${{ hashFiles(\'**/composer.lock\') }}',
    ]);
    expect(array_search('Verify the recipe PHP build', array_keys($steps), true))
        ->toBeLessThan(array_search('Locate Composer download cache', array_keys($steps), true));
});

it('caches only Bun downloads before installs and only writes them on main', function (string $name, string $guard): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $steps = $workflow['jobs'][$name]['steps'];
    $byName = array_column($steps, null, 'name');
    $setup = collect($steps)->firstWhere('uses', 'oven-sh/setup-bun@v2');
    $restore = $byName['Restore Bun downloads'];
    $cache = $byName['Cache Bun downloads on main'];

    expect($setup['id'])->toBe('bun');
    expect($restore['uses'])->toBe('actions/cache/restore@v6');
    $restoreCondition = "github.ref != 'refs/heads/main' || github.event_name == 'pull_request'";
    expect($restore['if'])->toBe($guard.($guard === '' ? $restoreCondition : "({$restoreCondition})"));
    expect($cache['uses'])->toBe('actions/cache@v6');
    expect($cache['if'])->toBe($guard."github.ref == 'refs/heads/main' && github.event_name != 'pull_request'");
    expect($cache['with'])->toBe($restore['with'])->toBe([
        'path' => '~/.bun/install/cache',
        'key' => 'bun-${{ runner.os }}-${{ runner.arch }}-${{ steps.bun.outputs.bun-version }}-${{ hashFiles(\'**/bun.lock\') }}',
    ]);
    $names = array_keys($byName);
    $install = $name === 'project' ? 'Install generated merge proof dependencies' : 'Install dependencies';
    expect(array_search($setup['name'], $names, true))->toBeLessThan(array_search('Restore Bun downloads', $names, true));
    expect(array_search('Cache Bun downloads on main', $names, true))->toBeLessThan(array_search($install, $names, true));
})->with([
    'project' => ['project', "(matrix.project.directory == 'apps/gateway' || matrix.project.directory == 'apps/docs') && "],
    'web' => ['web', ''],
    'pi-server' => ['pi-server', ''],
    'desktop' => ['desktop', "steps.changes.outputs.desktop == 'true' && "],
]);

it('never shares installed dependency trees or caches Playwright browsers', function (): void {
    $files = [
        base_path('../../.github/workflows/ci.yml'),
        base_path('../../.github/workflows/orbit-cli-binary.yml'),
        base_path('../../.github/actions/php/action.yml'),
    ];

    foreach ($files as $file) {
        $config = Yaml::parseFile($file);
        $jobs = $config['jobs'] ?? ['composite' => $config['runs']];
        foreach ($jobs as $job) {
            foreach ($job['steps'] as $step) {
                if (! str_starts_with($step['uses'] ?? '', 'actions/cache')) {
                    continue;
                }
                $path = $step['with']['path'];
                expect($path)->not->toContain('node_modules', 'playwright', 'ms-playwright');
                expect($path)->not->toMatch('~(?:^|/)vendor/?$~');
            }
        }
    }
});
