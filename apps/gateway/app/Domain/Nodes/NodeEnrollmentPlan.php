<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Support\ValidatedData;

/** An enrollment plan has one child per target and retains Route sources until proof. */
final readonly class NodeEnrollmentPlan
{
    public function __construct(private NodeEnrollmentRoutes $routes) {}

    /** @param array<string,mixed> $input
     * @param  array{route_changes:list<array{route_id:int,domain:string,node_id:int}>,roles:list<string>,settings_provided:bool,task_vm_id:?int}  $intent
     * @return list<array<string,mixed>>
     */
    public function build(Node $node, array $input, array $intent): array
    {
        $steps = $this->routes->steps($intent['route_changes']);
        if ($input['mode'] !== 'tld_only') {
            $steps[] = $this->child('add-begin', 'node.enrollment_begin', $node->id);
            foreach (['probe', 'install', ...($input['mode'] === 'linux' ? ['host', 'verify'] : [])] as $stage) {
                $steps[] = $this->child('add-bootstrap-'.$stage, 'node.bootstrap_'.$stage, $node->id);
            }
            if ($input['mode'] === 'macos') {
                $steps[] = $this->child('add-macos-inspect', 'node.macos_inspect', $node->id);
            } else {
                $steps[] = $this->child('add-network', 'node.network_converge', $node->id, ['roleless_operator' => $input['roleless_operator']]);
                $steps[] = $this->child('add-apt', 'tool.manager_converge', $node->id, ['manager' => 'apt', 'image_provides_manager' => false]);
                $roles = ValidatedData::stringList($intent['roles']);
                if (in_array('vpn', $roles, true)) {
                    $roles = ['vpn', ...array_filter($roles, static fn (string $role): bool => $role !== 'vpn')];
                }
                foreach ($roles as $role) {
                    $steps[] = $this->child('add-role-'.$role, 'node.role_add', $node->id, ['role' => $role, 'converge' => true]);
                }
                foreach (Node::query()->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get() as $target) {
                    if ($target->platform === 'linux' && $target->wireguard_ip !== null && $target->ssh_host_fingerprint !== null && ! app(ProcessSwampSafety::class)->requiresSynchronous($target)) {
                        $steps[] = $this->child('add-expand-'.$target->id, 'node.network_projection', $target->id,
                            ['context_node_id' => $node->id, 'phase' => 'expand']);
                    }
                }
            }
        }
        $steps[] = $this->child('add-activate', 'node.enrollment_activate', $node->id);
        if ($intent['settings_provided']) {
            $steps[] = $this->child('add-settings', 'node.enrollment_settings', $node->id);
        }
        if ($input['mode'] !== 'macos') {
            $steps[] = $this->child('add-footprint', 'node.converge', $node->id, ['force_artifacts' => false, 'first_visit' => false]);
            foreach (Node::query()->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get() as $target) {
                if ($target->platform !== 'linux' || $target->wireguard_ip === null || $target->ssh_host_fingerprint === null) {
                    continue;
                }
                if ($input['mode'] === 'linux' && ! app(ProcessSwampSafety::class)->requiresSynchronous($target)) {
                    $steps[] = $this->child('add-prune-'.$target->id, 'node.network_projection', $target->id,
                        ['context_node_id' => $node->id, 'phase' => 'prune']);
                }
                if (PrivateDnsPublicationNodes::query()->whereKey($target->id)->exists()) {
                    $steps[] = $this->child('add-dns-footprint-'.$target->id, 'node.converge', $target->id,
                        ['force_artifacts' => false, 'first_visit' => false]);
                    $steps[] = $this->child('add-dns-'.$target->id, 'route.private_dns_publish', $target->id, ['reason' => 'node enrollment']);
                }
            }
            if ($input['mode'] === 'linux') {
                if ($intent['task_vm_id'] === null) {
                    $metrics = Node::query()->where('status', LifecycleStatus::Active)
                        ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->first();
                    if ($metrics !== null) {
                        throw new ResourceOperationException('metrics.native_composition_required', 'This parent requires Metrics stages in the same native run.', 409);
                    }
                }
            }
            $steps[] = $this->child('add-cli', 'fleet.cli_converge', $node->id, optional: true);
            if ($input['mode'] === 'linux') {
                $steps[] = $this->child('add-commit', 'node.enrollment_commit', $node->id);
            }
        }

        return $steps;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function child(string $key, string $kind, int $nodeId, array $input = [], bool $optional = false): array
    {
        return ['type' => 'child', 'key' => $key, 'kind' => $kind, 'node_id' => $nodeId, 'input' => $input, 'optional' => $optional];
    }
}
