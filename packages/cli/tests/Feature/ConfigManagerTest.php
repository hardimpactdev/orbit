<?php

use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/orbit-config-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Store original HOME and set test HOME (ConfigManager uses getenv)
    $this->originalHome = getenv('HOME');
    putenv("HOME={$this->tempDir}");
    $_SERVER['HOME'] = $this->tempDir;
});

afterEach(function () {
    // Restore original HOME
    putenv("HOME={$this->originalHome}");
    $_SERVER['HOME'] = $this->originalHome;

    // Cleanup
    if (File::isDirectory($this->tempDir.'/.config/orbit')) {
        File::deleteDirectory($this->tempDir.'/.config/orbit');
    }
    if (File::isDirectory($this->tempDir.'/.config')) {
        File::deleteDirectory($this->tempDir.'/.config');
    }
    if (File::isDirectory($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('returns default values when config does not exist', function () {
    $configManager = new ConfigManager;

    expect($configManager->getDefaultPhpVersion())->toBe('8.3');
    expect($configManager->getTld())->toBe('test');
    expect($configManager->getHostIp())->toBe('127.0.0.1');
    expect($configManager->getPaths())->toBe([]);
});

it('loads config from file', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'default_php_version' => '8.4',
        'tld' => 'local',
        'paths' => ['/home/user/projects'],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->getDefaultPhpVersion())->toBe('8.4');
    expect($configManager->getTld())->toBe('local');
    expect($configManager->getPaths())->toBe(['/home/user/projects']);
});

it('gets and sets values', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([]));

    $configManager = new ConfigManager;
    $configManager->set('custom_key', 'custom_value');

    expect($configManager->get('custom_key'))->toBe('custom_value');
});

it('manages site php version overrides', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([]));

    $configManager = new ConfigManager;

    $configManager->setSitePhpVersion('mysite', '8.4');
    expect($configManager->getSitePhpVersion('mysite'))->toBe('8.4');

    $configManager->removeSiteOverride('mysite');
    expect($configManager->getSitePhpVersion('mysite'))->toBeNull();
});

it('returns site overrides', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'sites' => [
            'site1' => ['php_version' => '8.4'],
            'site2' => ['php_version' => '8.3'],
        ],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->getSiteOverrides())->toBe([
        'site1' => ['php_version' => '8.4'],
        'site2' => ['php_version' => '8.3'],
    ]);
});

it('checks enabled services', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'services' => [
            'postgres' => ['enabled' => true],
            'redis' => ['enabled' => false],
            'mailpit' => ['enabled' => true],
        ],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->isServiceEnabled('postgres'))->toBeTrue();
    expect($configManager->isServiceEnabled('redis'))->toBeFalse();
    expect($configManager->getEnabledServices())->toBe(['postgres', 'mailpit']);
});

it('returns default DNS mappings when config does not exist', function () {
    $configManager = new ConfigManager;

    $mappings = $configManager->getDnsMappings();

    expect($mappings)->toBeArray();
    expect($mappings)->toHaveCount(3);
    expect($mappings[0])->toBe(['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1']);
    expect($mappings[1])->toBe(['type' => 'server', 'value' => '8.8.8.8']);
    expect($mappings[2])->toBe(['type' => 'server', 'value' => '8.8.4.4']);
});

it('manages DNS mappings', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'dns_mappings' => [],
    ]));

    $configManager = new ConfigManager;

    // Initial state should be empty
    expect($configManager->getDnsMappings())->toHaveCount(0);

    // Add address mapping
    $configManager->addDnsMapping('address', '127.0.0.1', 'local');
    $mappings = $configManager->getDnsMappings();
    expect($mappings)->toHaveCount(1);
    expect($mappings[0])->toBe(['type' => 'address', 'value' => '127.0.0.1', 'tld' => 'local']);

    // Add server mapping without TLD
    $configManager->addDnsMapping('server', '1.1.1.1');
    $mappings = $configManager->getDnsMappings();
    expect($mappings)->toHaveCount(2);
    expect($mappings[1])->toBe(['type' => 'server', 'value' => '1.1.1.1']);

    // Add server mapping with TLD
    $configManager->addDnsMapping('server', '10.6.0.1', 'ccc');
    $mappings = $configManager->getDnsMappings();
    expect($mappings)->toHaveCount(3);
    expect($mappings[2])->toBe(['type' => 'server', 'value' => '10.6.0.1', 'tld' => 'ccc']);
});

it('removes DNS mapping by index', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'dns_mappings' => [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
            ['type' => 'server', 'value' => '8.8.8.8'],
            ['type' => 'server', 'value' => '8.8.4.4'],
        ],
    ]));

    $configManager = new ConfigManager;

    expect($configManager->getDnsMappings())->toHaveCount(3);

    $configManager->removeDnsMapping(1);
    $mappings = $configManager->getDnsMappings();

    expect($mappings)->toHaveCount(2);
    expect($mappings[0])->toBe(['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1']);
    expect($mappings[1])->toBe(['type' => 'server', 'value' => '8.8.4.4']);
});

it('generates dnsmasq.conf from mappings', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'dns_mappings' => [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
            ['type' => 'server', 'tld' => 'ccc', 'value' => '10.6.0.1'],
            ['type' => 'server', 'value' => '8.8.8.8'],
        ],
    ]));

    $configManager = new ConfigManager;
    $content = $configManager->generateDnsmasqConf();

    expect($content)->toContain('address=/test/127.0.0.1');
    expect($content)->toContain('server=/ccc/10.6.0.1');
    expect($content)->toContain('server=8.8.8.8');
    expect($content)->toContain('log-queries');
    expect($content)->toContain('log-facility=-');
});

it('writes dnsmasq.conf to disk', function () {
    $configPath = $this->tempDir.'/.config/orbit';
    File::ensureDirectoryExists($configPath);
    File::put($configPath.'/config.json', json_encode([
        'dns_mappings' => [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
        ],
    ]));

    $configManager = new ConfigManager;
    $configManager->writeDnsmasqConf();

    $dnsmasqPath = $configPath.'/dnsmasq.conf';
    expect(File::exists($dnsmasqPath))->toBeTrue();

    $content = File::get($dnsmasqPath);
    expect($content)->toContain('address=/test/127.0.0.1');
});
