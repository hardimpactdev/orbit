<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

it('keeps source profile, production identity, and deployment evidence nullable without inference', function (): void {
    $project = Project::query()->create([
        'name' => 'Persistence', 'slug' => 'persistence', 'repository_url' => 'https://example.test/persistence.git',
    ]);
    $node = Node::query()->create([
        'name' => 'persistence', 'status' => 'active', 'public_ssh_host' => '192.0.2.70',
    ]);
    foreach ([InstanceState::Active, InstanceState::SourceResolved] as $status) {
        $instance = Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $node->id, 'name' => $status->value,
            'checkout_path' => '/srv/'.$status->value, 'status' => $status,
            'branch' => 'release/one', 'selected_php_version' => '8.5',
        ]);

        expect(Schema::hasColumns('instances', ['source_is_laravel', 'production_user', 'production_home', 'deployment_branch']))->toBeTrue()
            ->and($instance->refresh()->source_is_laravel)->toBeNull()
            ->and($instance->production_user)->toBeNull()
            ->and($instance->production_home)->toBeNull()
            ->and($instance->deployment_branch)->toBeNull()
            ->and(normalized_deploy_steps($instance))->toBe([]);
        foreach ([true, false] as $profile) {
            $instance->update(['source_is_laravel' => $profile]);
            expect($instance->refresh()->source_is_laravel)->toBe($profile);
        }
    }
});

it('persists transfer status and moved web-root Route evidence', function (): void {
    $node = Node::query()->create([
        'name' => 'transfer-persistence', 'status' => 'active', 'public_ssh_host' => '192.0.2.71',
    ]);
    $transfer = InstanceTransfer::query()->create([
        'source_node_id' => $node->id,
        'destination_node_id' => $node->id,
        'destination_name' => 'web',
        'destination_path' => '/srv/orbit/apps/transfer/web',
        'destination_domain' => 'web.transfer.dev.orbit',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/transfer/web',
        'source_route_id' => 1,
        'web_root_route_ids' => [2, 3],
        'status' => InstanceTransferStatus::Completed,
        'current_step' => InstanceTransferStep::Completed,
    ]);

    expect(Schema::hasColumn('instance_transfers', 'web_root_route_ids'))->toBeTrue()
        ->and($transfer->refresh()->web_root_route_ids)->toBe([2, 3])
        ->and($transfer->status)->toBe(InstanceTransferStatus::Completed)
        ->and($transfer->current_step)->toBe(InstanceTransferStep::Completed);
});
