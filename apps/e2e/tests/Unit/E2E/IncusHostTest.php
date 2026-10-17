<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

function incusCommand(string ...$arguments): array
{
    return ['incus', '--project', 'orbit', ...$arguments];
}

function incusTarget(string $identity = ''): string
{
    return 'lab:'.$identity;
}

/** @return list<string> */
function guestIpv4Probe(): array
{
    return [
        'sh',
        '-c',
        'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ] && ip -4 -o addr show dev "$interface" scope global',
    ];
}

function isIncusGuestBatchHelper(PendingProcess $process): bool
{
    return
        is_array($process->command)
        && count($process->command) === 2
        && ($process->command[0] ?? null) === 'python3'
        && is_string($process->command[1] ?? null)
        && str_ends_with($process->command[1], '/resources/host/exec-all.py');
}

function isDirectIncusGuestCommand(PendingProcess $process): bool
{
    return
        is_array($process->command)
        && ($process->command[0] ?? null) === 'incus'
        && in_array('exec', $process->command, true);
}

/** @return array<string, array{instance:string, command:GuestCommand}> */
function incusGuestBatchCommands(): array
{
    return [
        'first' => [
            'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'command' => new GuestCommand(['probe-one']),
        ],
        'second' => [
            'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'command' => new GuestCommand(['probe-two']),
        ],
    ];
}

/**
 * @param  callable(array{label:string,instance:string,argv:list<string>}): array{stdout:string,stderr:string,exit_code:int}  $result
 */
function incusGuestHelperResult(PendingProcess $process, callable $result): ProcessResult
{
    $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($payload['requests'] as $request) {
        $results[] = ['label' => $request['label'], ...$result($request)];
    }

    return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
}

function vmJson(
    string $name = 'orbit-e2e-tst-123-aaaaaaaa-gateway',
    string $owner = 'orbit-e2e',
    string $type = 'virtual-machine',
    string $pool = IncusHost::POOL,
    ?string $network = null,
    ?string $mac = null,
    ?string $ipv4 = null,
): string {
    $devices = ['root' => ['pool' => $pool]];
    if ($network !== null || $mac !== null || $ipv4 !== null) {
        $devices['eth0'] = array_filter(
            [
                'network' => $network,
                'hwaddr' => $mac,
                'ipv4.address' => $ipv4,
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }

    return json_encode([[
        'name' => $name,
        'type' => $type,
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => ['user.orbit.e2e.owner' => $owner],
        'devices' => $devices,
    ]], JSON_THROW_ON_ERROR);
}

/** @return array<array-key, mixed> */
function vmResource(): array
{
    return json_decode(
        vmJson(network: 'oe-b32d6c83af72', mac: '00:16:3e:5e:4b:52'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    )[0];
}

function incusHost(?SecretRedactor $redactor = null, int $guestReadinessTimeoutSeconds = 600): IncusHost
{
    return new IncusHost(
        remote: 'lab',
        project: 'orbit',
        redactor: $redactor ?? new SecretRedactor,
        guestReadinessTimeoutSeconds: $guestReadinessTimeoutSeconds,
    );
}

/**
 * @return array{name:string,network:string,node:string,address:int,slot:int,memory:string,metadata:array<string,string>}
 */
function incusGuestSpec(string $node, int $address, string $memory = '2GiB'): array
{
    return [
        'name' => "orbit-e2e-tst-123-aaaaaaaa-{$node}",
        'network' => 'oe-b32d6c83af72',
        'node' => $node,
        'address' => $address,
        'slot' => 30,
        'memory' => $memory,
        'metadata' => ['user.orbit.e2e.operation' => 'op-1'],
    ];
}

/** @return array<string, array<array-key, mixed>> Node key => the instance resource that matches the target. */
function topologyIdentityResources(TopologyTarget $target, int $slot): array
{
    $resources = [];
    foreach ($target->recipe->nodes as $node) {
        $resources[$node->key] = json_decode(
            vmJson(
                $target->instance($node->key),
                network: $target->network(),
                mac: $target->mac($node->key),
                ipv4: TopologyTarget::ipv4For($slot, $node->address),
            ),
            true,
            16,
            JSON_THROW_ON_ERROR,
        )[0];
    }

    return $resources;
}

/** @param array<string, array<array-key, mixed>> $resources */
function fakeTopologyIdentity(TopologyTarget $target, array $resources, string $owner = 'orbit-e2e'): void
{
    Process::fake(function (PendingProcess $process) use ($target, $resources, $owner) {
        return match ($process->command) {
            incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([[
                'name' => $target->network(),
                'config' => ['user.orbit.e2e.owner' => $owner, 'ipv4.address' => '10.232.30.1/24'],
            ]], JSON_THROW_ON_ERROR)),
            incusCommand('list', incusTarget(), '--format=json') => Process::result(
                json_encode(array_values($resources), JSON_THROW_ON_ERROR),
            ),
            default => Process::result('', 'Unexpected command.', 1),
        };
    });
}

describe('IncusHost reads', function () {
    it('decodes the same valid VM device shapes through exact and batch reads', function (bool $expanded): void {
        $resource = vmResource();
        if ($expanded) {
            $resource['expanded_devices'] = $resource['devices'];
            $resource['devices'] = [];
        }
        Process::fake(['*' => Process::result(json_encode([$resource], JSON_THROW_ON_ERROR))]);
        $name = 'orbit-e2e-tst-123-aaaaaaaa-gateway';
        $host = incusHost();

        $exact = $host->instance($name);
        $batch = $host->instances([$name]);

        expect($exact)
            ->toEqual($batch[$name])
            ->and($exact?->pool)
            ->toBe(IncusHost::POOL)
            ->and($exact?->network)
            ->toBe('oe-b32d6c83af72')
            ->and($exact?->mac)
            ->toBe('00:16:3e:5e:4b:52');
    })->with([
        'local devices' => false,
        'expanded devices' => true,
    ]);

    it('rejects the same malformed VM shapes through exact and batch reads', function (
        string $field,
        mixed $value,
        string $exactMessage,
        string $batchMessage,
    ): void {
        $resource = vmResource();
        match ($field) {
            'type' => $resource['type'] = $value,
            'pool' => $resource['devices']['root']['pool'] = $value,
            'status' => $resource['status'] = $value,
            'status_code' => $resource['status_code'] = $value,
            'network' => $resource['devices']['eth0']['network'] = $value,
            'mac' => $resource['devices']['eth0']['hwaddr'] = $value,
        };
        Process::fake(['*' => Process::result(json_encode([$resource], JSON_THROW_ON_ERROR))]);
        $name = 'orbit-e2e-tst-123-aaaaaaaa-gateway';
        $host = incusHost();

        expect(fn () => $host->instance($name))
            ->toThrow(RuntimeException::class, $exactMessage)
            ->and(fn () => $host->instances([$name]))
            ->toThrow(RuntimeException::class, $batchMessage);
    })->with([
        'unknown type' => ['type', 'unknown', 'not a virtual machine', 'identity is not a virtual machine'],
        'missing pool' => ['pool', null, 'no storage pool identity', 'identity is invalid'],
        'wrong pool' => ['pool', 'other', 'storage pool identity does not match', 'identity is invalid'],
        'retired harness pool' => ['pool', 'orbit-e2e', 'storage pool identity does not match', 'identity is invalid'],
        'missing status' => ['status', null, 'no valid power status', 'identity is invalid'],
        'invalid status code' => ['status_code', '102', 'no valid power status', 'identity is invalid'],
        'invalid network' => ['network', 12, 'invalid network identity', 'network identity is invalid'],
        'invalid MAC' => ['mac', 12, 'invalid MAC identity', 'MAC identity is invalid'],
    ]);

    it('keeps exact lookup semantics while rejecting duplicate batch inventory rows', function (): void {
        $resource = vmResource();
        Process::fake(['*' => Process::result(json_encode([$resource, $resource], JSON_THROW_ON_ERROR))]);
        $name = 'orbit-e2e-tst-123-aaaaaaaa-gateway';
        $host = incusHost();

        expect($host->instance($name))
            ->toBeInstanceOf(IncusInstance::class)
            ->and(fn () => $host->instances([$name]))
            ->toThrow(RuntimeException::class, 'appears more than once in inventory');
    });

    it('resolves default-route dev tokens and stops before chained commands when none exists', function (
        string $routes,
        string $expectedOutput,
        int $expectedExitCode,
    ) {
        $constant = new ReflectionClass(IncusHost::class)->getReflectionConstant(
            'DEFAULT_ROUTE_INTERFACE_RESOLUTION',
        );
        expect($constant)->not->toBeFalse();
        $resolver = $constant->getValue();
        assert(is_string($resolver));
        $script = 'ip() { printf \'%s\\n\' "$ROUTE_OUTPUT"; }; '.$resolver.' && printf \'resolved:%s\' "$interface"';
        $process = new SymfonyProcess(['/bin/sh', '-c', $script], env: ['ROUTE_OUTPUT' => $routes]);

        $process->run();

        expect($process->getExitCode())
            ->toBe($expectedExitCode)
            ->and($process->getOutput())
            ->toBe($expectedOutput);
    })->with([
        'gateway route' => ['default via 192.0.2.1 dev enp5s0 proto dhcp', 'resolved:enp5s0', 0],
        'on-link route' => ['default dev enp5s0 scope link', 'resolved:enp5s0', 0],
        'first default route' => [
            "default via 192.0.2.1 dev enp5s0\ndefault via 198.51.100.1 dev eth9 metric 100",
            'resolved:enp5s0',
            0,
        ],
        'missing dev token' => ['default via 192.0.2.1 proto dhcp', '', 1],
        'missing interface token' => ['default dev', '', 1],
    ]);

    it('reads an exact instance set from one inventory request', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if (
                array_filter(
                    $process->command,
                    static fn (mixed $part): bool => is_string($part)
                    && str_ends_with($part, 'exec-all.py'),
                ) !== []
            ) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);

                return Process::result(json_encode(array_map(static fn (array $request): array => [
                    'label' => $request['label'],
                    'stdout' => "ready\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ], $payload['requests']), JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-other-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result('', 'Unexpected command.', 1);
        });

        $instances = incusHost()->instances([
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'orbit-e2e-tst-123-aaaaaaaa-app-dev',
        ]);

        expect(array_keys($instances))
            ->toBe(['orbit-e2e-tst-123-aaaaaaaa-gateway', 'orbit-e2e-tst-123-aaaaaaaa-app-dev'])
            ->and($reads)
            ->toBe(1);
    });

    it('reads all network identities and configurations in one inventory request', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('network', 'list', incusTarget(), '--format=json')) {
                return Process::result('', 'Unexpected command.', 1);
            }

            return Process::result(json_encode([
                [
                    'name' => 'oe-b32d6c83af72',
                    'config' => ['ipv4.address' => '10.232.30.1/24'],
                ],
                [
                    'name' => 'oe-9c9ad027b058',
                    'config' => ['ipv4.address' => '10.232.31.1/24'],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $networks = incusHost()->networks();

        expect(array_keys($networks))
            ->toBe(['oe-b32d6c83af72', 'oe-9c9ad027b058'])
            ->and($networks['oe-b32d6c83af72']->config['ipv4.address'])
            ->toBe('10.232.30.1/24');
    });

    it('reads guest IPv4 addresses in one owned inventory and one parallel probe phase', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-prod'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return incusGuestHelperResult($process, static function (array $request): array {
                expect($request['argv'])->toBe(guestIpv4Probe());
                $address = match (true) {
                    str_contains($request['instance'], 'gateway') => '192.0.2.10',
                    str_contains($request['instance'], 'app-dev') => '192.0.2.11',
                    default => '192.0.2.12',
                };

                return [
                    'stdout' => "2: enp5s0 inet {$address}/24 scope global enp5s0\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ];
            });
        });

        $addresses = incusHost()->globalIpv4All([
            'gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'app-dev' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev',
            'app-prod' => 'orbit-e2e-tst-123-aaaaaaaa-app-prod',
        ]);

        expect($addresses)
            ->toBe([
                'gateway' => '192.0.2.10',
                'app-dev' => '192.0.2.11',
                'app-prod' => '192.0.2.12',
            ])
            ->and($inventoryReads)
            ->toBe(1);
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    });

    it('reads the default-route topology NIC without assuming its interface name', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => "2: enp5s0    inet 192.0.2.44/24 scope global enp5s0\n",
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });

        expect(incusHost()->globalIpv4All(['gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway']))->toBe(['gateway' => '192.0.2.44']);
    });

    it('fails when the IPv4 probe fails', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => '',
                'stderr' => 'probe failed',
                'exit_code' => 1,
            ]);
        });
        expect(fn () => incusHost()->globalIpv4All(['gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway']))
            ->toThrow(RuntimeException::class, 'Failed to read global IPv4 address');
    });

    it('fails when no usable IPv4 address exists', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => '',
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });
        expect(fn () => incusHost()->globalIpv4All(['gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway']))
            ->toThrow(RuntimeException::class, 'has no usable global IPv4 address');
    });

    it('fails closed when the default-route NIC has more than one IPv4 address', function () {
        Process::fake(function (PendingProcess $process) {
            if (in_array('list', $process->command, true)) {
                return Process::result(vmJson());
            }

            return incusGuestHelperResult($process, static fn (): array => [
                'stdout' => "2: enp5s0    inet 192.0.2.44/24 scope global enp5s0\n"
                        ."2: enp5s0    inet 198.51.100.44/24 scope global enp5s0\n",
                'stderr' => '',
                'exit_code' => 0,
            ]);
        });

        expect(fn () => incusHost()->globalIpv4All(['gateway' => 'orbit-e2e-tst-123-aaaaaaaa-gateway']))
            ->toThrow(RuntimeException::class, 'does not have exactly one usable global IPv4 address');
    });

    it('rejects a loopback address returned by the default-route probe', function () {
        $host = incusHost();
        $method = new ReflectionMethod($host, 'hasUsableGlobalIpv4');

        expect($method->invoke($host, '1: lo inet 127.0.0.1/8'))->toBeFalse();
    });

    it('validates every guest of a composed recipe from one instance inventory', function () {
        $target = featureTarget('TST-123', recipe: TopologyRecipe::compose(['app-dev']));
        fakeTopologyIdentity($target, topologyIdentityResources($target, 30));

        incusHost()->assertTopologyNetworkIdentity($target);

        Process::assertRanTimes(incusCommand('list', incusTarget(), '--format=json'), 1);
    });

    it('rejects a guest whose identity does not match its recipe Node', function (
        string $node,
        string $field,
        string $value,
        string $message,
    ) {
        $target = featureTarget('TST-123', recipe: TopologyRecipe::compose(['app-dev']));
        $resources = topologyIdentityResources($target, 30);
        if ($field === 'type') {
            $resources[$node]['type'] = $value;
        } else {
            $resources[$node]['devices']['eth0'][$field] = $value;
        }
        fakeTopologyIdentity($target, $resources);

        expect(fn () => incusHost()->assertTopologyNetworkIdentity($target))
            ->toThrow(RuntimeException::class, $message);
    })->with([
        'a guest as a container' => ['operator', 'type', 'container', 'type does not match topology'],
        'another network' => ['app-dev', 'network', 'oe-other', 'network identity does not match topology'],
        'another MAC' => ['gateway', 'hwaddr', '00:16:3e:00:00:00', 'MAC identity does not match topology'],
        'another Node address' => ['app-dev', 'ipv4.address', '10.232.30.12', 'IPv4 identity does not match topology'],
    ]);

    it('rejects a composed topology with a missing guest', function () {
        $target = featureTarget('TST-123', recipe: TopologyRecipe::compose(['app-dev']));
        $resources = topologyIdentityResources($target, 30);
        unset($resources['operator']);
        fakeTopologyIdentity($target, $resources);

        expect(fn () => incusHost()->assertTopologyNetworkIdentity($target))
            ->toThrow(RuntimeException::class, 'Incus instance '.$target->instance('operator').' does not exist.');
    });

    it('rejects a stopped guest when the topology must be running', function () {
        $target = featureTarget('TST-123', recipe: TopologyRecipe::compose(['app-dev']));
        fakeTopologyIdentity($target, topologyIdentityResources($target, 30));

        expect(fn () => incusHost()->assertTopologyNetworkIdentity($target, requireRunning: true))
            ->toThrow(RuntimeException::class, 'Incus instance '.$target->instance('gateway').' is not running.');
    });

    it('rejects topology identity on a network that Orbit does not own', function () {
        $target = featureTarget('TST-123', recipe: TopologyRecipe::compose(['app-dev']));
        fakeTopologyIdentity($target, topologyIdentityResources($target, 30), 'someone-else');

        expect(fn () => incusHost()->assertTopologyNetworkIdentity($target))
            ->toThrow(RuntimeException::class, 'network '.$target->network().' ownership metadata does not match');
    });

    it('reads exact VM and network identities as JSON', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-tst-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $host = incusHost();
        $instance = $host->instance('orbit-e2e-tst-123-aaaaaaaa-gateway');

        expect($instance)
            ->toBeInstanceOf(IncusInstance::class)
            ->and($instance?->pool)
            ->toBe(IncusHost::POOL)
            ->and($instance?->isStopped())
            ->toBeTrue()
            ->and($host->network('orbit-e2e-tst-123')?->name)
            ->toBe('orbit-e2e-tst-123');
    });

    it('returns null when an exact identity is absent', function () {
        Process::fake([
            '*' => Process::result(json_encode([
                ['name' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev', 'type' => 'virtual-machine'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        expect(incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))->toBeNull();
    });

    it('rejects malformed network configuration', function (array $configuration) {
        Process::fake([
            '*' => Process::result(json_encode([[
                'name' => 'orbit-e2e-tst-123',
                'config' => $configuration,
            ]], JSON_THROW_ON_ERROR)),
        ]);

        expect(fn () => incusHost()->network('orbit-e2e-tst-123'))
            ->toThrow(RuntimeException::class, 'Incus returned invalid resource configuration.');
    })->with([
        'numeric key' => [['ipv4.nat']],
        'non-string value' => [['ipv4.nat' => true]],
    ]);

    it('creates a guest with an integer CPU limit and the memory its spec names', function () {
        app()->instance('config', new Repository([
            'e2e' => [
                'incus' => [
                    'cpu' => 2,
                ],
            ],
        ]));
        try {
            Process::fake(['*' => Process::result()]);

            incusHost()->createGuests(['operator' => incusGuestSpec('operator', 14, memory: '1GiB')]);

            Process::assertRan(static fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'copy'
                && in_array('limits.cpu=2', $process->command, true)
                && in_array('limits.memory=1GiB', $process->command, true));
        } finally {
            app()->forgetInstance('config');
        }
    });

    it('rejects a CPU limit that is not a number', function () {
        app()->instance('config', new Repository([
            'e2e' => ['incus' => ['cpu' => ['1']]],
        ]));
        try {
            expect(fn () => incusHost()->createGuests(['gateway' => incusGuestSpec('gateway', 10)]))
                ->toThrow(InvalidArgumentException::class, 'Incus CPU limit must be a positive integer.');
        } finally {
            app()->forgetInstance('config');
        }
    });

    it('rejects creation metadata that overrides ownership', function () {
        Process::fake();

        expect(fn () => incusHost()->createGuests(['gateway' => [
            ...incusGuestSpec('gateway', 10),
            'metadata' => ['user.orbit.e2e.owner' => 'attacker'],
        ]]))->toThrow(RuntimeException::class, 'cannot override ownership metadata');
        Process::assertNothingRan();
    });

    it('rejects missing or contradictory VM power status', function () {
        Process::fake(['*' => Process::result(str_replace('"status_code":102', '"status_code":103', vmJson()))]);

        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(InvalidArgumentException::class, 'power status');

        Process::fake(['*' => Process::result(str_replace(
            ['"status":"Stopped",', '"status_code":102,'],
            '',
            vmJson(),
        ))]);

        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'no valid power status');
    });

    it('rejects malformed JSON and wrong instance types', function () {
        Process::fake(['*' => Process::result('{')]);
        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'malformed JSON');

        Process::fake(['*' => Process::result(vmJson(type: 'unknown'))]);
        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'not a virtual machine');
    });

    it('rejects an instance from a different storage pool', function () {
        Process::fake(['*' => Process::result(vmJson(pool: 'unrelated'))]);

        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'storage pool identity does not match');
    });
});

describe('IncusHost network creation', function () {
    it('rejects names longer than the Linux bridge interface limit before invoking Incus', function () {
        $name = 'orbit-e2e-network-too-long';

        expect(fn () => incusHost()->createNetwork($name, []))
            ->toThrow(RuntimeException::class, 'Incus network names must be 15 ASCII characters or fewer.');

        Process::assertNothingRan();
    });
});

describe('IncusHost mutations', function () {
    it('rejects duplicate guest targets before starting any process', function () {
        Process::fake();
        $guest = incusGuestSpec('gateway', 10);

        expect(fn () => incusHost()->createGuests(['one' => $guest, 'two' => [...$guest, 'node' => 'app-dev']]))
            ->toThrow(RuntimeException::class, 'targets must be unique');
        Process::assertNothingRan();
    });

    it('force-stops every running target when graceful pool throws', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(json_encode([
                    json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            ['"status":"Running"', '"status_code":103'],
                            vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'),
                        ),
                        true,
                    )[0],
                    json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            ['"status":"Running"', '"status_code":103'],
                            vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'),
                        ),
                        true,
                    )[0],
                ], JSON_THROW_ON_ERROR));
            }
            if (in_array('stop', $process->command, true) && ! in_array('--force', $process->command, true)) {
                throw new RuntimeException('graceful pool timeout');
            }

            return Process::result();
        });
        incusHost()->stopAll(['orbit-e2e-tst-123-aaaaaaaa-gateway', 'orbit-e2e-tst-123-aaaaaaaa-app-dev']);
        Process::assertRan(incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--force'));
        Process::assertRan(incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-app-dev'), '--force'));
    });

    it('copies every guest from the template, then adds each NIC with its fixed network identity', function () {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result();
        });

        $instances = incusHost()->createGuests([
            'gateway' => [...incusGuestSpec('gateway', 10), 'config' => ['cloud-init.user-data' => "#cloud-config\nusers: []\n"]],
            'operator' => incusGuestSpec('operator', 14, memory: '1GiB'),
        ]);

        $copy = static fn (string $node, string $memory, string ...$config): array => incusCommand(
            'copy',
            incusTarget(IncusHost::TEMPLATE),
            incusTarget("orbit-e2e-tst-123-aaaaaaaa-{$node}"),
            '--config',
            'limits.cpu=1',
            '--config',
            "limits.memory={$memory}",
            '--config',
            'user.orbit.e2e.owner=orbit-e2e',
            '--config',
            'user.orbit.e2e.operation=op-1',
            ...$config,
        );
        $nic = static fn (string $node, string $ipv4): array => incusCommand(
            'config',
            'device',
            'add',
            incusTarget("orbit-e2e-tst-123-aaaaaaaa-{$node}"),
            'eth0',
            'nic',
            'network=oe-b32d6c83af72',
            "ipv4.address={$ipv4}",
            'hwaddr='.TopologyTarget::macFor('oe-b32d6c83af72', $node),
        );
        expect(array_slice($commands, 0, 2))
            ->toEqualCanonicalizing([
                $copy('gateway', '2GiB', '--config', "cloud-init.user-data=#cloud-config\nusers: []\n"),
                $copy('operator', '1GiB'),
            ])
            ->and(array_slice($commands, 2))
            ->toEqualCanonicalizing([$nic('gateway', '10.232.30.10'), $nic('operator', '10.232.30.14')])
            ->and(array_filter($commands, static fn (array $command): bool => array_intersect(['init', '--vm', '--storage', '--device', 'shift=true'], $command) !== []))
            ->toBe([])
            ->and(array_keys($instances))
            ->toBe(['gateway', 'operator'])
            ->and($instances['operator']->pool)
            ->toBe(IncusHost::POOL)
            ->and($instances['operator']->network)
            ->toBe('oe-b32d6c83af72')
            ->and($instances['operator']->metadata)
            ->toBe(['user.orbit.e2e.owner' => 'orbit-e2e', 'user.orbit.e2e.operation' => 'op-1'])
            ->and($instances['operator']->disks)
            ->toBe([]);
    });

    it('refuses guest configuration outside the allowed keys before copying', function () {
        Process::fake();

        expect(fn () => incusHost()->createGuests(['gateway' => [
            ...incusGuestSpec('gateway', 10),
            'config' => ['security.privileged' => 'true'],
        ]]))->toThrow(RuntimeException::class, 'Incus guest configuration [security.privileged] is not allowed.');
        Process::assertNothingRan();
    });

    it('refuses an empty guest batch', function () {
        Process::fake();

        expect(fn () => incusHost()->createGuests([]))
            ->toThrow(RuntimeException::class, 'Incus guest creation batch must be non-empty.');
        Process::assertNothingRan();
    });

    it('adds the worktree mount after every guest has its NIC', function () {
        $worktree = temporaryPath('orbit-incus-mount-', 4);
        mkdir($worktree, 0700, true);
        $mount = ['device' => 'orbit-source', 'source' => $worktree, 'path' => '/home/orbit/orbit'];
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result();
        });

        $instances = incusHost()->createGuests([
            'app-dev' => [...incusGuestSpec('app-dev', 11), 'mount' => $mount],
            'operator' => [...incusGuestSpec('operator', 14), 'mount' => $mount],
        ]);

        $add = static fn (string $node): array => incusCommand(
            'config',
            'device',
            'add',
            incusTarget("orbit-e2e-tst-123-aaaaaaaa-{$node}"),
            'orbit-source',
            'disk',
            "source={$worktree}",
            'path=/home/orbit/orbit',
        );
        expect(array_map(static fn (array $command): string => $command[3] === 'copy' ? 'copy '.$command[4] : 'config '.$command[7], $commands))
            ->toBe(['copy '.incusTarget(IncusHost::TEMPLATE), 'copy '.incusTarget(IncusHost::TEMPLATE), 'config eth0', 'config eth0', 'config orbit-source', 'config orbit-source'])
            ->and(array_slice($commands, 4))
            ->toEqualCanonicalizing([$add('app-dev'), $add('operator')])
            ->and($instances['app-dev']->disks)
            ->toBe(['orbit-source' => ['source' => $worktree, 'path' => '/home/orbit/orbit']]);
    });

    it('adds no NIC or mount when the copy batch fails', function () {
        $worktree = temporaryPath('orbit-incus-mount-', 4);
        mkdir($worktree, 0700, true);
        Process::fake(static fn (PendingProcess $process): ProcessResult => ($process->command[3] ?? null) === 'copy'
            ? Process::result('', 'Error: Instance not found', 1)
            : Process::result());

        expect(fn () => incusHost()->createGuests(['app-dev' => [
            ...incusGuestSpec('app-dev', 11),
            'mount' => ['device' => 'orbit-source', 'source' => $worktree, 'path' => '/home/orbit/orbit'],
        ]]))->toThrow(RuntimeException::class, 'Incus guest copy batch failed for app-dev: Error: Instance not found');
        Process::assertNotRan(static fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'config');
    });

    it('adds no mount when the NIC batch fails', function () {
        $worktree = temporaryPath('orbit-incus-mount-', 4);
        mkdir($worktree, 0700, true);
        Process::fake(static fn (PendingProcess $process): ProcessResult => in_array('eth0', $process->command, true)
            ? Process::result('', 'network not found', 1)
            : Process::result());

        expect(fn () => incusHost()->createGuests(['app-dev' => [
            ...incusGuestSpec('app-dev', 11),
            'mount' => ['device' => 'orbit-source', 'source' => $worktree, 'path' => '/home/orbit/orbit'],
        ]]))->toThrow(RuntimeException::class, 'Incus guest NIC batch failed for app-dev: network not found');
        Process::assertNotRan(static fn (PendingProcess $process): bool => in_array('orbit-source', $process->command, true));
    });

    it('rejects a non-positive guest readiness timeout', function () {
        expect(fn () => new IncusHost(guestReadinessTimeoutSeconds: 0))
            ->toThrow(InvalidArgumentException::class, 'Guest readiness timeout must be positive.');
    });

    it('does not start an already running owned VM', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json') => Process::result(
                    str_replace(
                        ['"status":"Stopped"', '"status_code":102'],
                        ['"status":"Running"', '"status_code":103'],
                        vmJson(),
                    ),
                ),
                default => Process::result('[]'),
            };
        });

        incusHost()->start('orbit-e2e-tst-123-aaaaaaaa-gateway');

        Process::assertNotRan(incusCommand('start', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')));
    });

    it('force-stops the exact owned VM when graceful stop fails', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--force') => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        incusHost()->stop('orbit-e2e-tst-123-aaaaaaaa-gateway');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json'),
            incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')),
            incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--force'),
        ]);
    });

    it('exposes the forced stop failure when graceful stop also fails', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--force') => Process::result(
                    '',
                    'forced stop failed',
                    1,
                ),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        expect(fn () => incusHost()->stop('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'forced stop failed');
    });

    it('force-stops only guests whose parallel graceful stop failed', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;

                return Process::result(json_encode(array_map(
                    static fn (string $name): array => json_decode(
                        str_replace(
                            ['"status":"Stopped"', '"status_code":102'],
                            $inventoryReads === 1 || $name === 'orbit-e2e-tst-123-aaaaaaaa-app-dev'
                                ? ['"status":"Running"', '"status_code":103']
                                : ['"status":"Stopped"', '"status_code":102'],
                            vmJson($name),
                        ),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    )[0],
                    ['orbit-e2e-tst-123-aaaaaaaa-gateway', 'orbit-e2e-tst-123-aaaaaaaa-app-dev'],
                ), JSON_THROW_ON_ERROR));
            }

            return match ($process->command) {
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')) => Process::result(),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-app-dev')) => Process::result(
                    '',
                    'graceful stop failed',
                    1,
                ),
                incusCommand('stop', incusTarget('orbit-e2e-tst-123-aaaaaaaa-app-dev'), '--force') => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        incusHost()->stopAll([
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'orbit-e2e-tst-123-aaaaaaaa-app-dev',
        ]);

        expect($inventoryReads)->toBe(2);
        Process::assertRan(incusCommand(
            'stop',
            incusTarget('orbit-e2e-tst-123-aaaaaaaa-app-dev'),
            '--force',
        ));
        Process::assertNotRan(incusCommand(
            'stop',
            incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
            '--force',
        ));
    });

    it('retries guest readiness after starting until the agent succeeds', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            return match ($process->command) {
                incusCommand('list', incusTarget(), '--format=json') => Process::result(
                    vmJson(),
                ),
                incusCommand('exec', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--', '/bin/true') => ++$probes
                    === 1
                        ? Process::result('', 'agent not ready', 1)
                        : Process::result(),
                default => Process::result('[]'),
            };
        });

        incusHost()->waitForAgents(['orbit-e2e-tst-123-aaaaaaaa-gateway']);

        expect($probes)->toBe(2);
        Process::assertRan(incusCommand(
            'exec',
            incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
            '--',
            '/bin/true',
        ));
    });

    it('retries guest readiness after a transient probe exception', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            if (
                $process->command === incusCommand(
                    'list',
                    incusTarget(),
                    '--format=json',
                )
            ) {
                return Process::result(vmJson());
            }

            if (++$probes === 1) {
                throw new RuntimeException('The guest agent probe timed out.');
            }

            return Process::result();
        });

        incusHost()->waitForAgents(['orbit-e2e-tst-123-aaaaaaaa-gateway']);

        expect($probes)->toBe(2);
    });

    it('fails at the readiness deadline when the guest readiness process cannot run', function () {
        $probes = 0;
        Process::fake(function (PendingProcess $process) use (&$probes) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            $probes++;
            throw new RuntimeException('incus unavailable');
        });

        expect(fn () => new IncusHost(
            remote: 'lab',
            project: 'orbit',
            guestReadinessTimeoutSeconds: 1,
        )
            ->waitForAgents(['orbit-e2e-tst-123-aaaaaaaa-gateway']))
            ->toThrow(RuntimeException::class, 'Timed out waiting for Incus guest agents')
            ->and($probes)
            ->toBe(1);
    });

    it('rejects empty and duplicate readiness instance lists before probing', function (array $instances) {
        expect(fn () => incusHost()->waitForAgents($instances))
            ->toThrow(RuntimeException::class, 'non-empty and unique');
        Process::assertNothingRan();
    })->with([
        'empty' => [[]],
        'duplicate' => [['orbit-e2e-tst-123-aaaaaaaa-gateway', 'orbit-e2e-tst-123-aaaaaaaa-gateway']],
    ]);

    it('waits for IPv4 on the guest default-route interface', function () {
        $instance = 'orbit-e2e-tst-123-aaaaaaaa-gateway';
        Process::fake(function (PendingProcess $process) use ($instance) {
            return match ($process->command) {
                incusCommand('list', incusTarget(), '--format=json') => Process::result(vmJson($instance)),
                incusCommand('exec', incusTarget($instance), '--', ...guestIpv4Probe()) => Process::result(
                    "2: enp5s0 inet 10.44.0.10/24 scope global enp5s0\n",
                ),
                default => Process::result('', 'unexpected command', 1),
            };
        });

        incusHost(guestReadinessTimeoutSeconds: 1)->waitForGlobalIpv4([$instance]);

        Process::assertRan(incusCommand('exec', incusTarget($instance), '--', ...guestIpv4Probe()));
    });

    it('passes guest stdin through process input without adding it to argv', function () {
        $observedInput = null;
        $secret = 'token='.bin2hex(random_bytes(8))."\n";
        Process::fake(function (PendingProcess $process) use (&$observedInput) {
            if (
                $process->command === incusCommand(
                    'list',
                    incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
                    '--format=json',
                )
            ) {
                return Process::result(vmJson());
            }
            $observedInput = $process->input;

            return Process::result();
        });

        incusHost()->exec(
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            new GuestCommand(['install', '/dev/stdin', '/tmp/config'], 60, $secret),
        );

        expect($observedInput)->toBe($secret);
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                is_array($process->command)
                && in_array('install', $process->command, true)
                && ! in_array($secret, $process->command, true)
            ),
        );
    });

    it('creates a network with argument arrays and ownership metadata', function () {
        Process::fake();

        $network = incusHost()->createNetwork('oe-b32d6c83af72', ['ipv4.address' => '10.232.30.1/24']);

        expect($network->name)
            ->toBe('oe-b32d6c83af72')
            ->and($network->metadata['user.orbit.e2e.owner'])
            ->toBe('orbit-e2e');
        Process::assertRan(incusCommand(
            'network',
            'create',
            incusTarget('oe-b32d6c83af72'),
            'ipv4.address=10.232.30.1/24',
            'user.orbit.e2e.owner=orbit-e2e',
        ));
    });

    it('re-reads and verifies ownership before destructive instance calls', function () {
        Process::fake([
            '*' => Process::sequence()
                ->push(vmJson())
                ->push(Process::result()),
        ]);

        incusHost()->deleteInstance('orbit-e2e-tst-123-aaaaaaaa-gateway');

        Process::assertRanInOrder([
            incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json'),
            incusCommand('delete', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')),
        ]);
    });

    it('deletes owned instances in parallel after one inventory read and verifies absence', function () {
        $inventoryReads = 0;
        Process::fake(function (PendingProcess $process) use (&$inventoryReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $inventoryReads++;
                if ($inventoryReads > 1) {
                    return Process::result('[]');
                }

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        incusHost()->deleteInstances([
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'orbit-e2e-tst-123-aaaaaaaa-app-dev',
        ]);

        expect($inventoryReads)->toBe(2);
        foreach (['orbit-e2e-tst-123-aaaaaaaa-gateway', 'orbit-e2e-tst-123-aaaaaaaa-app-dev'] as $instance) {
            Process::assertRan(incusCommand('delete', incusTarget($instance)));
        }
    });

    it('rejects an instance deletion batch before mutation when any owner does not match', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result('', 'Unexpected command.', 1);
            }

            return Process::result(json_encode([
                json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                json_decode(
                    vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev', owner: 'someone-else'),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => incusHost()->deleteInstances([
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            'orbit-e2e-tst-123-aaaaaaaa-app-dev',
        ]))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertNotRan(
            fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'delete',
        );
    });

    it('fails when a parallel instance deletion leaves an instance present', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result(vmJson());
            }

            return Process::result();
        });

        expect(fn () => incusHost()->deleteInstances(['orbit-e2e-tst-123-aaaaaaaa-gateway']))
            ->toThrow(RuntimeException::class, 'still exist after deletion: orbit-e2e-tst-123-aaaaaaaa-gateway');
    });

    it('validates ownership once before deleting a network', function () {
        $listCalls = 0;
        Process::fake(function (PendingProcess $process) use (&$listCalls) {
            if ($process->command === incusCommand('network', 'list', incusTarget(), '--format=json')) {
                $listCalls++;

                return Process::result(json_encode([[
                    'name' => 'orbit-e2e-tst-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('network', 'delete', incusTarget('orbit-e2e-tst-123'))) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 1);
        });

        incusHost()->deleteNetwork('orbit-e2e-tst-123');

        Process::assertRanInOrder([
            incusCommand('network', 'list', incusTarget(), '--format=json'),
            incusCommand('network', 'delete', incusTarget('orbit-e2e-tst-123')),
        ]);
        expect($listCalls)->toBe(1);
    });

    it('fails when Incus rejects network deletion', function () {
        Process::fake(function (PendingProcess $process) {
            return match ($process->command) {
                incusCommand('network', 'list', incusTarget(), '--format=json') => Process::result(json_encode([
                    ['name' => 'orbit-e2e-tst-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                ], JSON_THROW_ON_ERROR)),
                incusCommand('network', 'delete', incusTarget('orbit-e2e-tst-123')) => Process::result(
                    '',
                    'network is in use',
                    1,
                ),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        expect(fn () => incusHost()->deleteNetwork('orbit-e2e-tst-123'))
            ->toThrow(RuntimeException::class, 'Incus command failed');
    });

    it('reads identity from expanded devices when local devices are empty', function () {
        Process::fake(function (PendingProcess $process) {
            expect($process->command)
                ->toBe(incusCommand('list', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'), '--format=json'));

            return Process::result(json_encode([[
                'name' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => [],
                'expanded_devices' => [
                    'root' => ['type' => 'disk', 'pool' => IncusHost::POOL],
                    'eth0' => ['type' => 'nic', 'network' => 'oe-b32d6c83af72', 'hwaddr' => '00:16:3e:5e:4b:52'],
                ],
            ]], JSON_THROW_ON_ERROR));
        });

        $instance = incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway');

        expect($instance?->pool)
            ->toBe(IncusHost::POOL)
            ->and($instance?->network)
            ->toBe('oe-b32d6c83af72')
            ->and($instance?->mac)
            ->toBe('00:16:3e:5e:4b:52');
    });

    it('rejects ownership mismatch without destructive mutation', function () {
        Process::fake(['*' => Process::result(vmJson(owner: 'someone-else'))]);

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertDidntRun(incusCommand('delete', incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway')));
    });

    it('runs guest commands with their timeout and returns nonzero results', function () {
        Process::fake(function (PendingProcess $process) {
            return $process->command === incusCommand(
                'list',
                incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
                '--format=json',
            )
                ? Process::result(vmJson())
                : Process::result('stdout', 'stderr', 17);
        });

        $result = incusHost()->exec(
            'orbit-e2e-tst-123-aaaaaaaa-gateway',
            new GuestCommand(['sh', '-lc', 'exit 17'], 42),
        );

        expect($result->stdout)
            ->toBe("stdout\n")
            ->and($result->stderr)
            ->toBe("stderr\n")
            ->and($result->exitCode)
            ->toBe(17)
            ->and($result->successful())
            ->toBeFalse();
        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->command === incusCommand(
                    'exec',
                    incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
                    '--',
                    'sh',
                    '-lc',
                    'exit 17',
                )
                && $process->timeout === 42
            ),
        );
    });

    it('preserves labeled guest results and JSON requests through one helper process', function () {
        $instanceReads = 0;
        $helperPayload = null;
        Process::fake(function (PendingProcess $process) use (&$instanceReads, &$helperPayload) {
            if (isIncusGuestBatchHelper($process)) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
                $helperPayload = $payload;

                return Process::result(json_encode([
                    [
                        'label' => 'first',
                        'stdout' => "one\n",
                        'stderr' => "warning\n",
                        'exit_code' => 0,
                    ],
                    [
                        'label' => 'second',
                        'stdout' => "two\n",
                        'stderr' => "failed\n",
                        'exit_code' => 17,
                    ],
                    [
                        'label' => 'timeout',
                        'stdout' => "partial\n",
                        'stderr' => "timed out\n",
                        'exit_code' => 124,
                        'timed_out' => true,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
            if (($process->command[3] ?? null) === 'list') {
                $instanceReads++;

                return Process::result(vmJson());
            }

            return Process::result('', 'unexpected process', 99);
        });

        $results = incusHost()->execAll([
            'first' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'command' => new GuestCommand(['probe-one', 'literal;$(not-shell)'], 11, "payload\n"),
            ],
            'second' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'command' => new GuestCommand(['probe-two'], 17),
            ],
            'timeout' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'command' => new GuestCommand(['probe-timeout'], 5),
            ],
        ]);

        expect($instanceReads)
            ->toBe(1)
            ->and($helperPayload)
            ->toBe([
                'requests' => [
                    [
                        'label' => 'first',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
                        'argv' => ['probe-one', 'literal;$(not-shell)'],
                        'timeout' => 11,
                        'stdin' => "payload\n",
                    ],
                    [
                        'label' => 'second',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
                        'argv' => ['probe-two'],
                        'timeout' => 17,
                        'stdin' => null,
                    ],
                    [
                        'label' => 'timeout',
                        'project' => 'orbit',
                        'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
                        'argv' => ['probe-timeout'],
                        'timeout' => 5,
                        'stdin' => null,
                    ],
                ],
            ])
            ->and($results['first']->stdout)
            ->toBe("one\n")
            ->and($results['first']->stderr)
            ->toBe("warning\n")
            ->and($results['first']->successful())
            ->toBeTrue()
            ->and($results['second']->stdout)
            ->toBe("two\n")
            ->and($results['second']->stderr)
            ->toBe("failed\n")
            ->and($results['second']->exitCode)
            ->toBe(17)
            ->and($results['timeout']->stdout)
            ->toBe("partial\n")
            ->and($results['timeout']->stderr)
            ->toBe("timed out\n")
            ->and($results['timeout']->exitCode)
            ->toBe(124);
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
        Process::assertRan(
            fn (PendingProcess $process): bool => isIncusGuestBatchHelper($process) && $process->timeout === 27,
        );
    });

    it('fails closed on invalid guest helper label and result contracts', function (string $output) {
        Process::fake(function (PendingProcess $process) use ($output) {
            return isIncusGuestBatchHelper($process)
                ? Process::result($output)
                : Process::result(vmJson());
        });

        expect(fn () => incusHost()->execAll(incusGuestBatchCommands()))
            ->toThrow(RuntimeException::class, 'Incus guest command batch failed');
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    })->with([
        'malformed JSON' => ['{'],
        'non-list JSON' => [json_encode(['label' => 'first'], JSON_THROW_ON_ERROR)],
        'missing label' => [json_encode([[
            'label' => 'first',
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
        ]], JSON_THROW_ON_ERROR)],
        'duplicate label' => [json_encode([
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
        'unknown label' => [json_encode([
            ['label' => 'first', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'unknown', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
        'missing result field' => [json_encode([
            ['label' => 'first', 'stderr' => '', 'exit_code' => 0],
            ['label' => 'second', 'stdout' => '', 'stderr' => '', 'exit_code' => 0],
        ], JSON_THROW_ON_ERROR)],
    ]);

    it('reports a failed guest helper without starting serial guest processes', function () {
        Process::fake(function (PendingProcess $process) {
            return isIncusGuestBatchHelper($process)
                ? Process::result('', 'helper unavailable', 9)
                : Process::result(vmJson());
        });

        expect(fn () => incusHost()->execAll(incusGuestBatchCommands()))
            ->toThrow(RuntimeException::class, 'Batch helper failed: helper unavailable.');
        Process::assertRanTimes(isIncusGuestBatchHelper(...), 1);
        Process::assertNotRan(isDirectIncusGuestCommand(...));
    });

    it('starts all helper guest processes before the process barrier can pass', function () {
        $directory = temporaryPath('orbit-exec-all-', 6);
        mkdir($directory, 0700);
        $incus = $directory.'/incus';
        $barrier = $directory.'/barrier';
        symlink(realpath(__DIR__.'/../../Fixtures/Host/concurrent-incus.py'), $incus);
        $requests = [];
        foreach (range(1, 22) as $number) {
            $label = sprintf('probe-%02d', $number);
            $requests[] = [
                'label' => $label,
                'project' => 'orbit',
                'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
                'argv' => ['barrier', $label],
                'timeout' => 3,
                'stdin' => null,
            ];
        }
        $requests[] = [
            'label' => 'failure',
            'project' => 'orbit',
            'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
            'argv' => ['failure'],
            'timeout' => 3,
            'stdin' => null,
        ];
        $requests[] = [
            'label' => 'timeout',
            'project' => 'orbit',
            'instance' => 'lab:orbit-e2e-tst-123-aaaaaaaa-gateway',
            'argv' => ['timeout'],
            'timeout' => 1,
            'stdin' => null,
        ];

        try {
            $process = new SymfonyProcess(
                ['python3', realpath(__DIR__.'/../../../resources/host/exec-all.py')],
                env: [
                    'PATH' => $directory.':'.getenv('PATH'),
                    'ORBIT_E2E_EXEC_ALL_BARRIER' => $barrier,
                    'ORBIT_E2E_EXEC_ALL_EXPECTED' => '22',
                ],
                input: json_encode(['requests' => $requests], JSON_THROW_ON_ERROR),
                timeout: 10,
            );

            $process->run();
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $results = array_column($decoded, null, 'label');
            $barrierLabels = file($barrier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            expect($process->isSuccessful())
                ->toBeTrue()
                ->and($process->getErrorOutput())
                ->toBe('')
                ->and($decoded)
                ->toHaveCount(24)
                ->and($barrierLabels)
                ->toHaveCount(22)
                ->and(array_unique(array_column(array_slice($decoded, 0, 22), 'exit_code')))
                ->toBe([0])
                ->and($results['failure'])
                ->toBe([
                    'label' => 'failure',
                    'stdout' => "failure stdout\n",
                    'stderr' => "failure stderr\n",
                    'exit_code' => 17,
                ])
                ->and($results['timeout'])
                ->toBe([
                    'label' => 'timeout',
                    'stdout' => '',
                    'stderr' => '',
                    'exit_code' => 124,
                    'timed_out' => true,
                ]);
        } finally {
            if (is_link($incus)) {
                unlink($incus);
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            rmdir($directory);
        }
    });

    it('reuses one operation ownership inventory across guest batches', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if (
                array_filter(
                    $process->command,
                    static fn (mixed $part): bool => is_string($part)
                    && str_ends_with($part, 'exec-all.py'),
                ) !== []
            ) {
                $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);

                return Process::result(json_encode(array_map(static fn (array $request): array => [
                    'label' => $request['label'],
                    'stdout' => "ready\n",
                    'stderr' => '',
                    'exit_code' => 0,
                ], $payload['requests']), JSON_THROW_ON_ERROR));
            }
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $reads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result('ready');
        });
        $host = incusHost();
        $commands = [
            'gateway' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'command' => new GuestCommand(['true']),
            ],
            'app-dev' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev',
                'command' => new GuestCommand(['true']),
            ],
        ];

        $host->execAll($commands);
        $host->execAll($commands);

        expect($reads)->toBe(1);
    });

    it('reuses one ownership proof across repeated guest commands', function () {
        $reads = 0;
        Process::fake(function (PendingProcess $process) use (&$reads) {
            if (
                $process->command === incusCommand(
                    'list',
                    incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway'),
                    '--format=json',
                )
            ) {
                $reads++;

                return Process::result(vmJson());
            }

            return Process::result('ready');
        });
        $host = incusHost();

        $host->exec('orbit-e2e-tst-123-aaaaaaaa-gateway', new GuestCommand(['true']));
        $host->exec('orbit-e2e-tst-123-aaaaaaaa-gateway', new GuestCommand(['true']));

        expect($reads)->toBe(1);
    });

    it('pushes labeled guest files in one validated parallel batch', function () {
        $instanceReads = 0;
        Process::fake(function (PendingProcess $process) use (&$instanceReads) {
            if ($process->command === incusCommand('list', incusTarget(), '--format=json')) {
                $instanceReads++;

                return Process::result(json_encode([
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                    json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev'), true, 16, JSON_THROW_ON_ERROR)[0],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        incusHost()->pushFiles([
            'gateway-bundle' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'gateway-overlay' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/overlay.tar',
                'destination' => '/var/lib/orbit-e2e/source/overlay.tar',
            ],
            'app-dev-bundle' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
        ]);

        expect($instanceReads)->toBe(1);
        foreach ([
            incusCommand(
                'file',
                'push',
                '/tmp/source.bundle',
                incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway').'/var/lib/orbit-e2e/source/source.bundle',
            ),
            incusCommand(
                'file',
                'push',
                '/tmp/overlay.tar',
                incusTarget('orbit-e2e-tst-123-aaaaaaaa-gateway').'/var/lib/orbit-e2e/source/overlay.tar',
            ),
            incusCommand(
                'file',
                'push',
                '/tmp/source.bundle',
                incusTarget('orbit-e2e-tst-123-aaaaaaaa-app-dev').'/var/lib/orbit-e2e/source/source.bundle',
            ),
        ] as $command) {
            Process::assertRan($command);
        }
    });

    it('rejects invalid guest file batches before invoking Incus', function (array $files) {
        Process::fake();

        expect(fn () => incusHost()->pushFiles($files))
            ->toThrow(RuntimeException::class);

        Process::assertNothingRan();
    })->with([
        'empty' => [[]],
        'missing destination' => [[
            'source' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/source.bundle',
            ],
        ]],
        'relative destination' => [[
            'source' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => 'relative/source.bundle',
            ],
        ]],
    ]);

    it('reports each failed guest file push by label', function () {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[3] ?? null) === 'list') {
                return Process::result(vmJson());
            }

            return in_array('/tmp/overlay.tar', $process->command, true)
                ? Process::result('', 'push failed', 9)
                : Process::result();
        });

        expect(fn () => incusHost()->pushFiles([
            'bundle' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'overlay' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/overlay.tar',
                'destination' => '/var/lib/orbit-e2e/source/overlay.tar',
            ],
        ]))
            ->toThrow(RuntimeException::class, 'overlay: push failed');
    });

    it('validates every guest owner before starting a file push batch', function () {
        Process::fake(function (PendingProcess $process) {
            if ($process->command !== incusCommand('list', incusTarget(), '--format=json')) {
                return Process::result();
            }

            return Process::result(json_encode([
                json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0],
                json_decode(
                    vmJson('orbit-e2e-tst-123-aaaaaaaa-app-dev', owner: 'someone-else'),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => incusHost()->pushFiles([
            'gateway' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-gateway',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
            'app-dev' => [
                'instance' => 'orbit-e2e-tst-123-aaaaaaaa-app-dev',
                'source' => '/tmp/source.bundle',
                'destination' => '/var/lib/orbit-e2e/source/source.bundle',
            ],
        ]))
            ->toThrow(RuntimeException::class, 'ownership metadata does not match');

        Process::assertNotRan(
            fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'file',
        );
    });
});

describe('IncusHost failures', function () {
    it('reports nonzero exits without exposing secrets', function () {
        Process::fake(['*' => Process::result('', 'Bearer token-value', 9)]);

        try {
            incusHost(new SecretRedactor(['token-value']))->instance('orbit-e2e-tst-123-aaaaaaaa-gateway');
            $this->fail('Expected the Incus command to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('[REDACTED]')
                ->and(str_contains($exception->getMessage(), 'token-value'))
                ->toBeFalse();
        }
    });

    it('wraps timeouts and redacts their output', function () {
        Process::fake(function (): never {
            throw new RuntimeException('Timed out with password-value');
        });

        expect(fn () => incusHost(new SecretRedactor(['password-value']))->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, '[REDACTED]');
    });

    it('rejects unsafe public identities before running Incus', function () {
        Process::fake();

        expect(fn () => incusHost()->deleteInstance('orbit-e2e-*'))
            ->toThrow(RuntimeException::class, 'Invalid')
            ->and(fn () => incusHost()->createGuests(['gateway' => [
                ...incusGuestSpec('gateway', 10),
                'metadata' => ['user.other.owner' => 'orbit-e2e'],
            ]]))
            ->toThrow(RuntimeException::class, 'ownership metadata');
        Process::assertNothingRan();
    });

    it('rejects an unsafe or missing source mount before creating any guest', function (array $mount, string $message) {
        Process::fake();

        expect(fn () => incusHost()->createGuests(['gateway' => [...incusGuestSpec('gateway', 10), 'mount' => $mount]]))
            ->toThrow(RuntimeException::class, $message);
        Process::assertNothingRan();
    })->with([
        'relative source' => [
            ['device' => 'orbit-source', 'source' => 'relative', 'path' => '/home/orbit/orbit'],
            'mount source',
        ],
        'comma in source' => [
            ['device' => 'orbit-source', 'source' => '/tmp/a,b', 'path' => '/home/orbit/orbit'],
            'mount source',
        ],
        'newline in path' => [
            ['device' => 'orbit-source', 'source' => '/tmp', 'path' => "/home/orbit\n/orbit"],
            'mount path',
        ],
        'equals in path' => [
            ['device' => 'orbit-source', 'source' => '/tmp', 'path' => '/home/orbit=orbit'],
            'mount path',
        ],
        'symlinked source' => [
            ['device' => 'orbit-source', 'source' => '/proc/self/cwd', 'path' => '/home/orbit/orbit'],
            'existing directory',
        ],
        'missing directory' => [
            ['device' => 'orbit-source', 'source' => '/nonexistent/orbit-worktree', 'path' => '/home/orbit/orbit'],
            'existing directory',
        ],
        'root device' => [['device' => 'root', 'source' => '/tmp', 'path' => '/home/orbit/orbit'], 'device identity'],
        'bad device name' => [
            ['device' => 'orbit source', 'source' => '/tmp', 'path' => '/home/orbit/orbit'],
            'device identity',
        ],
    ]);

    it('reads every non-root disk device from the instance inventory', function () {
        $resource = json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0];
        $resource['devices']['orbit-source'] = [
            'type' => 'disk',
            'source' => '/srv/worktrees/tst-123',
            'path' => '/home/orbit/orbit',
        ];
        $resource['devices']['eth1'] = ['type' => 'nic', 'network' => 'oe-other'];
        Process::fake(['*' => Process::result(json_encode([$resource], JSON_THROW_ON_ERROR))]);

        $instance = incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway');
        $inventory = incusHost()->instances(['orbit-e2e-tst-123-aaaaaaaa-gateway']);

        expect($instance?->disks)
            ->toBe(['orbit-source' => ['source' => '/srv/worktrees/tst-123', 'path' => '/home/orbit/orbit']])
            ->and($instance?->disk('orbit-source'))
            ->toBe(['source' => '/srv/worktrees/tst-123', 'path' => '/home/orbit/orbit'])
            ->and($instance?->disk('missing'))
            ->toBeNull()
            ->and($inventory['orbit-e2e-tst-123-aaaaaaaa-gateway']->disks)
            ->toBe($instance?->disks);
    });

    it('rejects a disk device without an exact source and path', function () {
        $resource = json_decode(vmJson('orbit-e2e-tst-123-aaaaaaaa-gateway'), true, 16, JSON_THROW_ON_ERROR)[0];
        $resource['devices']['orbit-source'] = ['type' => 'disk', 'source' => '/srv/worktrees/tst-123'];
        Process::fake(['*' => Process::result(json_encode([$resource], JSON_THROW_ON_ERROR))]);

        expect(fn () => incusHost()->instance('orbit-e2e-tst-123-aaaaaaaa-gateway'))
            ->toThrow(RuntimeException::class, 'disk device orbit-source identity is invalid');
    });
});
