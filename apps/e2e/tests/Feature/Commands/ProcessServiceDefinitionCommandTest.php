<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('creates MySQL and Redis node service definitions through process commands on a prepared topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $definitions = [
        [
            'name' => "mysql8-{$suffix}",
            'definition' => 'mysql',
            'version_input' => '8',
            'version_family' => '8',
            'version' => '8.4',
            'command' => 'mysqld',
            'image' => 'mysql:8.4',
            'port' => 3308,
            'target_port' => 3306,
            'credential_fields' => ['database', 'password', 'username'],
            'healthcheck_command' => 'mysqladmin ping -horbit -porbit',
        ],
        [
            'name' => "mysql9-{$suffix}",
            'definition' => 'mysql',
            'version_input' => '9',
            'version_family' => '9',
            'version' => '9',
            'command' => 'mysqld',
            'image' => 'mysql:9',
            'port' => 3309,
            'target_port' => 3306,
            'credential_fields' => ['database', 'password', 'username'],
            'healthcheck_command' => 'mysqladmin ping -horbit -porbit',
        ],
        [
            'name' => "redis7-{$suffix}",
            'definition' => 'redis',
            'version_input' => '7',
            'version_family' => '7',
            'version' => '7.2',
            'command' => 'redis-server --appendonly yes --bind 0.0.0.0 --protected-mode no',
            'image' => 'redis:7.2',
            'port' => 6379,
            'target_port' => 6379,
            'credential_fields' => [],
            'healthcheck_command' => 'redis-cli ping',
        ],
    ];
    $names = array_column($definitions, 'name');

    try {
        e2eRestartGatewayApi($topology, 'process-service-definitions');
        processServiceDefinitionCommandCleanup($topology, $names);
        processServiceDefinitionCommandRemovePreparedRedis($topology);

        $serviceHost = processServiceDefinitionCommandNodeServiceHost($topology);

        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $runtimeUnit = "orbit-{$name}";
            $add = $topology->ssh(
                'gateway',
                "cd {$checkout} && orbit process:add ".escapeshellarg($name)
                    .' --node=app-dev-1'
                    .' --definition='.escapeshellarg($definition['definition'])
                    .' --definition-version='.escapeshellarg($definition['version_input'])
                    .' --runtime=docker-swarm'
                    .' --json',
                timeoutSeconds: 180,
                allowFailure: true,
            );

            if (! $add->successful()) {
                throw new RuntimeException(trim($add->output().$add->errorOutput()));
            }

            $addPayload = processServiceDefinitionCommandPayload($add->output());

            expect($addPayload['success']['data']['process'])->toMatchArray([
                'name' => $name,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime' => 'docker-swarm',
                'tool' => null,
            ])
                ->and($addPayload['success']['data']['runtime_units'][0])->toMatchArray([
                    'name' => $runtimeUnit,
                    'context' => 'node',
                ]);

            processServiceDefinitionCommandAssertWarnings($addPayload, $runtimeUnit);
        }

        $list = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:list --node=app-dev-1 --json",
            timeoutSeconds: 120,
            allowFailure: true,
        );

        if (! $list->successful()) {
            throw new RuntimeException(trim($list->output().$list->errorOutput()));
        }

        $listPayload = processServiceDefinitionCommandPayload($list->output());
        $processes = collect($listPayload['success']['data']['processes'])->keyBy('name');
        $snapshot = collect(processServiceDefinitionCommandSnapshot($topology, $names))->keyBy('name');

        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $runtimeUnit = "orbit-{$name}";
            $listed = $processes->get($name);
            $record = $snapshot->get($name);

            expect($listed)->toBeArray()
                ->and($listed)->toMatchArray([
                    'node' => 'app-dev-1',
                    'app' => null,
                    'workspace' => null,
                    'name' => $name,
                    'command' => $definition['command'],
                    'runtime' => 'docker-swarm',
                    'tool' => null,
                    'runtime_unit' => $runtimeUnit,
                    'service' => [
                        'definition' => $definition['definition'],
                        'version_family' => $definition['version_family'],
                        'version' => $definition['version'],
                        'service_name' => $runtimeUnit,
                        'endpoint' => [
                            'name' => $name,
                            'host' => $serviceHost,
                            'port' => $definition['port'],
                        ],
                        'endpoints' => [
                            [
                                'name' => $name,
                                'host' => $serviceHost,
                                'port' => $definition['port'],
                            ],
                        ],
                        'credential_fields' => $definition['credential_fields'],
                    ],
                ])
                ->and($listed['service'])->not->toHaveKey('credentials')
                ->and($record)->toMatchArray([
                    'name' => $name,
                    'command' => $definition['command'],
                    'runtime' => 'docker-swarm',
                    'tool' => null,
                    'runtime_config' => [
                        'definition' => $definition['definition'],
                        'version_family' => $definition['version_family'],
                        'version' => $definition['version'],
                        'image' => $definition['image'],
                        'service_name' => $runtimeUnit,
                        'healthcheck' => [
                            'kind' => 'command',
                            'command' => $definition['healthcheck_command'],
                        ],
                        'ports' => [
                            [
                                'published' => $definition['port'],
                                'target' => $definition['target_port'],
                                'protocol' => 'tcp',
                            ],
                        ],
                        'credential_fields' => $definition['credential_fields'],
                    ],
                ]);
        }
    } finally {
        processServiceDefinitionCommandCleanup($topology, $names);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function processServiceDefinitionCommandAssertWarnings(array $payload, string $runtimeUnit): void
{
    $warnings = $payload['success']['meta']['warnings'] ?? [];

    expect($warnings)->toBeArray();

    $unexpected = collect($warnings)
        ->reject(fn (mixed $warning): bool => is_array($warning)
            && ($warning['code'] ?? null) === 'process.runtime_unit_apply_failed'
            && str_contains((string) ($warning['message'] ?? ''), $runtimeUnit))
        ->values()
        ->all();

    expect($unexpected)->toBe([]);
}

function processServiceDefinitionCommandNodeServiceHost(E2ETopologyHarness $topology): string
{
    $result = processServiceDefinitionCommandRunGatewayTinker(
        $topology,
        <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
echo trim((string) $node->wireguard_address);
PHP,
    );
    $host = trim($result->output());

    if ($host === '') {
        throw new RuntimeException('Prepared app-dev node has no WireGuard service address.');
    }

    return $host;
}

/**
 * @param  list<string>  $names
 * @return list<array<string, mixed>>
 */
function processServiceDefinitionCommandSnapshot(E2ETopologyHarness $topology, array $names): array
{
    if ($names === []) {
        return [];
    }

    $script = str_replace('__NAMES__', json_encode(array_values($names), JSON_THROW_ON_ERROR), <<<'PHP'
$names = __NAMES__;
$rows = \App\Models\Process::query()
    ->whereIn('name', $names)
    ->get()
    ->map(function (\App\Models\Process $process): array {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $credentials = is_array($config['credentials'] ?? null) ? array_keys($config['credentials']) : [];

        sort($credentials);

        return [
            'name' => $process->name,
            'command' => $process->command,
            'runtime' => $process->runtime->value,
            'tool' => $process->tool,
            'runtime_config' => [
                'definition' => $config['definition'] ?? null,
                'version_family' => $config['version_family'] ?? null,
                'version' => $config['version'] ?? null,
                'image' => $config['image'] ?? null,
                'service_name' => $config['service_name'] ?? null,
                'healthcheck' => [
                    'kind' => $config['healthcheck']['kind'] ?? null,
                    'command' => $config['healthcheck']['command'] ?? null,
                ],
                'ports' => collect($config['ports'] ?? [])
                    ->map(fn (mixed $port): array => [
                        'published' => (int) ($port['published'] ?? 0),
                        'target' => (int) ($port['target'] ?? 0),
                        'protocol' => (string) ($port['protocol'] ?? ''),
                    ])
                    ->values()
                    ->all(),
                'credential_fields' => $credentials,
            ],
        ];
    })
    ->values()
    ->all();

echo json_encode($rows, JSON_THROW_ON_ERROR);
PHP);

    return json_decode(
        processServiceDefinitionCommandRunGatewayTinker($topology, $script)->output(),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function processServiceDefinitionCommandRemovePreparedRedis(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit process:remove redis --node=app-dev-1 --force --json >/dev/null 2>&1 || true",
        timeoutSeconds: 180,
        allowFailure: true,
    );

    $topology->ssh(
        'dev',
        'docker service rm orbit-redis >/dev/null 2>&1 || true; docker rm -f orbit-redis >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $script = <<<'PHP'
if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
    $node->processes()->where('name', 'redis')->delete();
}
PHP;

    processServiceDefinitionCommandRunGatewayTinker($topology, $script, allowFailure: true);
}

/**
 * @param  list<string>  $names
 */
function processServiceDefinitionCommandCleanup(E2ETopologyHarness $topology, array $names): void
{
    if ($names === []) {
        return;
    }

    $checkout = escapeshellarg($topology->checkout('gateway'));

    foreach ($names as $name) {
        $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove ".escapeshellarg($name).' --node=app-dev-1 --force --json >/dev/null 2>&1 || true',
            timeoutSeconds: 180,
            allowFailure: true,
        );
    }

    $runtimeUnits = implode(' ', array_map(
        fn (string $name): string => escapeshellarg("orbit-{$name}"),
        $names,
    ));

    $topology->ssh(
        'dev',
        "docker service rm {$runtimeUnits} >/dev/null 2>&1 || true; docker rm -f {$runtimeUnits} >/dev/null 2>&1 || true",
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $script = str_replace('__NAMES__', json_encode(array_values($names), JSON_THROW_ON_ERROR), <<<'PHP'
$names = __NAMES__;

if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
    $node->processes()->whereIn('name', $names)->delete();
}
PHP);

    processServiceDefinitionCommandRunGatewayTinker($topology, $script, allowFailure: true);
}

function processServiceDefinitionCommandRunGatewayTinker(E2ETopologyHarness $topology, string $script, bool $allowFailure = false): ProcessResult
{
    return e2eRunInRoleRuntime(
        $topology,
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 180,
        allowFailure: $allowFailure,
    );
}

/**
 * @return array<string, mixed>
 */
function processServiceDefinitionCommandPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}
