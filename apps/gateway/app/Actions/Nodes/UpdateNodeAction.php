<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeTaskVmContainersData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

final readonly class UpdateNodeAction
{
    public function __construct(
        private NodeSettingsNormalizer $normalizer,
        private ConfiguredStoragePathValidator $validator,
        private ManagedUserAccountResolver $accounts,
        private NodeStorageRootPreparer $preparer,
        private StartOperationAction $operations,
        private NodeProvisioningLock $provisioningLock,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    /**
     * Saves record fields at once. Apps preparation and task-host input withdrawal start a run; its settings
     * are stored when the run succeeds, while a new name or platform in the same request is already saved.
     */
    public function execute(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller = null, ?string $requestId = null): Node|Operation
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $name, $platform, $settings, $caller, $requestId): Node|Operation {
                $node->refresh();
                $update = fn (): Node|Operation => $this->update($node, $name, $platform, $settings, $caller, $requestId);

                return $name === null || $name === $node->name ? $update() : $this->provisioningLock->run($name, $update);
            });
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function update(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller, ?string $requestId): Node|Operation
    {
        $written = false;
        $result = DB::transaction(function () use ($node, $name, $platform, $settings, $caller, $requestId, &$written): Node|Operation {
            $node->refresh();
            $written = $this->record($node, $name, $platform);
            if ($settings->isEmpty()) {
                return $node;
            }
            $merged = $settings->merge($this->normalizer->fromStored($node->settings));
            $normalized = $this->normalizer->normalize($merged);
            $this->validateHostBridge($node, $normalized);
            $create = $this->hasActiveAppDevRole($node);
            $previousHost = $this->normalizer->fromStored($node->settings)?->task_vms;
            $this->keepLiveContainers($node, $previousHost, $normalized?->task_vms);
            $retireHostInput = $settings->hasTaskVms && $previousHost !== null
                && ($normalized?->task_vms === null || $previousHost->network !== $normalized->task_vms->network
                    || $previousHost->cidr !== $normalized->task_vms->cidr);
            $prepareStorage = $settings->hasApps && ($create || $normalized?->appsPath() !== null);
            if (! $prepareStorage && ! $retireHostInput) {
                $this->persist($node, $normalized);
                $written = true;

                return $node;
            }

            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('node.settings_platform_unsupported', 'Settings actions require a Linux Node.');
            }
            // Orbit creates each managed account with its own group and home, so the root is checked here, not on the Node.
            $account = new ManagedUserAccount($node->user, $node->user, $node->user === 'root' ? '/root' : '/home/'.$node->user);
            $root = $prepareStorage ? $this->validator->validateEffective($normalized, $node, $account) : null;

            return $this->operations->execute('node.update', 'node', $node->id, $node->id, [
                'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                'root' => $root?->value, 'user' => $account->user, 'group' => $account->group, 'settings' => $this->normalizer->stored($normalized),
                'task_host_input_comments' => $retireHostInput ? NodeFirewallRuleCatalog::TaskHostInputComments : [],
                'settings_patch' => ['apps' => $settings->hasApps, 'task_vms' => $settings->hasTaskVms],
            ], 'operator', $requestId, expectedWriter: 'direct');
        });

        if ($written) {
            $node->refresh()->load('roles');
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray(),
            );
        }

        return $result instanceof Node ? $node->load('roles') : $result;
    }

    /** Saves the name and platform, the registry facts that need no run, and reports whether either changed. */
    private function record(Node $node, ?string $name, ?string $platform): bool
    {
        // Enrollment observes a managed Node's platform, so only an unmanaged Node takes one from this command.
        if ($platform !== null && ((is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') || $node->roles()->exists())) {
            throw new ResourceOperationException('node.platform_managed', 'Only an unmanaged Node, with no pinned SSH host key and no role, takes a platform from this command.', 409);
        }
        if ($name !== null && $name !== $node->name && Node::query()->where('name', $name)->whereKeyNot($node->id)->exists()) {
            throw new ResourceOperationException('validation.failed', "The name [{$name}] is already registered.", details: ['field' => 'name']);
        }
        if ($name !== null) {
            $node->name = $name;
        }
        if ($platform !== null) {
            $node->platform = $platform;
        }
        if (! $node->isDirty()) {
            return false;
        }
        $node->save();

        return true;
    }

    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validateHostBridge($node, $normalized);
        $this->keepLiveContainers($node, $this->normalizer->fromStored($node->settings)?->task_vms, $normalized?->task_vms);
        $this->apply($node, $normalized);
    }

    /** @return array{settings:array<string,mixed>|null,storage:array{root:string,user:string,group:string,create_missing:bool}|null} */
    public function enrollmentIntent(Node $node, ?NodeSettingsData $settings): array
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validateHostBridge($node, $normalized);
        $this->keepLiveContainers($node, $this->normalizer->fromStored($node->settings)?->task_vms, $normalized?->task_vms);
        $this->validator->validateGrammar($normalized);
        $create = $this->hasActiveAppDevRole($node);
        $storage = null;
        if ($create || $normalized?->appsPath() !== null) {
            $account = $this->accounts->resolve($node);
            $root = $this->validator->validateEffective($normalized, $node, $account);
            $storage = ['root' => $root->value, 'user' => $account->user, 'group' => $account->group, 'create_missing' => $create];
        }

        return ['settings' => $this->normalizer->stored($normalized), 'storage' => $storage];
    }

    /** No bridge of the host, its VM bridge or its container bridge, may overlap a bridge of another host. */
    private function validateHostBridge(Node $node, ?NodeSettingsData $settings): void
    {
        $host = $settings?->task_vms;
        if ($host === null) {
            return;
        }
        $bridges = self::bridges($host->toArray());
        foreach (Node::query()->where('id', '!=', $node->id)->get() as $other) {
            $stored = $other->settings;
            $otherHost = is_array($stored) && is_array($stored['task_vms'] ?? null) ? $stored['task_vms'] : [];
            foreach (self::bridges($otherHost) as $cidr) {
                if (array_any($bridges, static fn (string $bridge): bool => TaskVmHost::overlap($bridge, $cidr))) {
                    throw new ResourceOperationException('node.settings_invalid', 'A task_vms bridge overlaps a bridge of another host.');
                }
            }
        }
    }

    /**
     * The bridge networks of stored task VM host settings: `cidr` and `containers.cidr`.
     *
     * @param  array<array-key, mixed>  $host
     * @return list<string>
     */
    private static function bridges(array $host): array
    {
        $containers = is_array($host['containers'] ?? null) ? $host['containers'] : [];

        return array_values(array_filter([$host['cidr'] ?? null, $containers['cidr'] ?? null], is_string(...)));
    }

    /**
     * A host keeps the container side its live task containers run on: their project, bridge and bridge network
     * (docs/reference/task-containers.md#add-containers-to-a-host). Its places may change.
     */
    private function keepLiveContainers(Node $node, ?NodeTaskVmsData $previous, ?NodeTaskVmsData $next): void
    {
        $before = $previous?->containers;
        if (! $before instanceof NodeTaskVmContainersData) {
            return;
        }
        $after = $next?->containers;
        if ($after instanceof NodeTaskVmContainersData && [$after->project, $after->network, $after->cidr] === [$before->project, $before->network, $before->cidr]) {
            return;
        }
        if (TaskVm::query()->live()->where('host_node_id', $node->id)->where('runtime', TaskVmRuntime::Container)->exists()) {
            throw new ResourceOperationException('task_vm.runtime_in_use', 'The host has a task container that is not destroyed, so its containers project, network and cidr stay.', 409);
        }
    }

    private function apply(Node $node, ?NodeSettingsData $normalized): void
    {
        $this->validator->validateGrammar($normalized);

        if ($this->hasActiveAppDevRole($node)) {
            $this->prepareEffectiveRoots($node, $normalized);
            $this->persist($node, $normalized);

            return;
        }

        if ($normalized instanceof NodeSettingsData) {
            $this->inspectConfiguredRoots($node, $normalized);
        }

        $this->persist($node, $normalized);
    }

    private function prepareEffectiveRoots(Node $node, ?NodeSettingsData $normalized): void
    {
        $account = $this->accounts->resolve($node);
        $roots = $this->validator->validateEffective($normalized, $node, $account);
        $this->preparer->prepare($node, $account, $roots);
    }

    private function inspectConfiguredRoots(Node $node, NodeSettingsData $settings): void
    {
        $account = $this->accounts->resolve($node);
        $this->validator->validateEffective($settings, $node, $account);
        $this->inspectExplicitRoots($node, $account, $settings);
    }

    private function persist(Node $node, ?NodeSettingsData $normalized): void
    {
        $node->settings = $this->normalizer->storedOver($node->settings, $normalized);
        $node->save();
    }

    private function inspectExplicitRoots(Node $node, ManagedUserAccount $account, NodeSettingsData $settings): void
    {
        $path = $settings->appsPath();

        if (is_string($path) && $path !== '') {
            $this->preparer->inspect($node, $account, StoragePath::parse($path));
        }
    }

    private function hasActiveAppDevRole(Node $node): bool
    {
        return $node
            ->roles()
            ->where('role', RoleName::AppDev->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();
    }
}
