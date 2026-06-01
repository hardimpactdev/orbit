<?php

declare(strict_types=1);

use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

beforeEach(function (): void {
    $this->tempPath = tempnam(sys_get_temp_dir(), 'orbit-config-test-').'.json';
});

afterEach(function (): void {
    if (isset($this->tempPath) && is_file($this->tempPath)) {
        @unlink($this->tempPath);
    }
});

describe(OrbitConfigStore::class, function (): void {
    it('returns the empty skeleton when the config file does not exist', function (): void {
        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        @unlink($this->tempPath);

        $config = $store->read();

        expect($config['schema_version'])->toBe(OrbitConfigStore::CURRENT_SCHEMA_VERSION)
            ->and($config['active_gateway'])->toBeNull()
            ->and($config['gateways'])->toBe([])
            ->and($config['defaults'])->toBe(['node' => null, 'profile' => null]);
    });

    it('honours the ORBIT_CONFIG_PATH override path', function (): void {
        $store = new OrbitConfigStore(overridePath: '/custom/path/orbit.json');

        expect($store->path())->toBe('/custom/path/orbit.json');
    });

    it('falls back to $HOME/.config/orbit/config.json when no override is provided', function (): void {
        $original = getenv('HOME');
        putenv('HOME=/tmp/orbit-fake-home');

        try {
            $store = new OrbitConfigStore;

            expect($store->path())->toBe('/tmp/orbit-fake-home/.config/orbit/config.json');
        } finally {
            putenv('HOME='.($original === false ? '' : $original));
        }
    });

    it('throws config_invalid_json when the file body is malformed', function (): void {
        file_put_contents($this->tempPath, 'not-json');
        chmod($this->tempPath, 0o600);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect(fn () => $store->read())
            ->toThrow(OrbitConfigStoreException::class);
    });

    it('throws config_invalid_root when the JSON root is not an object', function (): void {
        file_put_contents($this->tempPath, '[]');
        chmod($this->tempPath, 0o600);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect(fn () => $store->read())
            ->toThrow(OrbitConfigStoreException::class);
    });

    it('throws config_invalid_schema_version when schema_version is missing or non-int', function (): void {
        file_put_contents($this->tempPath, '{"gateways":{}}');
        chmod($this->tempPath, 0o600);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect(fn () => $store->read())
            ->toThrow(OrbitConfigStoreException::class);
    });

    it('throws config_schema_version_too_new when schema_version exceeds current', function (): void {
        file_put_contents($this->tempPath, '{"schema_version":999}');
        chmod($this->tempPath, 0o600);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect(fn () => $store->read())
            ->toThrow(OrbitConfigStoreException::class);
    });

    it('writes the config file atomically with mode 0600 and returns it on read', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        $store->save([
            'active_gateway' => 'default',
            'gateways' => [
                'default' => [
                    'url' => 'https://10.6.0.1',
                    'wireguard_ip' => '10.6.0.1',
                    'ca_pem_path' => '/tmp/ca.pem',
                    'ca_sha256' => 'deadbeef',
                    'ca_fingerprint' => 'sha256:deadbeef',
                    'timeout' => 30,
                    'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
                ],
            ],
            'defaults' => ['node' => 'agent-1', 'profile' => null],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ]);

        expect(is_file($this->tempPath))->toBeTrue();

        $perms = fileperms($this->tempPath) & 0o777;
        expect($perms)->toBe(OrbitConfigStore::FILE_MODE);

        $config = $store->read();
        expect($config['active_gateway'])->toBe('default')
            ->and($config['defaults']['node'])->toBe('agent-1')
            ->and($config['schema_version'])->toBe(OrbitConfigStore::CURRENT_SCHEMA_VERSION);
    });

    it('returns the active gateway entry through activeGateway()', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => ['default' => ['url' => 'https://10.6.0.1', 'timeout' => 5]],
        ]);

        $entry = $store->activeGateway();

        expect($entry)->not->toBeNull()
            ->and($entry['url'])->toBe('https://10.6.0.1');
    });

    it('returns active gateway name and sorted gateway entries', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'zulu',
            'gateways' => [
                'zulu' => ['url' => 'https://10.6.0.3'],
                'alpha' => ['url' => 'https://10.6.0.2'],
            ],
        ]);

        expect($store->activeGatewayName())->toBe('zulu')
            ->and(array_keys($store->gatewayEntries()))->toBe(['alpha', 'zulu'])
            ->and($store->gatewayEntry('alpha')['url'])->toBe('https://10.6.0.2');
    });

    it('switches active gateway when the entry exists', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => [
                'default' => ['url' => 'https://10.6.0.2'],
                'incus-dev' => ['url' => 'https://10.6.0.12'],
            ],
        ]);

        expect($store->setActiveGateway('incus-dev'))->toBeTrue()
            ->and($store->activeGatewayName())->toBe('incus-dev')
            ->and($store->activeGateway()['url'])->toBe('https://10.6.0.12');
    });

    it('rejects invalid and unknown gateway names', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => [
                'default' => ['url' => 'https://10.6.0.2'],
            ],
        ]);

        expect(OrbitConfigStore::isValidGatewayName('incus-dev'))->toBeTrue()
            ->and(OrbitConfigStore::isValidGatewayName('../prod'))->toBeFalse()
            ->and($store->setActiveGateway('missing'))->toBeFalse()
            ->and($store->gatewayEntry('../prod'))->toBeNull();
    });

    it('returns null from activeGateway() when active_gateway is missing', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect($store->activeGateway())->toBeNull();
    });

    it('returns the default node through defaultNode()', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save(['defaults' => ['node' => 'agent-1', 'profile' => null]]);

        expect($store->defaultNode())->toBe('agent-1');
    });

    it('returns null from defaultNode() when defaults.node is null', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save(['defaults' => ['node' => null, 'profile' => null]]);

        expect($store->defaultNode())->toBeNull();
    });

    it('returns null from defaultNode() when the config file does not exist', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect($store->defaultNode())->toBeNull();
    });

    it('stores and reads the default node via setDefaultNode()', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');

        expect($store->defaultNode())->toBe('app-1');
    });

    it('overwrites an existing default node via setDefaultNode()', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');
        $store->setDefaultNode('app-2');

        expect($store->defaultNode())->toBe('app-2');
    });

    it('clearDefaultNode() returns true when a default was set', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');

        $wasSet = $store->clearDefaultNode();

        expect($wasSet)->toBeTrue()
            ->and($store->defaultNode())->toBeNull();
    });

    it('clearDefaultNode() returns false when no default was set', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        $wasSet = $store->clearDefaultNode();

        expect($wasSet)->toBeFalse()
            ->and($store->defaultNode())->toBeNull();
    });

    it('clearDefaultNode() is idempotent on a fresh config', function (): void {
        @unlink($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->clearDefaultNode();

        expect($store->defaultNode())->toBeNull();
    });
});
