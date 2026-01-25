<?php

use App\Data\ServiceTemplate;
use App\Services\ServiceConfigValidator;
use App\Services\ServiceTemplateLoader;

describe('ServiceTemplate DTO', function () {
    it('creates from array data', function () {
        $data = [
            'name' => 'redis',
            'label' => 'Redis',
            'description' => 'In-memory data store',
            'category' => 'cache',
            'versions' => ['7.4', '7.2'],
            'configSchema' => ['type' => 'object'],
            'dockerConfig' => ['image' => 'redis:7.4'],
            'dependsOn' => ['network'],
            'required' => false,
        ];

        $template = ServiceTemplate::fromArray($data);

        expect($template->name)->toBe('redis')
            ->and($template->label)->toBe('Redis')
            ->and($template->description)->toBe('In-memory data store')
            ->and($template->category)->toBe('cache')
            ->and($template->versions)->toBe(['7.4', '7.2'])
            ->and($template->configSchema)->toBe(['type' => 'object'])
            ->and($template->dockerConfig)->toBe(['image' => 'redis:7.4'])
            ->and($template->dependsOn)->toBe(['network'])
            ->and($template->required)->toBeFalse();
    });

    it('converts to array', function () {
        $template = new ServiceTemplate(
            name: 'postgres',
            label: 'PostgreSQL',
            description: 'Relational database',
            category: 'database',
            versions: ['16', '15'],
            configSchema: [],
            dockerConfig: [],
            dependsOn: [],
            required: false,
        );

        $array = $template->toArray();

        expect($array)->toBe([
            'name' => 'postgres',
            'label' => 'PostgreSQL',
            'description' => 'Relational database',
            'category' => 'database',
            'versions' => ['16', '15'],
            'configSchema' => [],
            'dockerConfig' => [],
            'dependsOn' => [],
            'required' => false,
        ]);
    });

    it('checks dependencies', function () {
        $template = new ServiceTemplate(
            name: 'app',
            label: 'Application',
            description: 'Main app',
            category: 'app',
            versions: ['1.0'],
            configSchema: [],
            dockerConfig: [],
            dependsOn: ['redis', 'postgres'],
            required: false,
        );

        expect($template->dependsOn('redis'))->toBeTrue()
            ->and($template->dependsOn('postgres'))->toBeTrue()
            ->and($template->dependsOn('mysql'))->toBeFalse();
    });

    it('gets default version', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4', '7.2', '7.0'],
            configSchema: [],
            dockerConfig: [],
            required: false,
        );

        expect($template->getDefaultVersion())->toBe('7.4');
    });

    it('returns null for default version when no versions', function () {
        $template = new ServiceTemplate(
            name: 'custom',
            label: 'Custom',
            description: 'Custom service',
            category: 'other',
            versions: [],
            configSchema: [],
            dockerConfig: [],
            required: false,
        );

        expect($template->getDefaultVersion())->toBeNull();
    });

    it('checks if version is supported', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4', '7.2', '7.0'],
            configSchema: [],
            dockerConfig: [],
            required: false,
        );

        expect($template->supportsVersion('7.4'))->toBeTrue()
            ->and($template->supportsVersion('7.2'))->toBeTrue()
            ->and($template->supportsVersion('6.0'))->toBeFalse();
    });
});

describe('ServiceTemplateLoader', function () {
    beforeEach(function () {
        $this->testDir = sys_get_temp_dir().'/orbit-template-tests-'.uniqid();
        @mkdir($this->testDir, 0755, true);
        $this->loader = new ServiceTemplateLoader($this->testDir);
    });

    afterEach(function () {
        // Clean up test directory
        if (is_dir($this->testDir)) {
            $files = array_diff(scandir($this->testDir), ['.', '..']);
            foreach ($files as $file) {
                @unlink("{$this->testDir}/{$file}");
            }
            @rmdir($this->testDir);
        }
    });

    it('loads a template from JSON file', function () {
        $templateData = [
            'name' => 'redis',
            'label' => 'Redis',
            'description' => 'Cache',
            'category' => 'cache',
            'versions' => ['7.4'],
            'configSchema' => [],
            'dockerConfig' => [],
        ];

        file_put_contents(
            "{$this->testDir}/redis.json",
            json_encode($templateData)
        );

        $template = $this->loader->load('redis');

        expect($template->name)->toBe('redis')
            ->and($template->label)->toBe('Redis');
    });

    it('throws exception for non-existent template', function () {
        $this->loader->load('nonexistent');
    })->throws(RuntimeException::class, 'Service template not found: nonexistent');

    it('throws exception for invalid JSON', function () {
        file_put_contents("{$this->testDir}/invalid.json", '{invalid json}');

        $this->loader->load('invalid');
    })->throws(RuntimeException::class);

    it('caches loaded templates', function () {
        $templateData = [
            'name' => 'redis',
            'label' => 'Redis',
            'description' => 'Cache',
            'category' => 'cache',
            'versions' => ['7.4'],
            'configSchema' => [],
            'dockerConfig' => [],
        ];

        file_put_contents(
            "{$this->testDir}/redis.json",
            json_encode($templateData)
        );

        $template1 = $this->loader->load('redis');
        $template2 = $this->loader->load('redis');

        expect($template1)->toBe($template2);
    });

    it('loads all available templates', function () {
        file_put_contents(
            "{$this->testDir}/redis.json",
            json_encode([
                'name' => 'redis',
                'label' => 'Redis',
                'description' => 'Cache',
                'category' => 'cache',
                'versions' => ['7.4'],
                'configSchema' => [],
                'dockerConfig' => [],
            ])
        );

        file_put_contents(
            "{$this->testDir}/postgres.json",
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16'],
                'configSchema' => [],
                'dockerConfig' => [],
            ])
        );

        $templates = $this->loader->loadAll();

        expect($templates)->toHaveCount(2)
            ->and($templates)->toHaveKey('redis')
            ->and($templates)->toHaveKey('postgres');
    });

    it('gets available template names', function () {
        file_put_contents("{$this->testDir}/redis.json", '{}');
        file_put_contents("{$this->testDir}/postgres.json", '{}');
        file_put_contents("{$this->testDir}/readme.txt", 'not a template');

        $names = $this->loader->getAvailable();

        expect($names)->toHaveCount(2)
            ->and($names)->toContain('redis')
            ->and($names)->toContain('postgres')
            ->and($names)->not->toContain('readme');
    });

    it('checks if template exists', function () {
        file_put_contents("{$this->testDir}/redis.json", '{}');

        expect($this->loader->exists('redis'))->toBeTrue()
            ->and($this->loader->exists('nonexistent'))->toBeFalse();
    });

    it('clears template cache', function () {
        file_put_contents(
            "{$this->testDir}/redis.json",
            json_encode([
                'name' => 'redis',
                'label' => 'Redis',
                'description' => 'Cache',
                'category' => 'cache',
                'versions' => ['7.4'],
                'configSchema' => [],
                'dockerConfig' => [],
            ])
        );

        $template1 = $this->loader->load('redis');
        $this->loader->clearCache();
        $template2 = $this->loader->load('redis');

        // After clearing cache, a new instance is loaded
        expect($template1)->not->toBe($template2);
    });

    it('gets templates by category', function () {
        file_put_contents(
            "{$this->testDir}/redis.json",
            json_encode([
                'name' => 'redis',
                'label' => 'Redis',
                'description' => 'Cache',
                'category' => 'cache',
                'versions' => ['7.4'],
                'configSchema' => [],
                'dockerConfig' => [],
            ])
        );

        file_put_contents(
            "{$this->testDir}/postgres.json",
            json_encode([
                'name' => 'postgres',
                'label' => 'PostgreSQL',
                'description' => 'Database',
                'category' => 'database',
                'versions' => ['16'],
                'configSchema' => [],
                'dockerConfig' => [],
            ])
        );

        $cacheTemplates = $this->loader->getByCategory('cache');
        $dbTemplates = $this->loader->getByCategory('database');

        expect($cacheTemplates)->toHaveCount(1)
            ->and($cacheTemplates)->toHaveKey('redis')
            ->and($dbTemplates)->toHaveCount(1)
            ->and($dbTemplates)->toHaveKey('postgres');
    });
});

describe('ServiceConfigValidator', function () {
    beforeEach(function () {
        $this->validator = new ServiceConfigValidator;
    });

    it('validates required fields', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'required' => ['port', 'host'],
                'properties' => [],
            ],
            dockerConfig: [],
            required: false,
        );

        $result = $this->validator->validate(['port' => 6379], $template);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'])->toContain("Required field 'host' is missing");
    });

    it('validates field types', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'properties' => [
                    'port' => ['type' => 'number'],
                    'host' => ['type' => 'string'],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result = $this->validator->validate([
            'port' => 'not-a-number',
            'host' => 'localhost',
        ], $template);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'][0])->toContain('must be of type number');
    });

    it('validates enum values', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['standalone', 'cluster', 'sentinel'],
                    ],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result = $this->validator->validate(['mode' => 'invalid'], $template);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'][0])->toContain('must be one of: standalone, cluster, sentinel');
    });

    it('validates number ranges', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'properties' => [
                    'port' => [
                        'type' => 'number',
                        'minimum' => 1024,
                        'maximum' => 65535,
                    ],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result1 = $this->validator->validate(['port' => 500], $template);
        $result2 = $this->validator->validate(['port' => 70000], $template);
        $result3 = $this->validator->validate(['port' => 6379], $template);

        expect($result1['valid'])->toBeFalse()
            ->and($result2['valid'])->toBeFalse()
            ->and($result3['valid'])->toBeTrue();
    });

    it('validates string length', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'properties' => [
                    'password' => [
                        'type' => 'string',
                        'minLength' => 8,
                        'maxLength' => 32,
                    ],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result1 = $this->validator->validate(['password' => 'short'], $template);
        $result2 = $this->validator->validate(['password' => str_repeat('a', 40)], $template);
        $result3 = $this->validator->validate(['password' => 'goodpassword'], $template);

        expect($result1['valid'])->toBeFalse()
            ->and($result2['valid'])->toBeFalse()
            ->and($result3['valid'])->toBeTrue();
    });

    it('applies default values', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'properties' => [
                    'port' => ['type' => 'number', 'default' => 6379],
                    'host' => ['type' => 'string', 'default' => 'localhost'],
                    'persistence' => ['type' => 'boolean', 'default' => true],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $config = $this->validator->applyDefaults(['host' => 'redis'], $template);

        expect($config['host'])->toBe('redis')
            ->and($config['port'])->toBe(6379)
            ->and($config['persistence'])->toBeTrue();
    });

    it('validates and applies defaults together', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'required' => ['host'],
                'properties' => [
                    'host' => ['type' => 'string'],
                    'port' => ['type' => 'number', 'default' => 6379],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result = $this->validator->validateAndApplyDefaults(['host' => 'localhost'], $template);

        expect($result['valid'])->toBeTrue()
            ->and($result['config']['host'])->toBe('localhost')
            ->and($result['config']['port'])->toBe(6379);
    });

    it('passes validation for valid config', function () {
        $template = new ServiceTemplate(
            name: 'redis',
            label: 'Redis',
            description: 'Cache',
            category: 'cache',
            versions: ['7.4'],
            configSchema: [
                'required' => ['port'],
                'properties' => [
                    'port' => ['type' => 'number'],
                    'host' => ['type' => 'string'],
                ],
            ],
            dockerConfig: [],
            required: false,
        );

        $result = $this->validator->validate([
            'port' => 6379,
            'host' => 'localhost',
        ], $template);

        expect($result['valid'])->toBeTrue()
            ->and($result['errors'])->toBeEmpty();
    });
});
