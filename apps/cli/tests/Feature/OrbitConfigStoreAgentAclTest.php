<?php

declare(strict_types=1);

use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->tempPath = orbit_test_config_path(prefix: 'orbit-config-agent-acl-');
});

afterEach(function (): void {
    unlink_orbit_test_file($this->tempPath);
});

/**
 * @return Process
 */
function orbit_config_store_agent_acl_process(
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

/**
 * Focused ACL durability coverage; kept in one suite for shared process fakes.
 *
 * @mago-expect lint:halstead
 * @mago-expect lint:cyclomatic-complexity
 */
describe('OrbitConfigStore agent ACL durability', function (): void {
    it('re-applies directory and file agent ACLs after each atomic save when the agent user exists', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $directory = dirname($this->tempPath);
        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                $line = implode(' ', $command);

                if (($command[0] ?? null) === 'setfacl') {
                    $setfaclCalls[] = $line;

                    return orbit_config_store_agent_acl_process();
                }

                if (($command[0] ?? null) === 'getfacl') {
                    return orbit_config_store_agent_acl_process(
                        output: "user::rw-\nuser:agent:r--\ngroup::---\nmask::r--\nother::---\n",
                    );
                }

                return orbit_config_store_agent_acl_process(exitCode: 1);
            },
        );

        $store->save(['defaults' => ['node' => 'agent-1', 'profile' => null]]);
        $store->save(['defaults' => ['node' => 'agent-2', 'profile' => null]]);

        // Each save: directory u:agent:--x after chmod 0700, then file u:agent:r-- after rename.
        expect($setfaclCalls)
            ->toHaveCount(4)
            ->and($setfaclCalls[0])
            ->toBe('setfacl -m u:agent:--x '.$directory)
            ->and($setfaclCalls[1])
            ->toBe('setfacl -m u:agent:r-- '.$this->tempPath)
            ->and($setfaclCalls[2])
            ->toBe('setfacl -m u:agent:--x '.$directory)
            ->and($setfaclCalls[3])
            ->toBe('setfacl -m u:agent:r-- '.$this->tempPath)
            ->and($store->defaultNode())
            ->toBe('agent-2')
            ->and(fileperms($this->tempPath) & 0o777)
            ->toBe(OrbitConfigStore::FILE_MODE)
            ->and(fileperms($directory) & 0o777)
            ->toBe(OrbitConfigStore::DIRECTORY_MODE);
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

                return orbit_config_store_agent_acl_process(exitCode: 1);
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
        chmod($this->tempPath, permissions: 0o640);

        $chmodWouldBreak = false;
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$chmodWouldBreak): Process {
                if (($command[0] ?? null) === 'getfacl') {
                    return orbit_config_store_agent_acl_process(
                        output: "user::rw-\nuser:agent:r--\t#effective:r--\ngroup::---\nmask::r--\nother::---\n",
                    );
                }

                if (($command[0] ?? null) === 'setfacl') {
                    $chmodWouldBreak = true;

                    return orbit_config_store_agent_acl_process();
                }

                return orbit_config_store_agent_acl_process(exitCode: 1);
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
        chmod($this->tempPath, permissions: 0o640);

        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => false,
            processRunner: static fn (array $command): Process => orbit_config_store_agent_acl_process(
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
        chmod($this->tempPath, permissions: 0o644);

        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                if (($command[0] ?? null) === 'setfacl') {
                    $setfaclCalls[] = implode(' ', $command);

                    return orbit_config_store_agent_acl_process();
                }

                return orbit_config_store_agent_acl_process(exitCode: 1);
            },
        );

        $store->read();

        expect(fileperms($this->tempPath) & 0o777)
            ->toBe(OrbitConfigStore::FILE_MODE)
            ->and($setfaclCalls)
            ->toBe(['setfacl -m u:agent:r-- '.$this->tempPath]);
    });

    it('fails closed when agent directory ACL re-apply fails after chmod on an agent node', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $directory = dirname($this->tempPath);
        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                if (($command[0] ?? null) !== 'setfacl') {
                    return orbit_config_store_agent_acl_process(exitCode: 1);
                }

                $setfaclCalls[] = implode(' ', $command);

                // Directory traversal restore fails closed before the file write path.
                if (($command[2] ?? null) === OrbitConfigStore::AGENT_CONFIG_DIRECTORY_ACL_SPEC) {
                    return orbit_config_store_agent_acl_process(exitCode: 1, errorOutput: 'setfacl directory failed');
                }

                return orbit_config_store_agent_acl_process();
            },
        );

        expect(fn () => $store->save(['defaults' => ['node' => null, 'profile' => null]]))
            ->toThrow(OrbitConfigStoreException::class, 'Failed to re-apply agent directory ACL')
            ->and($setfaclCalls)
            ->toBe(['setfacl -m u:agent:--x '.$directory])
            ->and(is_file($this->tempPath))
            ->toBeFalse();
    });

    it('fails closed when agent file ACL re-apply fails after save on an agent node', function (): void {
        unlink_orbit_test_file($this->tempPath);

        $directory = dirname($this->tempPath);
        $setfaclCalls = [];
        $store = new OrbitConfigStore(
            overridePath: $this->tempPath,
            agentUserExists: static fn (): bool => true,
            processRunner: function (array $command) use (&$setfaclCalls): Process {
                if (($command[0] ?? null) !== 'setfacl') {
                    return orbit_config_store_agent_acl_process(exitCode: 1);
                }

                $setfaclCalls[] = implode(' ', $command);

                if (($command[2] ?? null) === OrbitConfigStore::AGENT_CONFIG_DIRECTORY_ACL_SPEC) {
                    return orbit_config_store_agent_acl_process();
                }

                return orbit_config_store_agent_acl_process(exitCode: 1, errorOutput: 'setfacl file failed');
            },
        );

        expect(fn () => $store->save(['defaults' => ['node' => null, 'profile' => null]]))
            ->toThrow(OrbitConfigStoreException::class, 'Failed to re-apply agent read ACL')
            ->and($setfaclCalls)
            ->toBe([
                'setfacl -m u:agent:--x '.$directory,
                'setfacl -m u:agent:r-- '.$this->tempPath,
            ]);
    });
});
