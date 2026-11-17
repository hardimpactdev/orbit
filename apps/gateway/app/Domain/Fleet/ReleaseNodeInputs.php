<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\AppDevRuntimeFootprint;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleUnits;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;
use App\Support\ValidatedData;

/** Fingerprints of the declared inputs to release-time Node work. Agent and CLI delivery have their own pins. */
final readonly class ReleaseNodeInputs
{
    private const string Recipe = 'apps/gateway/resources/machine-software/recipe.json';

    /** These affect the host as a whole. A release may report their drift but may not apply them. */
    private const array HostFiles = [
        'apps/gateway/app/Domain/Nodes/NodeKernelPolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeUpdatePolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeNetworkConvergeInput.php',
        'swamp/ansible/tasks/node-kernel-policy.yml',
        'swamp/ansible/tasks/node-update-policy.yml',
        'swamp/ansible/tasks/node-converge-network.yml',
        'swamp/ansible/tasks/node-converge-hub.yml',
        'apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php',
        'swamp/ansible/tasks/gateway-runtime.yml',
    ];

    /** The plays and materializer shared by every role converge. */
    private const array SharedRoleFiles = [
        'swamp/ansible/ansible.cfg',
        'swamp/ansible/build_vars.py',
        'swamp/ansible/operation-node-converge.yml',
        'swamp/ansible/tasks/node-converge-role.yml',
        'swamp/ansible/tasks/node-role-runtime.yml',
        'swamp/ansible/tasks/node-converge-role-check.yml',
        'swamp/ansible/tasks/check-files.yml',
        'swamp/ansible/tasks/check-runtime.yml',
        'swamp/ansible/tasks/node-converge-software*.yml',
        'swamp/ansible/tasks/node-converge-apt*.yml',
        'swamp/ansible/tasks/node-converge-javascript.yml',
        'swamp/ansible/tasks/javascript-version.yml',
        'swamp/ansible/tasks/artifact-fetch.yml',
        'swamp/ansible/library/orbit_artifact.py',
        'swamp/ansible/transport-exit-codes.json',
        'swamp/extensions/behaviors/operations/ansible-input/converge_input.ts',
    ];

    /** Shipped assets consumed by the shared Instance-host branch on app-dev and CI-only Nodes. */
    private const array InstanceHostFiles = [
        'swamp/ansible/tasks/instance-host*.yml',
        'swamp/ansible/tasks/instance-folder*.yml',
        'swamp/ansible/tasks/instance-bridge-*.yml',
        'swamp/ansible/tasks/instance-container-files.yml',
        'swamp/ansible/tasks/instance-power-helper.yml',
        'swamp/ansible/tasks/instance-fleet-node.yml',
        'swamp/ansible/tasks/instance-pool-create.yml',
        'swamp/ansible/tasks/instance-network-*.yml',
        'swamp/ansible/tasks/instance-egress.yml',
        'swamp/ansible/tasks/instance-image.yml',
        'swamp/ansible/tasks/instance-vite-plus-home.yml',
        'swamp/ansible/tasks/task-container-host-*.yml',
        'swamp/ansible/instance-nic-filters.json',
        'swamp/ansible/tasks/incus-install.yml',
        'swamp/ansible/library/orbit_incus_images.py',
        'swamp/ansible/library/orbit_static_php.py',
        'swamp/ansible/instance_container_files.py',
        'swamp/ansible/instance_machine_name.py',
        'swamp/ansible/instance_folder_*.py',
        'swamp/ansible/instance_bridge.py',
        'swamp/ansible/instance_network.py',
        'swamp/ansible/instance_nested.py',
        'swamp/ansible/files/instance-browser-trust.sh',
        'swamp/ansible/files/instance-container-wake.sh',
        'swamp/ansible/files/instance-container-power.py',
    ];

    private const array RoleFiles = [
        'swamp' => ['swamp/ansible/tasks/swamp-start-cache.yml', 'swamp/ansible/swamp-worker-renewal.yml',
            'swamp/ansible/tasks/swamp-worker-*.yml', 'swamp/ansible/tasks/node-converge-deno.yml'],
        'websocket' => ['swamp/ansible/tasks/websocket.yml'],
        'metrics' => ['swamp/ansible/tasks/metrics.yml'],
        'ci-runner' => ['swamp/ansible/tasks/ci.yml', 'swamp/ansible/tasks/ci-*.yml', 'swamp/ansible/tasks/proof-seed-*.yml',
            'swamp/extensions/behaviors/operations/node/ci_host_spec.ts'],
        'app-prod' => ['swamp/ansible/tasks/node-converge-browser.yml'],
    ];

    public function host(?string $root = null): string
    {
        $root ??= dirname(base_path(), 2);
        $recipe = new RecipeLoader()->source($root.'/'.self::Recipe);
        $raw = ValidatedData::object(json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR));

        return self::digest([$raw['ubuntu'], $raw['architectures'], $raw['artifact_mirror'], $raw['git'],
            $this->pins($recipe, $recipe->profile('base')), $this->files($root, self::HostFiles)]);
    }

    /**
     * @param  list<string>|null  $selected
     * @return array<int, array{role:string,digest:string}> Assignment IDs keep a recreated role from inheriting an apply.
     */
    public function roles(Node $node, bool $release = true, ?array $selected = null, ?string $root = null): array
    {
        $root ??= dirname(base_path(), 2);
        $recipe = new RecipeLoader()->source($root.'/'.self::Recipe);
        $raw = ValidatedData::object(json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR));
        $host = app(InstanceHostIntent::class);
        $hostRole = $host->role($node);
        $sharedFiles = $this->files($root, self::SharedRoleFiles);
        $roles = [];
        foreach ($node->roles()->where('status', LifecycleStatus::Active)
            ->when($release, static fn ($query) => $query->where('role', '!=', RoleName::Gateway))
            ->when($selected !== null, static fn ($query) => $query->whereIn('role', $selected ?? []))->orderBy('id')->get() as $assignment) {
            $role = $assignment->role->value;
            $profile = array_values(array_diff($recipe->profiles[$role] ?? [], ValidatedData::stringList($raw['common'])));
            $versions = array_keys($recipe->phpExtensions($profile));
            $php = array_intersect_key($recipe->php, array_flip($versions));
            $runtime = app(NodeRoleRuntimeInput::class)->capture($node, $assignment);
            // Transport, exporter publication, and the pinned binary are separate inputs to their own Operations.
            unset($runtime['agent_sha256'], $runtime['ssh_execution'], $runtime['fleet']);
            $instanceHost = $hostRole === $assignment->role;
            // The first converge allocates a missing bridge before capturing the applied plan.
            $instances = $instanceHost && $node->instance_bridge !== null ? $host->for($node) : null;
            $executor = isset(NodeExecutorInput::RoleFamilies[$role]);
            $roleUnits = NodeRoleUnits::for($node, [$role]);
            $files = [
                ...(self::RoleFiles[$role] ?? []),
                ...($php !== [] ? ['swamp/ansible/tasks/php.yml'] : []),
                ...($executor || $roleUnits !== [] ? ['swamp/ansible/tasks/node-converge-base.yml'] : []),
                ...($executor ? ['swamp/ansible/tasks/node-executor.yml', 'swamp/ansible/tasks/node-converge-replace.yml'] : []),
                ...($instanceHost ? self::InstanceHostFiles : []),
            ];
            $renderedFiles = match ($assignment->role) {
                RoleName::AppDev => [AppDevRuntimeFootprint::directories(), AppDevRuntimeFootprint::files()],
                RoleName::CiRunner => app(NativeNodeRoleRuntimeInput::class)->ciRunner($node),
                default => [],
            };
            $phpRuntime = $php === [] ? [] : array_values(array_filter(
                app(PhpRuntimeFootprintArtifact::class)->inputs($node),
                static fn (array $configuration): bool => in_array($configuration['version'], $versions, true),
            ));
            $roles[$assignment->id] = ['role' => $role, 'digest' => self::digest([
                $this->pins($recipe, $profile), $php, in_array($role, $recipe->phpPolicy->pcovProfiles, true),
                $instances === null ? null : InstanceHostIntent::withoutAdmission($instances),
                $runtime, $executor ? NodeExecutorInput::for($node) : null,
                $renderedFiles, $phpRuntime, $roleUnits, $sharedFiles, $this->files($root, $files),
            ])];
        }

        return $roles;
    }

    /**
     * @param  array<int,string>  $names
     * @return array<string,ToolPin>
     */
    private function pins(MachineRecipe $recipe, array $names): array
    {
        return array_intersect_key($recipe->tools, array_flip($names));
    }

    /**
     * @param  list<string>  $patterns
     * @return array<string,string>
     */
    private function files(string $root, array $patterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root.'/'.$pattern) ?: [] as $file) {
                $result[substr($file, strlen($root) + 1)] = hash_file('sha256', $file) ?: throw new \RuntimeException('Cannot hash a release input.');
            }
        }

        return $result;
    }

    private static function digest(mixed $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
