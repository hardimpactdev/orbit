<?php

use App\Services\ComposeGenerator;
use App\Services\ConfigManager;
use App\Services\ServiceConfigValidator;
use App\Services\ServiceManager;
use App\Services\ServiceTemplateLoader;

describe('ServiceManager', function () {
    beforeEach(function () {
        $this->testDir = sys_get_temp_dir().'/orbit-sm-tests-'.uniqid();
        @mkdir($this->testDir, 0755, true);

        // Create templates directory
        $templatesDir = $this->testDir.'/templates';
        @mkdir($templatesDir, 0755, true);

        // Create test templates
        file_put_contents(
            $templatesDir.'/redis.json',
            json_encode([
                'name' => 'redis',
                'label' => 'Redis',
                'description' => 'Cache',
                'category' => 'cache',
                'versions' => ['7.4', '7.2'],
                'configSchema' => ['properties' => ['port' => ['type' => 'number']]],
                'dockerConfig' => ['image' => 'redis:${version}'],
                'dependsOn' => [],
            ])
        );

        file_put_contents(
            $templatesDir.'/postgres.json',
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16', '15'],
                'configSchema' => ['properties' => ['port' => ['type' => 'number']]],
                'dockerConfig' => ['image' => 'postgres:${version}'],
                'dependsOn' => [],
            ])
        );

        // Create stubs directory
        $stubsDir = $this->testDir.'/stubs';
        @mkdir($stubsDir, 0755, true);

        // Create services.yaml.stub
        file_put_contents(
            $stubsDir.'/services.yaml.stub',
            <<<'YAML'
services:
  redis:
    enabled: true
    version: "7.4"
    port: 6379
  postgres:
    enabled: false
    version: "16"
    port: 5432
YAML
        );

        // Mock dependencies
        $this->configManager = Mockery::mock(ConfigManager::class);
        $this->configManager->shouldReceive('getConfigPath')->andReturn($this->testDir);

        $this->templateLoader = new ServiceTemplateLoader($templatesDir);
        $this->validator = new ServiceConfigValidator;
        $this->composeGenerator = Mockery::mock(ComposeGenerator::class);

        // Copy stub to expected location for tests
        copy($stubsDir.'/services.yaml.stub', $this->testDir.'/stubs/services.yaml.stub');

        // Override base_path for stub loading
        $this->manager = new class($this->configManager, $this->composeGenerator, $this->templateLoader, $this->validator) extends ServiceManager
        {
            protected function getStubPath(): string
            {
                return $this->configManager->getConfigPath().'/stubs/services.yaml.stub';
            }
        };
    });

    afterEach(function () {
        Mockery::close();

        // Clean up test directory
        if (is_dir($this->testDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->testDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($this->testDir);
        }
    });

    it('initializes from stub if services.yaml does not exist', function () {
        expect(file_exists($this->testDir.'/services.yaml'))->toBeTrue();

        $services = $this->manager->getServices();
        expect($services)->toHaveKey('redis')
            ->and($services)->toHaveKey('postgres');
    });

    it('loads existing services.yaml', function () {
        // Create services.yaml manually
        file_put_contents(
            $this->testDir.'/services.yaml',
            <<<'YAML'
services:
  redis:
    enabled: true
    version: "7.2"
    port: 6380
YAML
        );

        $manager = new class($this->configManager, $this->composeGenerator, $this->templateLoader, $this->validator) extends ServiceManager
        {
            protected function getStubPath(): string
            {
                return $this->configManager->getConfigPath().'/stubs/services.yaml.stub';
            }
        };

        $services = $manager->getServices();
        expect($services['redis']['version'])->toBe('7.2')
            ->and($services['redis']['port'])->toBe(6380);
    });

    it('gets all services', function () {
        $services = $this->manager->getServices();

        expect($services)->toBeArray()
            ->and($services)->toHaveKey('redis')
            ->and($services)->toHaveKey('postgres');
    });

    it('gets enabled services only', function () {
        $enabled = $this->manager->getEnabled();

        expect($enabled)->toHaveKey('redis')
            ->and($enabled)->not->toHaveKey('postgres');
    });

    it('gets specific service configuration', function () {
        $redis = $this->manager->getService('redis');

        expect($redis)->toBeArray()
            ->and($redis['enabled'])->toBeTrue()
            ->and($redis['version'])->toBe('7.4');
    });

    it('returns null for non-existent service', function () {
        $service = $this->manager->getService('nonexistent');

        expect($service)->toBeNull();
    });

    it('checks if service is enabled', function () {
        expect($this->manager->isEnabled('redis'))->toBeTrue()
            ->and($this->manager->isEnabled('postgres'))->toBeFalse()
            ->and($this->manager->isEnabled('nonexistent'))->toBeFalse();
    });

    it('enables a disabled service', function () {
        expect($this->manager->isEnabled('postgres'))->toBeFalse();

        $result = $this->manager->enable('postgres');

        expect($result)->toBeTrue()
            ->and($this->manager->isEnabled('postgres'))->toBeTrue();
    });

    it('enables a non-existent service with template defaults', function () {
        $result = $this->manager->enable('redis');

        expect($result)->toBeTrue();

        $service = $this->manager->getService('redis');
        expect($service['enabled'])->toBeTrue()
            ->and($service['version'])->toBe('7.4');
    });

    it('throws exception when enabling service without template', function () {
        $this->manager->enable('nonexistent');
    })->throws(RuntimeException::class, 'Service template not found: nonexistent');

    it('disables an enabled service', function () {
        expect($this->manager->isEnabled('redis'))->toBeTrue();

        $result = $this->manager->disable('redis');

        expect($result)->toBeTrue()
            ->and($this->manager->isEnabled('redis'))->toBeFalse();
    });

    it('configures a service with valid settings', function () {
        $result = $this->manager->configure('redis', [
            'enabled' => true,
            'version' => '7.2',
            'port' => 6380,
        ]);

        expect($result)->toBeTrue();

        $redis = $this->manager->getService('redis');
        expect($redis['version'])->toBe('7.2')
            ->and($redis['port'])->toBe(6380);
    });

    it('throws exception for invalid configuration', function () {
        // Create a template with strict validation
        file_put_contents(
            $this->testDir.'/templates/strict.json',
            json_encode([
                'name' => 'strict',
                'label' => 'Strict',
                'description' => 'Strict service',
                'category' => 'test',
                'versions' => ['1.0'],
                'configSchema' => [
                    'required' => ['port'],
                    'properties' => [
                        'port' => ['type' => 'number', 'minimum' => 1024],
                    ],
                ],
                'dockerConfig' => ['image' => 'strict:1.0'],
                'dependsOn' => [],
            ])
        );

        $templateLoader = new ServiceTemplateLoader($this->testDir.'/templates');
        $validator = new ServiceConfigValidator;
        $manager = new class($this->configManager, $this->composeGenerator, $templateLoader, $validator) extends ServiceManager
        {
            protected function getStubPath(): string
            {
                return $this->configManager->getConfigPath().'/stubs/services.yaml.stub';
            }
        };

        $manager->configure('strict', ['port' => 500]); // Below minimum
    })->throws(RuntimeException::class);

    it('regenerates docker-compose file', function () {
        $this->composeGenerator->shouldReceive('generate')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn('version: "3.8"');
        $this->composeGenerator->shouldReceive('write')
            ->once()
            ->with(Mockery::type('string'));

        $result = $this->manager->regenerateCompose();

        expect($result)->toBeTrue();
    });

    it('saves services to file', function () {
        $this->manager->configure('redis', [
            'enabled' => true,
            'version' => '7.2',
            'port' => 6380,
        ]);

        $servicesPath = $this->testDir.'/services.yaml';
        expect(file_exists($servicesPath))->toBeTrue();

        $content = file_get_contents($servicesPath);
        expect($content)->toContain('redis:')
            ->and($content)->toContain('version: "7.2"')
            ->and($content)->toContain('port: 6380');
    });

    it('removes a service', function () {
        expect($this->manager->getService('redis'))->not->toBeNull();

        $result = $this->manager->remove('redis');

        expect($result)->toBeTrue()
            ->and($this->manager->getService('redis'))->toBeNull();
    });

    it('gets available service templates', function () {
        $available = $this->manager->getAvailableServices();

        expect($available)->toContain('redis')
            ->and($available)->toContain('postgres');
    });
});
