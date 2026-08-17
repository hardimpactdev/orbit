<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Analytics\AnalyticsProcessEndpointResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the exact PostgreSQL process for a valid stored identity', function (): void {
    [$assignment, $selected, $other] = analytics_resolver_identified_postgres_pair();

    $resolved = app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    );

    expect($resolved['process']->is($selected))
        ->toBeTrue()
        ->and($resolved['host'])
        ->toBe('10.6.0.4')
        ->and($resolved['port'])
        ->toBe(5432)
        ->and($resolved['process']->is($other))
        ->toBeFalse();
});

it('fails closed when a stored PostgreSQL identity is missing even if one process exists', function (): void {
    [$assignment] = analytics_resolver_unidentified_postgres();

    expect(fn () => app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    ))
        ->toThrow(RuntimeException::class, 'The analytics role requires a postgres_process_id setting.');
});

it('rejects a stale stored PostgreSQL process identity', function (): void {
    [$assignment] = analytics_resolver_identified_postgres();
    $assignment->forceFill([
        'settings' => [
            ...$assignment->settings,
            'postgres_process_id' => 999_999,
        ],
    ])->save();

    expect(fn () => app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    ))
        ->toThrow(
            RuntimeException::class,
            'The analytics role postgres_process_id must reference a postgres process on its assigned database node.',
        );
});

it('fails clearly when multiple PostgreSQL processes exist without a stored identity', function (): void {
    [$assignment] = analytics_resolver_unidentified_postgres_pair();

    expect(fn () => app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    ))
        ->toThrow(
            RuntimeException::class,
            'The analytics role PostgreSQL selection is ambiguous on node database1; store postgres_process_id.',
        );
});

it('still discovers a single ClickHouse process when no process identity setting is requested', function (): void {
    $databaseNode = analytics_resolver_database_node();
    $clickHouse = analytics_resolver_service_process(
        databaseNode: $databaseNode,
        service: 'clickhouse',
        port: 8123,
    );
    $assignment = analytics_resolver_assignment($databaseNode, [
        'postgres_node_id' => $databaseNode->id,
        'clickhouse_node_id' => $databaseNode->id,
    ]);

    $resolved = app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'clickhouse_node_id',
        service: 'clickhouse',
    );

    expect($resolved['process']->is($clickHouse))
        ->toBeTrue()
        ->and($resolved['host'])
        ->toBe('10.6.0.4')
        ->and($resolved['port'])
        ->toBe(8123);
});

/**
 * @return array{0: NodeRoleAssignment, 1: Process}
 */
function analytics_resolver_identified_postgres(): array
{
    [$assignment, $selected] = analytics_resolver_postgres_context([
        'include_process_id' => true,
    ]);

    return [$assignment, $selected];
}

/**
 * @return array{0: NodeRoleAssignment, 1: Process}
 */
function analytics_resolver_unidentified_postgres(): array
{
    [$assignment, $selected] = analytics_resolver_postgres_context([]);

    return [$assignment, $selected];
}

/**
 * @return array{0: NodeRoleAssignment, 1: Process, 2: Process}
 */
function analytics_resolver_identified_postgres_pair(): array
{
    return analytics_resolver_postgres_context([
        'include_process_id' => true,
        'extra_name' => 'postgres-food',
        'extra_port' => 5433,
    ]);
}

/**
 * @return array{0: NodeRoleAssignment, 1: Process, 2: Process}
 */
function analytics_resolver_unidentified_postgres_pair(): array
{
    return analytics_resolver_postgres_context([
        'extra_name' => 'postgres-food',
        'extra_port' => 5433,
    ]);
}

/**
 * @param  array{include_process_id?: true, extra_name?: string, extra_port?: int}  $options
 * @return array{0: NodeRoleAssignment, 1: Process, 2?: Process}
 */
function analytics_resolver_postgres_context(array $options): array
{
    $databaseNode = analytics_resolver_database_node();
    $selected = analytics_resolver_service_process(
        databaseNode: $databaseNode,
        service: 'postgres',
        port: 5432,
    );
    $settings = [
        'postgres_node_id' => $databaseNode->id,
        'clickhouse_node_id' => $databaseNode->id,
    ];

    if (array_key_exists('include_process_id', $options)) {
        $settings['postgres_process_id'] = $selected->id;
    }

    $assignment = analytics_resolver_assignment($databaseNode, $settings);
    $result = [$assignment, $selected];

    if (array_key_exists('extra_name', $options) && array_key_exists('extra_port', $options)) {
        $result[] = analytics_resolver_service_process(
            databaseNode: $databaseNode,
            service: 'postgres',
            port: $options['extra_port'],
            name: $options['extra_name'],
        );
    }

    return $result;
}

function analytics_resolver_database_node(): Node
{
    return Node::factory()->create([
        'name' => 'database1',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);
}

function analytics_resolver_service_process(
    Node $databaseNode,
    string $service,
    int $port,
    ?string $name = null,
): Process {
    return Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => $name ?? $service,
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => $service,
                'version_family' => $service === 'postgres' ? '16' : null,
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => $port,
                ],
            ],
        ]);
}

/**
 * @param  array<string, mixed>  $settings
 */
function analytics_resolver_assignment(Node $databaseNode, array $settings): NodeRoleAssignment
{
    $analyticsNode = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);

    return NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'settings' => $settings,
    ]);
}
