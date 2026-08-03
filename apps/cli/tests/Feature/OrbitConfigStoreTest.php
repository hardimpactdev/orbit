<?php

declare(strict_types=1);

use App\Exceptions\OrbitConfigStoreException;
use App\Services\Extensions\LocalExtensionState;
use App\Services\OrbitConfigStore;
use Orbit\Core\Extensions\OrbitExtensionRegistry;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->tempPath = orbit_test_config_path(prefix: 'orbit-config-test-');
});

afterEach(function (): void {
    unlink_orbit_test_file($this->tempPath);
});

describe(OrbitConfigStore::class, function (): void {
    it('returns the empty skeleton when the config file does not exist', function (): void {
        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        unlink_orbit_test_file($this->tempPath);

        $config = $store->read();

        expect($config['schema_version'])
            ->toBe(OrbitConfigStore::CURRENT_SCHEMA_VERSION)
            ->and($config['active_gateway'])
            ->toBeNull()
            ->and($config['gateways'])
            ->toBeEmpty()
            ->and($config['defaults'])
            ->toBe(['node' => null, 'profile' => null]);
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
        unlink_orbit_test_file($this->tempPath);

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
        expect($config['active_gateway'])
            ->toBe('default')
            ->and($config['defaults']['node'])
            ->toBe('agent-1')
            ->and($config['schema_version'])
            ->toBe(OrbitConfigStore::CURRENT_SCHEMA_VERSION);
    });

    it('returns the active gateway entry through activeGateway()', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => ['default' => ['url' => 'https://10.6.0.1', 'timeout' => 5]],
        ]);

        $entry = $store->activeGateway();

        expect($entry)
            ->not
            ->toBeNull()
            ->and($entry['url'])
            ->toBe('https://10.6.0.1');
    });

    it('returns active gateway name and sorted gateway entries', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'zulu',
            'gateways' => [
                'zulu' => ['url' => 'https://10.6.0.3'],
                'alpha' => ['url' => 'https://10.6.0.2'],
            ],
        ]);

        expect($store->activeGatewayName())
            ->toBe('zulu')
            ->and(array_keys($store->gatewayEntries()))
            ->toBe(['alpha', 'zulu'])
            ->and($store->gatewayEntry('alpha')['url'])
            ->toBe('https://10.6.0.2');
    });

    it('switches active gateway when the entry exists', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => [
                'default' => ['url' => 'https://10.6.0.2'],
                'incus-dev' => ['url' => 'https://10.6.0.12'],
            ],
        ]);

        expect($store->setActiveGateway('incus-dev'))
            ->toBeTrue()
            ->and($store->activeGatewayName())
            ->toBe('incus-dev')
            ->and($store->activeGateway()['url'])
            ->toBe('https://10.6.0.12');
    });

    it('rejects invalid and unknown gateway names', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => 'default',
            'gateways' => [
                'default' => ['url' => 'https://10.6.0.2'],
            ],
        ]);

        expect(OrbitConfigStore::isValidGatewayName('incus-dev'))
            ->toBeTrue()
            ->and(OrbitConfigStore::isValidGatewayName('../prod'))
            ->toBeFalse()
            ->and($store->setActiveGateway('missing'))
            ->toBeFalse()
            ->and($store->gatewayEntry('../prod'))
            ->toBeNull();
    });

    it('returns null from activeGateway() when active_gateway is missing', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect($store->activeGateway())->toBeNull();
    });

    it('returns the default node through defaultNode()', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save(['defaults' => ['node' => 'agent-1', 'profile' => null]]);

        expect($store->defaultNode())->toBe('agent-1');
    });

    it('returns null from defaultNode() when defaults.node is null', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save(['defaults' => ['node' => null, 'profile' => null]]);

        expect($store->defaultNode())->toBeNull();
    });

    it('returns null from defaultNode() when the config file does not exist', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect($store->defaultNode())->toBeNull();
    });

    it('stores and reads the default node via setDefaultNode()', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');

        expect($store->defaultNode())->toBe('app-1');
    });

    it('overwrites an existing default node via setDefaultNode()', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');
        $store->setDefaultNode('app-2');

        expect($store->defaultNode())->toBe('app-2');
    });

    it('clearDefaultNode() returns true when a default was set', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->setDefaultNode('app-1');

        $wasSet = $store->clearDefaultNode();

        expect($wasSet)->toBeTrue()->and($store->defaultNode())->toBeNull();
    });

    it('clearDefaultNode() returns false when no default was set', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        $wasSet = $store->clearDefaultNode();

        expect($wasSet)->toBeFalse()->and($store->defaultNode())->toBeNull();
    });

    it('clearDefaultNode() is idempotent on a fresh config', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->clearDefaultNode();

        expect($store->defaultNode())->toBeNull();
    });

    it('includes extensions.enabled as an empty list in the empty skeleton', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        $skeleton = $store->emptySkeleton();

        expect($skeleton['schema_version'])
            ->toBe(OrbitConfigStore::CURRENT_SCHEMA_VERSION)
            ->and($skeleton['active_gateway'])
            ->toBeNull()
            ->and($skeleton['gateways'])
            ->toBeEmpty()
            ->and($skeleton['defaults'])
            ->toBe(['node' => null, 'profile' => null])
            ->and($skeleton['extensions']['enabled'])
            ->toBeEmpty();
    });

    it('returns an empty enabled extension list when the config file does not exist', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);

        expect($store->enabledExtensions())->toBeEmpty();
    });

    it('tolerates older configs without an extensions key', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'active_gateway' => null,
            'gateways' => [],
            'defaults' => ['node' => null, 'profile' => null],
        ]);

        expect($store->enabledExtensions())->toBeEmpty();
    });

    it('returns a sorted unique enabled extension list and ignores malformed values', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->save([
            'extensions' => [
                'enabled' => ['solo', '', 'cloudflare', 'solo', 42, 'codex'],
            ],
        ]);

        expect($store->enabledExtensions())->toBe(['cloudflare', 'codex', 'solo']);
    });

    it('persists enabled extensions idempotently in sorted unique order', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->enableExtension('solo');
        $store->enableExtension('cloudflare');
        $store->enableExtension('cloudflare');

        expect($store->enabledExtensions())
            ->toBe(['cloudflare', 'solo'])
            ->and($store->read()['extensions']['enabled'])
            ->toBe(['cloudflare', 'solo']);
    });

    it('reports enabled state through isExtensionEnabled()', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->enableExtension('codex');

        expect($store->isExtensionEnabled('codex'))->toBeTrue()->and($store->isExtensionEnabled('solo'))->toBeFalse();
    });

    it('removes enabled extensions and reports whether state changed', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(overridePath: $this->tempPath);
        $store->enableExtension('codex');

        expect($store->disableExtension('codex'))
            ->toBeTrue()
            ->and($store->enabledExtensions())
            ->toBeEmpty()
            ->and($store->disableExtension('codex'))
            ->toBeFalse();
    });

    it('re-applies the exact agent read ACL after each atomic save when the agent user exists', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                $line = implode(' ', $command);

                if (($command[0] ?? null) === 'setfacl') {
                    $setfaclCalls[] = $line;

                    return orbit_config_store_process();
                }

                if (($command[0] ?? null) === 'getfacl') {
                    return orbit_config_store_process(
                        output: "user::rw-\nuser:agent:r--\ngroup::---\nmask::r--\nother::---\n",
                    );
                }

                return orbit_config_store_process(exitCode: 1);
            },
        );

        $store->save(['defaults' => ['node' => 'agent-1', 'profile' => null]]);
        $store->save(['defaults' => ['node' => 'agent-2', 'profile' => null]]);

        expect($setfaclCalls)
            ->toHaveCount(2)
            ->and($setfaclCalls[0])
            ->toBe('setfacl -m u:agent:r-- '.$this->tempPath)
            ->and($setfaclCalls[1])
            ->toBe('setfacl -m u:agent:r-- '.$this->tempPath)
            ->and($store->defaultNode())
            ->toBe('agent-2')
            ->and(fileperms($this->tempPath) & 0o777)
            ->toBe(OrbitConfigStore::FILE_MODE);
    });

    it('does not apply agent ACL on non-agent hosts', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $setfaclCalls = 0;
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => false,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                if (($command[0] ?? null) === 'setfacl') {
                    $setfaclCalls++;
                }

                return orbit_config_store_process(exitCode: 1);
            },
        );

        $store->save(['defaults' => ['node' => null, 'profile' => null]]);

        expect($setfaclCalls)->toBe(0);
    });

    it('reads config when traditional group bits only reflect the allowed agent ACL mask', function (): void {
        unlink_orbit_test_file($this->tempPath);
        file_put_contents($this->tempPath, json_encode([
            'schema_version' => 1,
            'defaults' => ['node' => 'agent-1', 'profile' => null],
            'gateways' => [],
            'active_gateway' => null,
            'extensions' => ['enabled' => []],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ], JSON_THROW_ON_ERROR));
        // Simulate ACL mask reflecting as traditional group-read (0640).
        chmod($this->tempPath, 0o640);

        $chmodWouldBreak = false;
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$chmodWouldBreak): Process {
                if (($command[0] ?? null) === 'getfacl') {
                    return orbit_config_store_process(
                        output: "user::rw-\nuser:agent:r--\t#effective:r--\ngroup::---\nmask::r--\nother::---\n",
                    );
                }

                if (($command[0] ?? null) === 'setfacl') {
                    $chmodWouldBreak = true;

                    return orbit_config_store_process();
                }

                return orbit_config_store_process(exitCode: 1);
            },
        );

        $config = $store->read();
        $permsAfter = fileperms($this->tempPath) & 0o777;

        expect($config['defaults']['node'] ?? null)
            ->toBe('agent-1')
            // Must not silently chmod 0600 and zero the ACL mask.
            ->and($permsAfter)
            ->toBe(0o640)
            ->and($chmodWouldBreak)
            ->toBeFalse();
    });

    it('tightens ordinary group-readable configs that lack the allowed agent ACL', function (): void {
        unlink_orbit_test_file($this->tempPath);
        file_put_contents($this->tempPath, json_encode([
            'schema_version' => 1,
            'defaults' => ['node' => null, 'profile' => null],
            'gateways' => [],
            'active_gateway' => null,
            'extensions' => ['enabled' => []],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ], JSON_THROW_ON_ERROR));
        chmod($this->tempPath, 0o640);

        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => false,
            processRunner: static fn (array $command): Process => orbit_config_store_process(
                exitCode: 1,
                errorOutput: 'getfacl: Operation not supported',
            ),
        );

        $store->read();

        expect(fileperms($this->tempPath) & 0o777)->toBe(OrbitConfigStore::FILE_MODE);
    });

    it('tightens other-readable exposure even when an agent ACL is present', function (): void {
        unlink_orbit_test_file($this->tempPath);
        file_put_contents($this->tempPath, json_encode([
            'schema_version' => 1,
            'defaults' => ['node' => null, 'profile' => null],
            'gateways' => [],
            'active_gateway' => null,
            'extensions' => ['enabled' => []],
            'meta' => ['imported_from' => null, 'imported_at' => null],
        ], JSON_THROW_ON_ERROR));
        chmod($this->tempPath, 0o644);

        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                if (($command[0] ?? null) === 'setfacl') {
                    $setfaclCalls[] = implode(' ', $command);

                    return orbit_config_store_process();
                }

                return orbit_config_store_process(exitCode: 1);
            },
        );

        $store->read();

        expect(fileperms($this->tempPath) & 0o777)
            ->toBe(OrbitConfigStore::FILE_MODE)
            ->and($setfaclCalls)
            ->toBe(['setfacl -m u:agent:r-- '.$this->tempPath]);
    });

    it('fails closed when agent ACL re-apply fails after save on an agent node', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: static fn (array $command): Process => orbit_config_store_process(
                exitCode: 1,
                errorOutput: 'setfacl failed',
            ),
        );

        expect(fn () => $store->save(['defaults' => ['node' => null, 'profile' => null]]))
            ->toThrow(OrbitConfigStoreException::class, 'Failed to re-apply agent read ACL');
    });
});

/**
 * @return Process
 */
function orbit_config_store_process(
    int $exitCode = 0,
    string $output = '',
    string $errorOutput = '',
): Process {
    $process = \Mockery::mock(Process::class);
    $process->shouldReceive('isSuccessful')->andReturn($exitCode === 0);
    $process->shouldReceive('getExitCode')->andReturn($exitCode);
    $process->shouldReceive('getOutput')->andReturn($output);
    $process->shouldReceive('getErrorOutput')->andReturn($errorOutput);

    return $process;
}

describe(LocalExtensionState::class, function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-extension-state-test-');
        $this->store = new OrbitConfigStore(overridePath: $this->tempPath);
        $this->state = new LocalExtensionState($this->store, new OrbitExtensionRegistry);
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);

        putenv('ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS');
    });

    it('validates slugs through the extension registry', function (): void {
        expect(fn () => $this->state->enable('missing'))
            ->toThrow(InvalidArgumentException::class, 'Unknown Orbit extension [missing].');
    });

    it('rejects unknown slugs through enabled() before reading config state', function (): void {
        expect(fn () => $this->state->enabled('missing'))
            ->toThrow(InvalidArgumentException::class, 'Unknown Orbit extension [missing].');
    });

    it('rejects unknown slugs through enabled() even when ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS=1', function (): void {
        putenv('ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS=1');

        expect(fn () => $this->state->enabled('missing'))
            ->toThrow(InvalidArgumentException::class, 'Unknown Orbit extension [missing].');
    });

    it('enables and disables extensions through the config store', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $this->state->enable('cloudflare');

        expect($this->state->enabled('cloudflare'))
            ->toBeTrue()
            ->and($this->state->enabledSlugs())
            ->toBe(['cloudflare']);

        expect($this->state->disable('cloudflare'))->toBeTrue()->and($this->state->enabledSlugs())->toBeEmpty();
    });

    it('treats ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS=1 as all known extensions enabled', function (): void {
        unlink_orbit_test_file($this->tempPath);

        putenv('ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS=1');

        expect($this->state->enabledSlugs())
            ->toBe([
                'cloudflare',
                'codex',
                'solo',
            ])
            ->and($this->state->enabled('solo'))
            ->toBeTrue();
    });

    it('returns only known registry slugs from enabledSlugs() and ignores unknown stored slugs', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $this->store->save([
            'extensions' => [
                'enabled' => ['solo', 'missing', 'cloudflare', 'future-ext'],
            ],
        ]);

        expect($this->state->enabledSlugs())->toBe(['cloudflare', 'solo']);
    });
});
