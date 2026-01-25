<?php

use App\Services\ComposeGenerator;
use App\Services\ConfigManager;
use App\Services\ServiceTemplateLoader;

describe('ComposeGenerator', function () {
    beforeEach(function () {
        $this->testDir = sys_get_temp_dir().'/orbit-compose-tests-'.uniqid();
        @mkdir($this->testDir, 0755, true);

        // Create mock ConfigManager
        $this->configManager = Mockery::mock(ConfigManager::class);
        $this->configManager->shouldReceive('getConfigPath')->andReturn($this->testDir);

        // Create test template loader
        $templatesDir = $this->testDir.'/templates';
        @mkdir($templatesDir, 0755, true);

        // Create a test template
        file_put_contents(
            $templatesDir.'/redis.json',
            json_encode([
                'name' => 'redis',
                'label' => 'Redis',
                'description' => 'Cache',
                'category' => 'cache',
                'versions' => ['7.4', '7.2'],
                'configSchema' => [],
                'dockerConfig' => [
                    'image' => 'redis:${version}',
                    'ports' => ['${port}:${port}'],
                    'volumes' => ['${data_path}:/data'],
                ],
                'dependsOn' => [],
            ])
        );

        $this->templateLoader = new ServiceTemplateLoader($templatesDir);
        $this->generator = new ComposeGenerator($this->configManager, $this->templateLoader);
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

    it('generates docker-compose YAML for enabled services', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('version:')
            ->and($yaml)->toContain('services:')
            ->and($yaml)->toContain('redis:')
            ->and($yaml)->toContain('container_name: orbit-redis')
            ->and($yaml)->toContain('image: "redis:7.4"')
            ->and($yaml)->toContain('networks:')
            ->and($yaml)->toContain('- orbit');
    });

    it('skips disabled services', function () {
        $services = [
            'redis' => [
                'enabled' => false,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->not->toContain('container_name: orbit-redis');
    });

    it('interpolates version variables', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.2',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('image: "redis:7.2"');
    });

    it('interpolates port variables', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6380,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('6380:6380');
    });

    it('interpolates data_path variables', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain($this->testDir.'/data/redis:/data');
    });

    it('uses custom data_path when provided', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
                'data_path' => '/custom/path',
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('/custom/path:/data');
    });

    it('adds restart policy', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('restart: unless-stopped');
    });

    it('includes orbit network configuration', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('networks:')
            ->and($yaml)->toContain('orbit:')
            ->and($yaml)->toContain('external: true')
            ->and($yaml)->toContain('name: orbit');
    });

    it('sorts services by dependencies', function () {
        // Create postgres template that depends on network
        file_put_contents(
            $this->testDir.'/templates/postgres.json',
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16'],
                'configSchema' => [],
                'dockerConfig' => ['image' => 'postgres:${version}'],
                'dependsOn' => ['redis'],
            ])
        );

        // Reload template loader
        $this->templateLoader = new ServiceTemplateLoader($this->testDir.'/templates');
        $this->generator = new ComposeGenerator($this->configManager, $this->templateLoader);

        $services = [
            'postgres' => ['enabled' => true, 'version' => '16'],
            'redis' => ['enabled' => true, 'version' => '7.4', 'port' => 6379],
        ];

        $yaml = $this->generator->generate($services);

        // Redis should appear before postgres in the YAML
        $redisPos = strpos($yaml, 'container_name: orbit-redis');
        $postgresPos = strpos($yaml, 'container_name: orbit-postgres');

        expect($redisPos)->toBeLessThan($postgresPos);
    });

    it('writes docker-compose file to disk', function () {
        $services = [
            'redis' => [
                'enabled' => true,
                'version' => '7.4',
                'port' => 6379,
            ],
        ];

        $content = $this->generator->generateAndWrite($services);

        $outputPath = $this->testDir.'/docker-compose.yaml';
        expect(file_exists($outputPath))->toBeTrue();

        $fileContent = file_get_contents($outputPath);
        expect($fileContent)->toContain('version:')
            ->and($fileContent)->toContain('redis:');
    });

    it('handles environment variables from docker config', function () {
        // Create template with environment
        file_put_contents(
            $this->testDir.'/templates/postgres.json',
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16'],
                'configSchema' => [],
                'dockerConfig' => [
                    'image' => 'postgres:${version}',
                    'environment' => [
                        'POSTGRES_USER' => 'orbit',
                        'POSTGRES_PASSWORD' => 'secret',
                    ],
                ],
                'dependsOn' => [],
            ])
        );

        $this->templateLoader = new ServiceTemplateLoader($this->testDir.'/templates');
        $this->generator = new ComposeGenerator($this->configManager, $this->templateLoader);

        $services = [
            'postgres' => ['enabled' => true, 'version' => '16'],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('environment:')
            ->and($yaml)->toContain('POSTGRES_USER: orbit')
            ->and($yaml)->toContain('POSTGRES_PASSWORD: secret');
    });

    it('merges custom environment variables from user config', function () {
        // Create template with environment
        file_put_contents(
            $this->testDir.'/templates/postgres.json',
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16'],
                'configSchema' => [],
                'dockerConfig' => [
                    'image' => 'postgres:${version}',
                    'environment' => [
                        'POSTGRES_USER' => 'orbit',
                    ],
                ],
                'dependsOn' => [],
            ])
        );

        $this->templateLoader = new ServiceTemplateLoader($this->testDir.'/templates');
        $this->generator = new ComposeGenerator($this->configManager, $this->templateLoader);

        $services = [
            'postgres' => [
                'enabled' => true,
                'version' => '16',
                'environment' => [
                    'POSTGRES_PASSWORD' => 'custom-password',
                    'POSTGRES_DB' => 'mydb',
                ],
            ],
        ];

        $yaml = $this->generator->generate($services);

        expect($yaml)->toContain('POSTGRES_USER: orbit')
            ->and($yaml)->toContain('POSTGRES_PASSWORD: custom-password')
            ->and($yaml)->toContain('POSTGRES_DB: mydb');
    });
});
