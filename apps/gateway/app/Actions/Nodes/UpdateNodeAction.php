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
use App\Domain\Nodes\NodeBridges;
use App\Domain\Nodes\NodeLanAddress;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\UpdateNodeResult;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
     * are stored when the run succeeds, while a new name, platform or LAN address in the same request is already saved.
     * A record-only request whose values all equal the stored ones writes nothing and is reported unchanged.
     */
    public function execute(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller = null, ?string $requestId = null,
        bool $hasLanIp = false, ?string $lanIp = null): UpdateNodeResult
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp): UpdateNodeResult {
                $node->refresh();
                $update = fn (): UpdateNodeResult => $this->update($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp);

                return $name === null || $name === $node->name ? $update() : $this->provisioningLock->run($name, $update);
            });
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function update(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller, ?string $requestId,
        bool $hasLanIp, ?string $lanIp): UpdateNodeResult
    {
        $written = false;
        $result = DB::transaction(function () use ($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp, &$written): Node|Operation {
            $node->refresh();
            $written = $this->record($node, $name, $platform, $hasLanIp, $lanIp);
            if ($settings->isEmpty()) {
                return $node;
            }
            $merged = $settings->merge($this->normalizer->fromStored($node->settings));
            $normalized = $this->normalizer->normalize($merged);
            $this->validateHostBridge($node, $normalized);
            $create = $this->hasActiveAppDevRole($node);
            $previousHost = $this->normalizer->fromStored($node->settings)?->task_vms;
            $this->keepLiveContainers($node, $previousHost, $normalized?->task_vms);
            $nextHost = $normalized?->task_vms;
            // Each bridge's input rules go when the bridge goes or moves; the next base refresh prepares the new one.
            $retireVmInput = $settings->hasTaskVms && $previousHost !== null
                && ($nextHost === null || $previousHost->network !== $nextHost->network || $previousHost->cidr !== $nextHost->cidr);
            $before = $previousHost?->containers;
            $after = $nextHost?->containers;
            $retireContainerInput = $settings->hasTaskVms && $before instanceof NodeTaskVmContainersData
                && (! $after instanceof NodeTaskVmContainersData || [$before->network, $before->cidr] !== [$after->network, $after->cidr]);
            $retireComments = [...($retireVmInput ? NodeFirewallRuleCatalog::TaskHostInputComments : []),
                ...($retireContainerInput ? NodeFirewallRuleCatalog::TaskContainerInputComments : [])];
            $prepareStorage = $settings->hasApps && ($create || $normalized?->appsPath() !== null);
            if (! $prepareStorage && $retireComments === []) {
                $written = $this->persist($node, $normalized) || $written;

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
                'task_host_input_comments' => $retireComments,
                'settings_patch' => ['apps' => $settings->hasApps, 'task_vms' => $settings->hasTaskVms],
            ], 'operator', $requestId, expectedWriter: 'direct');
        });

        if ($written) {
            $node->refresh()->load('roles');
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray(),
            );
        }

        return $result instanceof Node ? new UpdateNodeResult($node->load('roles'), unchanged: ! $written) : new UpdateNodeResult($result);
    }

    /**
     * Saves the name, platform and LAN address, the registry facts that need no run, and reports whether one changed. The LAN
     * address selects where Orbit renders LAN traffic, the hub endpoint included (ADR 0251); each render picks it up.
     */
    private function record(Node $node, ?string $name, ?string $platform, bool $hasLanIp, ?string $lanIp): bool
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
        if ($hasLanIp && $lanIp !== $node->lan_ip) {
            if ($lanIp !== null) {
                NodeLanAddress::assertFree($lanIp, $node->cluster_id, $node->id);
            }
            $node->lan_ip = $lanIp;
        }
        if (! $node->isDirty()) {
            return false;
        }
        $node->save();

        return true;
    }

    /**
     * The Node's roots are prepared or inspected first, over SSH and outside any transaction. The bridge check and the write then
     * run in one transaction, which the connection begins IMMEDIATE, so an Instance bridge allocation cannot slip between them.
     */
    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);
        // Refuse before any SSH work; the transaction checks both again under the lock.
        $this->validateHostBridge($node, $normalized);
        $this->keepLiveContainers($node, $this->normalizer->fromStored($node->settings)?->task_vms, $normalized?->task_vms);
        $this->validator->validateGrammar($normalized);
        if ($this->hasActiveAppDevRole($node)) {
            $this->prepareEffectiveRoots($node, $normalized);
        } elseif ($normalized instanceof NodeSettingsData) {
            $this->inspectConfiguredRoots($node, $normalized);
        }
        DB::transaction(function () use ($node, $normalized): void {
            $node->refresh();
            $this->validateHostBridge($node, $normalized);
            $this->keepLiveContainers($node, $this->normalizer->fromStored($node->settings)?->task_vms, $normalized?->task_vms);
            $this->persist($node, $normalized);
        });
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

    /**
     * No bridge of the host, its VM bridge or its container bridge, may overlap a task bridge of another host or the
     * Instance bridge of any Node, its own included.
     */
    private function validateHostBridge(Node $node, ?NodeSettingsData $settings): void
    {
        $host = $settings?->task_vms;
        if ($host === null) {
            return;
        }
        $recorded = NodeBridges::recorded(replacing: $node->id);
        foreach (NodeBridges::taskBridges($host->toArray()) as $bridge) {
            if (NodeBridges::overlapsAny($bridge, $recorded)) {
                throw new ResourceOperationException('node.settings_invalid', 'A task_vms bridge overlaps a bridge of another host or an Instance bridge.');
            }
        }
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

    /** Saves the settings and reports whether they changed; equal settings are not written. */
    private function persist(Node $node, ?NodeSettingsData $normalized): bool
    {
        $node->settings = $this->normalizer->storedOver($node->settings, $normalized);
        if (! $node->isDirty('settings')) {
            return false;
        }
        $node->save();

        return true;
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
