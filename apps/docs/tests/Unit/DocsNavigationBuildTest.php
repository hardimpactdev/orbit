<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('builds navigation from independent family files and retains API groups on rebuild', function (): void {
    $root = sys_get_temp_dir().'/orbit-navigation-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs/navigation', 0777, true);
    try {
        file_put_contents($root.'/docs/site.json', json_encode([
            'navigation' => ['tabs' => [
                ['tab' => 'Documentation', 'groups' => [['group' => 'Fleet', 'pages' => []]]],
                ['tab' => 'API', 'groups' => []],
            ]],
            'redirects' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/docs/navigation/node.json', json_encode([
            'navigation' => [['tab' => 'Documentation', 'group' => 'Fleet', 'order' => 1, 'pages' => ['reference/nodes']]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/docs/navigation/firewall.json', json_encode([
            'navigation' => [['tab' => 'Documentation', 'group' => 'Fleet', 'order' => 2, 'pages' => ['reference/firewall']]],
            'redirects' => [['source' => '/old-firewall', 'destination' => '/reference/firewall']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/docs/openapi.json', json_encode([
            'tags' => [['name' => 'Firewall']],
            'paths' => ['/api/v1/firewall-rules' => ['get' => ['tags' => ['Firewall']]]],
        ], JSON_THROW_ON_ERROR));
        $build = new Process(['python3', dirname(__DIR__, 4).'/bin/docs-navigation', '--root', $root]);
        $build->mustRun();
        $first = file_get_contents($root.'/docs/docs.json');
        $build->mustRun();
        expect(file_get_contents($root.'/docs/docs.json'))->toBe($first);
        $config = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
        expect($config['navigation']['tabs'][0]['groups'][0]['pages'])->toBe(['reference/nodes', 'reference/firewall']);
        expect($config['navigation']['tabs'][1]['groups'][0]['pages'])->toBe(['GET /api/v1/firewall-rules']);
        expect($config['redirects'])->toBe([['source' => '/old-firewall', 'destination' => '/reference/firewall']]);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});
