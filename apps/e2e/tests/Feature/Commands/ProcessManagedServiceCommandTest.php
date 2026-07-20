<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('creates MySQL and Valkey node managed services through process commands on a prepared topology', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $services = [
        [
            'name' => "mysql8-{$suffix}",
            'service' => 'mysql',
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
            'service' => 'mysql',
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
            'name' => "valkey8-{$suffix}",
            'service' => 'valkey',
            'version_input' => '8',
            'version_family' => '8',
            'version' => '8.1',
            'command' => 'valkey-server --instanceendonly yes --bind 0.0.0.0 --protected-mode no',
            'image' => 'valkey/valkey:8.1',
            'port' => 6379,
            'target_port' => 6379,
            'credential_fields' => [],
            'healthcheck_command' => 'valkey-cli ping',
        ],
    ];
    $names = array_column($services, 'name');

    try {
        e2eRestartGatewayApi($topology, 'process-managed-services');
        processManagedServiceCommandCleanup($topology, $names);
        process_managed_service_command_remove_prepared_valkey($topology);

        $serviceHost = processManagedServiceCommandNodeServiceHost($topology);

        foreach ($services as $service) {
            $name = $service['name'];
            $runtimeUnit = "orbit-{$name}";
            $add = $topology->ssh(
                'gateway',
                "cd {$checkout} && orbit process:add "
                .escapeshellarg($name)
                .' --node=app-dev-1'
                .' --service='
                .escapeshellarg($service['service'])
                .' --version='
                .escapeshellarg($service['version_input'])
                .' --runtime=docker-swarm'
                .' --json',
                timeoutSeconds: 180,
                allowFailure: true,
            );

            if (! $add->successful()) {
                throw new RuntimeException(trim($add->output().$add->errorOutput()));
            }

            $addPayload = processManagedServiceCommandPayload($add->output());

            expect($addPayload['success']['data']['process'])
                ->toMatchArray([
                    'name' => $name,
                    'node' => 'app-dev-1',
                    'project' => null,
                    'instance' => null,
                    'workspace' => null,
                    'runtime' => 'docker-swarm',
                    'tool' => null,
                ])
                ->and($addPayload['success']['data']['runtime_units'][0])
                ->toMatchArray([
                    'name' => $runtimeUnit,
                    'context' => 'node',
                ]);

            processManagedServiceCommandAssertWarnings($addPayload, $runtimeUnit);
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

        $listPayload = processManagedServiceCommandPayload($list->output());
        $processes = collect($listPayload['success']['data']['processes'])->keyBy('name');
        $snapshot = collect(processManagedServiceCommandSnapshot($topology, $names))->keyBy('name');

        foreach ($services as $service) {
            $name = $service['name'];
            $runtimeUnit = "orbit-{$name}";
            $listed = $processes->get($name);
            $record = $snapshot->get($name);

            expect($listed)
                ->toBeArray()
                ->and($listed)
                ->toMatchArray([
                    'node' => 'app-dev-1',
                    'project' => null,
                    'instance' => null,
                    'workspace' => null,
                    'name' => $name,
                    'command' => $service['command'],
                    'runtime' => 'docker-swarm',
                    'tool' => null,
                    'runtime_unit' => $runtimeUnit,
                    'service' => [
                        'service' => $service['service'],
                        'version_family' => $service['version_family'],
                        'version' => $service['version'],
                        'service_name' => $runtimeUnit,
                        'endpoint' => [
                            'name' => $name,
                            'host' => $serviceHost,
                            'port' => $service['port'],
                        ],
                        'endpoints' => [
                            [
                                'name' => $name,
                                'host' => $serviceHost,
                                'port' => $service['port'],
                            ],
                        ],
                        'credential_fields' => $service['credential_fields'],
                    ],
                ])
                ->and($listed['service'])
                ->not
                ->toHaveKey('credentials')
                ->and($record)
                ->toMatchArray([
                    'name' => $name,
                    'command' => $service['command'],
                    'runtime' => 'docker-swarm',
                    'tool' => null,
                    'runtime_config' => [
                        'service' => $service['service'],
                        'version_family' => $service['version_family'],
                        'version' => $service['version'],
                        'image' => $service['image'],
                        'service_name' => $runtimeUnit,
                        'healthcheck' => [
                            'kind' => 'command',
                            'command' => $service['healthcheck_command'],
                        ],
                        'ports' => [
                            [
                                'published' => $service['port'],
                                'target' => $service['target_port'],
                                'protocol' => 'tcp',
                            ],
                        ],
                        'credential_fields' => $service['credential_fields'],
                    ],
                ]);
        }
    } finally {
        processManagedServiceCommandCleanup($topology, $names);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('renames a managed Docker MySQL process while keeping database targets coherent', function (): void {
    process_managed_service_command_assert_rename_keeps_database_targets_coherent();
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function process_managed_service_command_assert_rename_keeps_database_targets_coherent(): void
{
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $oldName = "mysql-rename-{$suffix}";
    $newName = "mysql-renamed-{$suffix}";
    $app = "mysql-target-{$suffix}";
    $appPath = "/home/orbit/apps/{$app}";
    $connectionSlug = "mysql-conn-{$suffix}";

    try {
        e2eRestartGatewayApi($topology, 'process-managed-service-rename');
        processManagedServiceCommandCleanup($topology, [$oldName, $newName]);
        process_managed_service_command_seed_database_target_app($topology, $app, $appPath);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add "
            .escapeshellarg($oldName)
            .' --node=app-dev-1 --service=mysql --service-version=8 --runtime=docker --no-start --json',
            timeoutSeconds: 180,
            allowFailure: true,
        );

        if (! $add->successful()) {
            throw new RuntimeException(trim($add->output().$add->errorOutput()));
        }

        $addPayload = processManagedServiceCommandPayload($add->output());

        expect($addPayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $oldName,
                'node' => 'app-dev-1',
                'runtime' => 'docker',
            ])
            ->and($addPayload['success']['data']['runtime_units'][0])
            ->toMatchArray([
                'name' => $oldName,
                'context' => 'node',
            ]);

        $endpoint = process_managed_service_command_endpoint($topology, $oldName);
        process_managed_service_command_seed_database_connection(
            $topology,
            slug: $connectionSlug,
            app: $app,
            host: $endpoint['host'],
            port: $endpoint['port'],
        );
        process_managed_service_command_seed_stale_database_env($topology, $appPath, $oldName);

        $rename = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:update "
            .escapeshellarg($oldName)
            .' --node=app-dev-1 --name='
            .escapeshellarg($newName)
            .' --json',
            timeoutSeconds: 180,
            allowFailure: true,
        );

        if (! $rename->successful()) {
            throw new RuntimeException(trim($rename->output().$rename->errorOutput()));
        }

        $renamePayload = processManagedServiceCommandPayload($rename->output());

        expect($renamePayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $newName,
                'node' => 'app-dev-1',
                'runtime' => 'docker',
            ])
            ->and($renamePayload['success']['data']['old_name'])
            ->toBe($oldName)
            ->and($renamePayload['success']['data']['changed'])
            ->toContain('name')
            ->and($renamePayload['success']['data']['runtime_units'][0]['name'])
            ->toBe($newName);

        $containers = $topology->ssh(
            'dev',
            "docker ps -a --format '{{.Names}}'",
            timeoutSeconds: 60,
            allowFailure: true,
        )->output();

        expect(explode("\n", trim($containers)))
            ->toContain($newName)
            ->not->toContain($oldName);

        $storedConnection = process_managed_service_command_connection_snapshot($topology, $connectionSlug);

        expect($storedConnection)
            ->toMatchArray([
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
            ]);

        $restore = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit doctor --node=app-dev-1 --family=database_connection --restore --json",
            timeoutSeconds: 180,
            allowFailure: true,
        );

        if (! $restore->successful()) {
            throw new RuntimeException(trim($restore->output().$restore->errorOutput()));
        }

        $restoreData = e2eJsonCommandData(e2eJsonCommandPayload($restore->output()));

        expect($restoreData['doctor']['healthy'])
            ->toBeTrue(json_encode($restoreData, JSON_PRETTY_PRINT));

        $env = $topology->ssh(
            'dev',
            'cat '.escapeshellarg("{$appPath}/.env"),
            timeoutSeconds: 60,
            allowFailure: true,
        );

        expect($env->successful())
            ->toBeTrue()
            ->and($env->output())
            ->toContain("DB_HOST={$newName}")
            ->toContain('DB_PORT=3306')
            ->not->toContain("DB_HOST={$oldName}");
    } finally {
        processManagedServiceCommandCleanup($topology, [$oldName, $newName]);
        process_managed_service_command_cleanup_database_target($topology, $connectionSlug, $app, $appPath);
        $topology->cleanup();
    }
}

function processManagedServiceCommandAssertWarnings(array $payload, string $runtimeUnit): void
{
    $warnings = $payload['success']['meta']['warnings'] ?? [];

    expect($warnings)->toBeArray();

    $unexpected = collect($warnings)
        ->reject(
            fn (mixed $warning): bool => (
                is_array($warning)
                && ($warning['code'] ?? null) === 'process.runtime_unit_apply_failed'
                && str_contains((string) ($warning['message'] ?? ''), $runtimeUnit)
            ),
        )
        ->values()
        ->all();

    expect($unexpected)->toBe([]);
}

function processManagedServiceCommandNodeServiceHost(E2ETopologyHarness $topology): string
{
    $result = processManagedServiceCommandRunGatewayTinker(
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

function process_managed_service_command_seed_database_target_app(
    E2ETopologyHarness $topology,
    string $app,
    string $path,
): void {
    $topology->ssh('dev', 'mkdir -p '.escapeshellarg($path), timeoutSeconds: 60);

    $script = str_replace(
        ['__APP__', '__PATH__'],
        [
            process_managed_service_command_php_string($app),
            process_managed_service_command_php_string($path),
        ],
        <<<'PHP'
            $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
            $node->update(['status' => 'active', 'platform' => 'ubuntu']);

            \App\Models\Project::query()->updateOrCreate(
                ['name' => '__APP__'],
                [
                    'node_id' => $node->id,
                    'path' => '__PATH__',
                    'document_root' => 'public',
                    'php_version' => '8.5',
                    'adopted' => true,
                ],
            );

            echo 'seeded';
            PHP,
    );

    processManagedServiceCommandRunGatewayTinker($topology, $script);
}

/**
 * @return array{host: string, port: int}
 */
function process_managed_service_command_endpoint(E2ETopologyHarness $topology, string $name): array
{
    $script = str_replace('__NAME__', process_managed_service_command_php_string($name), <<<'PHP'
        $process = \App\Models\Process::query()->where('name', '__NAME__')->firstOrFail();
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $endpoint = is_array($config['endpoint'] ?? null) ? $config['endpoint'] : [];

        echo json_encode([
            'host' => (string) ($endpoint['host'] ?? ''),
            'port' => (int) ($endpoint['port'] ?? 0),
        ], JSON_THROW_ON_ERROR);
        PHP);

    $endpoint = json_decode(
        processManagedServiceCommandRunGatewayTinker($topology, $script)->output(),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return [
        'host' => (string) $endpoint['host'],
        'port' => (int) $endpoint['port'],
    ];
}

function process_managed_service_command_seed_database_connection(
    E2ETopologyHarness $topology,
    string $slug,
    string $app,
    string $host,
    int $port,
): void {
    $script = str_replace(
        ['__SLUG__', '__APP__', '__HOST__', '__PORT__'],
        [
            process_managed_service_command_php_string($slug),
            process_managed_service_command_php_string($app),
            process_managed_service_command_php_string($host),
            (string) $port,
        ],
        <<<'PHP'
            $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
            $app = \App\Models\Project::query()->where('name', '__APP__')->firstOrFail();
            $connection = \App\Models\DatabaseConnection::query()->updateOrCreate(
                ['slug' => '__SLUG__'],
                [
                    'node_id' => $node->id,
                    'driver' => 'mysql',
                    'host' => '__HOST__',
                    'port' => __PORT__,
                    'database' => 'orbit',
                    'username' => 'orbit',
                    'credentials' => ['password' => 'orbit'],
                ],
            );

            \App\Models\DatabaseConnectionTarget::query()->updateOrCreate(
                ['database_connection_id' => $connection->id, 'app_id' => $app->id],
                ['env_prefix' => 'DB'],
            );

            echo 'seeded';
            PHP,
    );

    processManagedServiceCommandRunGatewayTinker($topology, $script);
}

function process_managed_service_command_seed_stale_database_env(
    E2ETopologyHarness $topology,
    string $path,
    string $host,
): void {
    $env = implode("\n", [
        'DB_CONNECTION=mysql',
        "DB_HOST={$host}",
        'DB_PORT=3306',
        'DB_DATABASE=orbit',
        'DB_USERNAME=orbit',
        'DB_PASSWORD=orbit',
        '',
    ]);

    $topology->ssh(
        'dev',
        sprintf(
            'mkdir -p %s && printf %%s %s | base64 -d > %s',
            escapeshellarg($path),
            escapeshellarg(base64_encode($env)),
            escapeshellarg("{$path}/.env"),
        ),
        timeoutSeconds: 60,
    );
}

/**
 * @return array{host: string|null, port: int|null}
 */
function process_managed_service_command_connection_snapshot(E2ETopologyHarness $topology, string $slug): array
{
    $script = str_replace('__SLUG__', process_managed_service_command_php_string($slug), <<<'PHP'
        $connection = \App\Models\DatabaseConnection::query()->where('slug', '__SLUG__')->firstOrFail();

        echo json_encode([
            'host' => $connection->host,
            'port' => $connection->port,
        ], JSON_THROW_ON_ERROR);
        PHP);

    $connection = json_decode(
        processManagedServiceCommandRunGatewayTinker($topology, $script)->output(),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return [
        'host' => is_string($connection['host'] ?? null) ? $connection['host'] : null,
        'port' => is_int($connection['port'] ?? null) ? $connection['port'] : null,
    ];
}

function process_managed_service_command_cleanup_database_target(
    E2ETopologyHarness $topology,
    string $slug,
    string $app,
    string $path,
): void {
    $script = str_replace(
        ['__SLUG__', '__APP__'],
        [
            process_managed_service_command_php_string($slug),
            process_managed_service_command_php_string($app),
        ],
        <<<'PHP'
            \App\Models\DatabaseConnection::query()->where('slug', '__SLUG__')->delete();
            \App\Models\Project::query()->where('name', '__APP__')->delete();
            echo 'cleaned';
            PHP,
    );

    processManagedServiceCommandRunGatewayTinker($topology, $script, allowFailure: true);

    $topology->ssh(
        'dev',
        'rm -rf '.escapeshellarg($path),
        timeoutSeconds: 60,
        allowFailure: true,
    );
}

function process_managed_service_command_php_string(string $value): string
{
    return str_replace(search: "'", replace: "\\'", subject: $value);
}

/**
 * @param  list<string>  $names
 * @return list<array<string, mixed>>
 */
function processManagedServiceCommandSnapshot(E2ETopologyHarness $topology, array $names): array
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
                        'service' => $config['service'] ?? null,
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
        processManagedServiceCommandRunGatewayTinker($topology, $script)->output(),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function process_managed_service_command_remove_prepared_valkey(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit process:remove valkey --node=app-dev-1 --force --json >/dev/null 2>&1 || true",
        timeoutSeconds: 180,
        allowFailure: true,
    );

    $topology->ssh(
        'dev',
        'docker service rm orbit-valkey >/dev/null 2>&1 || true; docker rm -f orbit-valkey >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $script = <<<'PHP'
        if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
            $node->processes()->where('name', 'valkey')->delete();
        }
        PHP;

    processManagedServiceCommandRunGatewayTinker($topology, $script, allowFailure: true);
}

/**
 * @param  list<string>  $names
 */
function processManagedServiceCommandCleanup(E2ETopologyHarness $topology, array $names): void
{
    if ($names === []) {
        return;
    }

    $checkout = escapeshellarg($topology->checkout('gateway'));

    foreach ($names as $name) {
        $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove "
            .escapeshellarg($name)
            .' --node=app-dev-1 --force --json >/dev/null 2>&1 || true',
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

    processManagedServiceCommandRunGatewayTinker($topology, $script, allowFailure: true);
}

function processManagedServiceCommandRunGatewayTinker(
    E2ETopologyHarness $topology,
    string $script,
    bool $allowFailure = false,
): ProcessResult {
    return e2eRunInRoleRuntime(
        $topology,
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
        timeoutSeconds: 180,
        allowFailure: $allowFailure,
    );
}

/**
 * @return array<string, mixed>
 */
function processManagedServiceCommandPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}
