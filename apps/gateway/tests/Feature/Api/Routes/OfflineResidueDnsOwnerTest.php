<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\OfflineResidueCommand;
use App\Domain\Routes\OfflineResidueExecution;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Tests\Support\Fleet\FleetFixtures;

it('prepares private DNS residue only on an active VPN owner', function (string $roles, LifecycleStatus $nodeStatus, LifecycleStatus $roleStatus, bool $dns): void {
    $node = FleetFixtures::node('residue-owner', array_map(RoleName::from(...), explode(',', $roles)), status: $nodeStatus);
    $node->roles()->update(['status' => $roleStatus]);
    $operation = Operation::query()->create([
        'kind' => 'route.offline_residue_clean', 'target_type' => 'node_route_residue',
        'target_id' => $node->id, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'sequence' => 1, 'input' => ['removed_node_id' => null], 'state' => [],
    ]);

    expect(app(OfflineResidueCommand::class)->run($operation, ['removed_node_id' => null, 'action' => 'prepare']))
        ->toBe(['prepared' => true]);

    $plan = ValidatedData::object(data_get($operation->refresh()->state, 'offline_residue_plan'));
    expect(array_key_exists('dns', $plan))->toBe($dns);
    expect(in_array('private-dns-residue', ValidatedData::stringList($plan['removed'] ?? []), true))->toBe($dns);
    $steps = array_column(app(OfflineResidueExecution::class)->context($operation)['descriptors'], 'key');
    expect(in_array('offline-residue-dns', $steps, true))->toBe($dns);
    expect($steps[0])->toBe('offline-residue-prepare');
    expect(array_last($steps))->toBe('offline-residue-proof');
})->with([
    'separate Gateway consumes DNS' => ['gateway', LifecycleStatus::Active, LifecycleStatus::Active, false],
    'VPN serves DNS' => ['vpn', LifecycleStatus::Active, LifecycleStatus::Active, true],
    'combined VPN and Gateway serves DNS' => ['vpn,gateway', LifecycleStatus::Active, LifecycleStatus::Active, true],
    'application Node consumes DNS' => ['app-dev', LifecycleStatus::Active, LifecycleStatus::Active, false],
    'inactive VPN role' => ['vpn', LifecycleStatus::Active, LifecycleStatus::Provisioning, false],
    'failed VPN Node' => ['vpn', LifecycleStatus::Failed, LifecycleStatus::Active, false],
]);
