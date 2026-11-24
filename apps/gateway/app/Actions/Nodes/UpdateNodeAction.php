<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeLanAddress;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeCiPatch;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\UpdateNodeResult;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
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
     * Saves record fields at once. Apps preparation starts a run; its settings are stored when the run succeeds, while a new name,
     * platform, LAN address, reboot hold or CI places in the same request are already saved.
     * A record-only request whose values all equal the stored ones writes nothing and is reported unchanged.
     */
    public function execute(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller = null, ?string $requestId = null,
        bool $hasLanIp = false, ?string $lanIp = null, ?bool $rebootHold = null, ?bool $primaryAgent = null): UpdateNodeResult
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp, $rebootHold, $primaryAgent): UpdateNodeResult {
                $node->refresh();
                $update = fn (): UpdateNodeResult => $this->update($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp, $rebootHold, $primaryAgent);

                return $name === null || $name === $node->name ? $update() : $this->provisioningLock->run($name, $update);
            });
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function update(Node $node, ?string $name, ?string $platform, NodeSettingsPatch $settings, ?Node $caller, ?string $requestId,
        bool $hasLanIp, ?string $lanIp, ?bool $rebootHold, ?bool $primaryAgent): UpdateNodeResult
    {
        $written = false;
        $demotedIds = [];
        $result = DB::transaction(function () use ($node, $name, $platform, $settings, $caller, $requestId, $hasLanIp, $lanIp, $rebootHold, $primaryAgent, &$written, &$demotedIds): Node|Operation {
            $node->refresh();
            ['written' => $written, 'demotedIds' => $demotedIds] = $this->record($node, $name, $platform, $hasLanIp, $lanIp, $rebootHold, $primaryAgent);
            $written = $this->recordCi($node, $settings) || $written;
            if ($settings->isEmpty()) {
                return $node;
            }
            $merged = $settings->merge($this->normalizer->fromStored($node->settings));
            $normalized = $this->normalizer->normalize($merged);
            $create = $this->hasActiveRole($node, RoleName::AppDev);
            $prepareStorage = $settings->hasApps && ($create || $normalized?->appsPath() !== null);
            if (! $prepareStorage) {
                $written = $this->persist($node, $normalized) || $written;

                return $node;
            }

            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('node.settings_platform_unsupported', 'Settings actions require a Linux Node.');
            }
            // Orbit creates each managed account with its own group and home, so the root is checked here, not on the Node.
            $account = new ManagedUserAccount($node->user, $node->user, $node->user === 'root' ? '/root' : '/home/'.$node->user);
            $root = $this->validator->validateEffective($normalized, $node, $account);

            return $this->operations->execute('node.update', 'node', $node->id, $node->id, [
                'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                'root' => $root->value, 'user' => $account->user, 'group' => $account->group, 'settings' => $this->normalizer->stored($normalized),
                'settings_patch' => ['apps' => $settings->hasApps, 'nested_fleet' => $settings->hasNestedFleet, 'instance_host' => $settings->hasInstanceHost],
            ], 'operator', $requestId, expectedWriter: 'direct');
        });

        $broadcaster = $this->broadcaster ?? app(RecordEventBroadcaster::class);
        if ($written) {
            $node->refresh()->load('roles');
            $broadcaster->broadcast(
                RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray(),
            );
        }
        if ($demotedIds !== []) {
            foreach (Node::query()->whereIn('id', $demotedIds)->with('roles')->get() as $demoted) {
                $broadcaster->broadcast(RecordEventType::NodeUpdated, $demoted->id, NodeData::fromModel($demoted)->toArray());
            }
        }

        return $result instanceof Node ? new UpdateNodeResult($node->load('roles'), unchanged: ! $written) : new UpdateNodeResult($result);
    }

    /**
     * Saves the name, platform, LAN address, holds and primary agent designation, the registry facts that need no run,
     * and reports whether one changed. The LAN address selects where Orbit renders LAN traffic, the hub endpoint
     * included (ADR 0251); each render picks it up.
     *
     * @return array{written: bool, demotedIds: list<int>}
     */
    private function record(Node $node, ?string $name, ?string $platform, bool $hasLanIp, ?string $lanIp, ?bool $rebootHold, ?bool $primaryAgent): array
    {
        // Enrollment observes a managed Node's platform, so only an unmanaged Node takes one from this command.
        if ($platform !== null && ((is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') || $node->roles()->exists())) {
            throw new ResourceOperationException('node.platform_managed', 'Only an unmanaged Node, with no pinned SSH host key and no role, takes a platform from this command.', 409);
        }
        if ($name !== null && $name !== $node->name && Node::query()->where('name', $name)->whereKeyNot($node->id)->exists()) {
            throw new ResourceOperationException('validation.failed', "The name [{$name}] is already registered.", details: ['field' => 'name']);
        }
        if ($primaryAgent === true && ! $this->hasActiveRole($node, RoleName::Agent)) {
            throw new ResourceOperationException('node.agent_role_required', 'Only a Node with an active agent role can be the primary agent.', 422);
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
        if ($rebootHold !== null) {
            $node->reboot_hold = $rebootHold;
        }
        $demotedIds = [];
        if ($primaryAgent !== null) {
            if ($primaryAgent) {
                foreach (Node::query()->whereKeyNot($node->id)->where('primary_agent', true)->get(['id']) as $previous) {
                    $demotedIds[] = $previous->id;
                }
                if ($demotedIds !== []) {
                    Node::query()->whereIn('id', $demotedIds)->update(['primary_agent' => false]);
                }
            }
            $node->primary_agent = $primaryAgent;
        }
        if (! $node->isDirty()) {
            return ['written' => $demotedIds !== [], 'demotedIds' => $demotedIds];
        }
        $node->save();

        return ['written' => true, 'demotedIds' => $demotedIds];
    }

    /**
     * Saves a CI Node's CI places, a record that needs no run (docs/reference/node-settings.md#ci-places), and reports whether they
     * changed. Only a Node with an active ci-runner role takes them; clearing them is allowed on any Node.
     */
    private function recordCi(Node $node, NodeSettingsPatch $settings): bool
    {
        if (! $settings->hasCi) {
            return false;
        }
        if ($settings->ci instanceof NodeCiPatch && ! $this->hasActiveRole($node, RoleName::CiRunner)) {
            throw new ResourceOperationException('node.settings_ci_runner_required', 'Only a Node with an active ci-runner role takes CI places.', 422);
        }

        return $this->persist($node, new NodeSettingsPatch(hasApps: false, apps: null, hasCi: true, ci: $settings->ci)
            ->merge($this->normalizer->fromStored($node->settings)));
    }

    /**
     * The Node's roots are prepared or inspected first, over SSH and outside any transaction. The write then runs in one transaction.
     */
    public function persistDuringProvisioning(Node $node, ?NodeSettingsData $settings): void
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validator->validateGrammar($normalized);
        if ($this->hasActiveRole($node, RoleName::AppDev)) {
            $this->prepareEffectiveRoots($node, $normalized);
        } elseif ($normalized instanceof NodeSettingsData) {
            $this->inspectConfiguredRoots($node, $normalized);
        }
        DB::transaction(function () use ($node, $normalized): void {
            $node->refresh();
            $this->persist($node, $normalized);
        });
    }

    /** @return array{settings:array<string,mixed>|null,storage:array{root:string,user:string,group:string,create_missing:bool}|null} */
    public function enrollmentIntent(Node $node, ?NodeSettingsData $settings): array
    {
        $normalized = $this->normalizer->normalize($settings);
        $this->validator->validateGrammar($normalized);
        $create = $this->hasActiveRole($node, RoleName::AppDev);
        $storage = null;
        if ($create || $normalized?->appsPath() !== null) {
            $account = $this->accounts->resolve($node);
            $root = $this->validator->validateEffective($normalized, $node, $account);
            $storage = ['root' => $root->value, 'user' => $account->user, 'group' => $account->group, 'create_missing' => $create];
        }

        return ['settings' => $this->normalizer->stored($normalized), 'storage' => $storage];
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

    private function hasActiveRole(Node $node, RoleName $role): bool
    {
        return $node
            ->roles()
            ->where('role', $role->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();
    }
}
