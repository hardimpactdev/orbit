<?php

use App\Data\ServiceTemplate;
use App\Services\ServiceManager;
use App\Services\ServiceTemplateLoader;

beforeEach(function () {
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->templateLoader = Mockery::mock(ServiceTemplateLoader::class);

    $this->app->instance(ServiceManager::class, $this->serviceManager);
    $this->app->instance(ServiceTemplateLoader::class, $this->templateLoader);
});

describe('service:list', function () {
    it('lists configured services', function () {
        $this->serviceManager->shouldReceive('getServices')
            ->once()
            ->andReturn([
                'redis' => ['enabled' => true, 'version' => '7.4'],
                'postgres' => ['enabled' => false, 'version' => '16'],
            ]);

        $this->serviceManager->shouldReceive('getEnabled')
            ->once()
            ->andReturn([
                'redis' => ['enabled' => true, 'version' => '7.4'],
            ]);

        $this->artisan('service:list')
            ->expectsOutputToContain('Configured Services')
            ->expectsOutputToContain('redis')
            ->expectsOutputToContain('postgres')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->serviceManager->shouldReceive('getServices')
            ->once()
            ->andReturn([
                'redis' => ['enabled' => true, 'version' => '7.4'],
            ]);

        $this->serviceManager->shouldReceive('getEnabled')
            ->once()
            ->andReturn([
                'redis' => ['enabled' => true, 'version' => '7.4'],
            ]);

        $this->artisan('service:list --json')
            ->assertExitCode(0);
    });

    it('lists available service templates with --available flag', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'In-memory data structure store',
            category: 'cache',
            versions: ['7.4', '7.2'],
            configSchema: [],
            dockerConfig: [],
            dependsOn: []
        );

        $this->templateLoader->shouldReceive('loadAll')
            ->once()
            ->andReturn(['redis' => $template]);

        $this->artisan('service:list --available')
            ->expectsOutputToContain('Available Service Templates')
            ->expectsOutputToContain('Redis')
            ->assertExitCode(0);
    });
});

describe('service:enable', function () {
    it('enables a service', function () {
        $this->serviceManager->shouldReceive('enable')
            ->with('redis')
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:enable redis')
            ->expectsOutputToContain('enabled')
            ->assertExitCode(0);
    });

    it('handles enable failures', function () {
        $this->serviceManager->shouldReceive('enable')
            ->with('redis')
            ->once()
            ->andReturn(false);

        $this->artisan('service:enable redis')
            ->expectsOutputToContain('Failed')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->serviceManager->shouldReceive('enable')
            ->with('redis')
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:enable redis --json')
            ->assertExitCode(0);
    });
});

describe('service:disable', function () {
    it('disables a service', function () {
        $this->serviceManager->shouldReceive('disable')
            ->with('redis')
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:disable redis')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    });

    it('handles disable failures', function () {
        $this->serviceManager->shouldReceive('disable')
            ->with('redis')
            ->once()
            ->andReturn(false);

        $this->artisan('service:disable redis')
            ->expectsOutputToContain('Failed')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->serviceManager->shouldReceive('disable')
            ->with('redis')
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:disable redis --json')
            ->assertExitCode(0);
    });
});

describe('service:configure', function () {
    it('configures a service with key=value pairs', function () {
        $this->serviceManager->shouldReceive('configure')
            ->with('redis', ['port' => 6379, 'maxmemory' => '256mb'])
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:configure redis --set=port=6379 --set=maxmemory=256mb')
            ->expectsOutputToContain('configured')
            ->assertExitCode(0);
    });

    it('parses boolean values correctly', function () {
        $this->serviceManager->shouldReceive('configure')
            ->with('redis', ['persistence' => true])
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:configure redis --set=persistence=true')
            ->assertExitCode(0);
    });

    it('requires --set option', function () {
        $this->artisan('service:configure redis')
            ->expectsOutputToContain('No configuration provided')
            ->assertExitCode(1);
    });

    it('handles configuration failures', function () {
        $this->serviceManager->shouldReceive('configure')
            ->with('redis', ['port' => 6379])
            ->once()
            ->andThrow(new RuntimeException('Invalid configuration'));

        $this->artisan('service:configure redis --set=port=6379')
            ->expectsOutputToContain('Invalid configuration')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->serviceManager->shouldReceive('configure')
            ->with('redis', ['port' => 6379])
            ->once()
            ->andReturn(true);

        $this->serviceManager->shouldReceive('regenerateCompose')
            ->once()
            ->andReturn(true);

        $this->artisan('service:configure redis --set=port=6379 --json')
            ->assertExitCode(0);
    });
});

describe('service:info', function () {
    it('shows information for a configured service', function () {
        $this->serviceManager->shouldReceive('getService')
            ->with('redis')
            ->once()
            ->andReturn(['enabled' => true, 'version' => '7.4']);

        $this->templateLoader->shouldReceive('exists')
            ->with('redis')
            ->once()
            ->andReturn(false);

        $this->artisan('service:info redis')
            ->expectsOutputToContain('Configuration')
            ->assertExitCode(0);
    });

    it('shows template information when available', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'In-memory data structure store',
            category: 'cache',
            versions: ['7.4', '7.2'],
            configSchema: [],
            dockerConfig: [],
            dependsOn: []
        );

        $this->serviceManager->shouldReceive('getService')
            ->with('redis')
            ->once()
            ->andReturn(['enabled' => true, 'version' => '7.4']);

        $this->templateLoader->shouldReceive('exists')
            ->with('redis')
            ->once()
            ->andReturn(true);

        $this->templateLoader->shouldReceive('load')
            ->with('redis')
            ->once()
            ->andReturn($template);

        $this->artisan('service:info redis')
            ->expectsOutputToContain('Redis')
            ->expectsOutputToContain('In-memory data structure store')
            ->assertExitCode(0);
    });

    it('handles non-existent services', function () {
        $this->serviceManager->shouldReceive('getService')
            ->with('nonexistent')
            ->once()
            ->andReturn(null);

        $this->templateLoader->shouldReceive('exists')
            ->with('nonexistent')
            ->once()
            ->andReturn(false);

        $this->artisan('service:info nonexistent')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->serviceManager->shouldReceive('getService')
            ->with('redis')
            ->once()
            ->andReturn(['enabled' => true, 'version' => '7.4']);

        $this->templateLoader->shouldReceive('exists')
            ->with('redis')
            ->once()
            ->andReturn(false);

        $this->artisan('service:info redis --json')
            ->assertExitCode(0);
    });
});
