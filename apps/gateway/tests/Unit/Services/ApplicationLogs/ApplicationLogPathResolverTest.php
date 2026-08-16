<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\ApplicationLogs\ApplicationLogPathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ApplicationLogPathResolver', function (): void {
    it('resolves authorized root and absolute log path under live for document_root live', function (): void {
        $serving = Node::factory()->create([
            'name' => 'app-dev-1',
            'managed' => true,
        ]);
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $serving->id,
                node: $serving->name,
                path: '/home/orbit/apps/docs',
                document_root: 'live',
                domain: 'docs.test',
            ),
        ]);

        $resolver = app(ApplicationLogPathResolver::class);
        $config = $instance->driver_config;
        assert($config instanceof OrbitInstanceDriverConfigData);
        $root = $resolver->hostApplicationRoot((string) $config->path, (string) $config->document_root);
        $paths = $resolver->forInstance($app, $instance);

        expect($root)
            ->toBe('/home/orbit/apps/docs/live')
            ->and($paths['authorized_root'])
            ->toBe('/home/orbit/apps/docs/live')
            ->and($paths['absolute_path'])
            ->toBe('/home/orbit/apps/docs/live/storage/logs/laravel.log')
            ->and($paths['logical_path'])
            ->toBe('storage/logs/laravel.log');
    });

    it('resolves authorized root and absolute log path under live for document_root live/public', function (): void {
        $serving = Node::factory()->create([
            'name' => 'app-dev-1',
            'managed' => true,
        ]);
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $serving->id,
                node: $serving->name,
                path: '/home/orbit/apps/docs',
                document_root: 'live/public',
                domain: 'docs.example.com',
            ),
        ]);

        $resolver = app(ApplicationLogPathResolver::class);
        $config = $instance->driver_config;
        assert($config instanceof OrbitInstanceDriverConfigData);
        $root = $resolver->hostApplicationRoot((string) $config->path, (string) $config->document_root);
        $paths = $resolver->forInstance($app, $instance);

        expect($root)
            ->toBe('/home/orbit/apps/docs/live')
            ->and($paths['authorized_root'])
            ->toBe('/home/orbit/apps/docs/live')
            ->and($paths['absolute_path'])
            ->toBe('/home/orbit/apps/docs/live/storage/logs/laravel.log')
            ->and($paths['logical_path'])
            ->toBe('storage/logs/laravel.log');
    });
});
