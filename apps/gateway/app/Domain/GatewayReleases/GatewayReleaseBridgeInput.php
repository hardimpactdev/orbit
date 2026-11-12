<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** The candidate Gateway captures public SSH intent during the non-fatal activation step. */
final readonly class GatewayReleaseBridgeInput
{
    public function __construct(private ManagedNodeEligibility $eligibility, private SwampSshExecutionInput $ssh) {}

    /**
     * @return array{sha256:string,nodes:list<array{node_id:int,name:string,host:string,user:string,port:int,host_keys:list<string>,identity_file:string}>}
     */
    public function capture(): array
    {
        $digest = hash_file('sha256', base_path('../../swamp/ansible/instance_bridge.py'));
        if (! is_string($digest)) {
            throw new RuntimeException('gateway.release_bridge_input_failed');
        }
        $targets = [];
        $nodes = Node::query()->where('status', LifecycleStatus::Active)->where('platform', 'linux')
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active))
            ->orderBy('id')->get();
        foreach ($nodes as $node) {
            if (! $this->eligibility->allows($node) || ! is_string($node->ssh_host_key_type) || $node->ssh_host_key_type === ''
                || ! is_string($node->ssh_host_key) || $node->ssh_host_key === '') {
                continue;
            }
            $ssh = $this->ssh->forNode($node);
            if ($ssh['target']['host_keys'] === null) {
                continue;
            }
            $targets[] = [...$ssh['target'], 'name' => $node->name, 'identity_file' => $ssh['identity_file']];
        }

        return ['sha256' => $digest, 'nodes' => $targets];
    }
}
