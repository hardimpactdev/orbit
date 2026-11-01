<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Actions\Doctor\Intent\NodeDoctorIntent;
use App\Actions\Doctor\Intent\RuntimeDoctorIntent;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Models\TaskVm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Admit stored expectations. Swamp owns observations, ordering and completion. */
final readonly class StartDoctorAction
{
    public function __construct(private StartOperationAction $start, private NodeAccessAuthorizer $access,
        private NodeDoctorIntent $node, private RuntimeDoctorIntent $runtime,
        private ManagedNodeEligibility $eligibility, private NodeRoleServiceCatalog $services) {}

    /** @param list<DoctorFamily> $families */
    public function execute(Node $consumer, ?int $nodeId, array $families, ?string $requestId = null): Operation
    {
        if ($nodeId !== null && ! $this->access->allows($consumer, Node::query()->findOrFail($nodeId))) {
            throw new AuthorizationException;
        }
        $nodes = Node::query()->whereIn('id', $nodeId === null ? $this->access->accessibleNodeIds($consumer) : [$nodeId])->orderBy('name')->orderBy('id')->get();

        return $this->admit(array_values($nodes->all()), $families, false, $requestId);
    }

    public function collect(?string $requestId = null, bool $horizonOnly = false): Operation
    {
        $nodes = Node::query()->when($horizonOnly, fn ($query) => $query->whereKey($this->access->activeGatewayId()))->orderBy('name')->orderBy('id')->get();

        return $this->admit(array_values($nodes->all()), $horizonOnly ? [DoctorFamily::Process] : [], true, $requestId);
    }

    /** @param list<Node> $nodes
     * @param  list<DoctorFamily>  $families
     */
    public function admit(array $nodes, array $families, bool $collect, ?string $requestId = null): Operation
    {
        $rootId = ! $collect && $nodes !== [] ? $nodes[0]->id : $this->access->activeGatewayId();
        if ($rootId === null) {
            throw new ResourceOperationException('doctor.gateway_missing', 'Doctor needs the registered Gateway Node.', 503);
        }
        $selected = array_values(array_filter(DoctorFamily::cases(), static fn (DoctorFamily $family): bool => $families === [] || in_array($family, $families, true)));
        $has = static fn (array $families): bool => array_intersect(array_map(static fn (DoctorFamily $family): string => $family->value, $selected), $families) !== [];
        $intents = [];
        foreach ($nodes as $node) {
            $resources = [
                ...($has(['node']) ? $this->node->execute($node) : []),
                ...($has(['process']) ? $this->runtime->execute($node) : []),
            ];
            $managed = $this->eligibility->isManagedForObservation($node);
            $intents[] = ['id' => $node->id, 'name' => $node->name, 'platform' => $node->platform,
                'architecture' => $node->architecture ?? '', 'user' => $node->user,
                'address' => $node->wireguard_ip, 'managed' => $managed,
                'parked' => TaskVm::query()->where('node_id', $node->id)->where('state', TaskVmState::Parked)->exists(),
                'roles' => $has(['role']) ? $node->roles->map(fn ($role): array => ['id' => $role->id, 'role' => $role->role->value, 'status' => $role->status->value, 'services' => $this->services->forRole($role->role)])->all() : [],
                'resources' => (object) $resources];
        }
        $participants = array_values(array_unique([$rootId, ...array_column($intents, 'id')]));
        $input = ['nodes' => $intents, 'families' => array_map(static fn (DoctorFamily $family): string => $family->value, $selected),
            'collect_problems' => $collect, 'participant_node_ids' => $participants];
        if ($collect) {
            $state = ProblemCollectorState::query()->orderBy('id')->first();
            $input['logs'] = ['log_directory' => storage_path('logs'), 'base_directory' => base_path(),
                'cursor' => $state?->log_path === null ? null : ['path' => $state->log_path, 'inode' => $state->log_inode, 'offset' => $state->log_offset]];
        }

        return DB::transaction(fn (): Operation => $this->start->execute('doctor.inspect', 'node', $rootId, $rootId, $input, 'doctor', $requestId));
    }
}
