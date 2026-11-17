<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\GatewayPrivateDnsInput;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleUnits;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
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
        'swamp/workflows/workflow-operation-node-converge-direct.yaml',
        'swamp/extensions/behaviors/operations/ansible-input/write.ts',
        'swamp/extensions/behaviors/operations/ansible-input/converge_input.ts',
        'swamp/extensions/behaviors/operations/ansible-input/converge_result.ts',
        'swamp/ansible/build_vars.py',
        'swamp/ansible/operation-node-converge.yml',
        'swamp/ansible/tasks/node-converge-role.yml',
        'swamp/ansible/tasks/node-role-runtime.yml',
        'swamp/ansible/tasks/node-converge-role-check.yml',
        'swamp/ansible/tasks/check-files.yml',
        'swamp/ansible/tasks/check-runtime.yml',
        'swamp/ansible/tasks/node-converge-software*.yml',
        'swamp/ansible/tasks/node-converge-apt*.yml',
        'swamp/ansible/tasks/artifact-fetch.yml',
        'swamp/ansible/library/orbit_artifact.py',
        'swamp/ansible/transport-exit-codes.json',
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

    public function host(?string $root = null): string
    {
        $root ??= dirname(base_path(), 2);
        $recipe = new RecipeLoader()->source($root.'/'.self::Recipe);
        $raw = ValidatedData::object(json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR));

        $roleTools = [];
        foreach (RoleName::cases() as $role) {
            if ($role !== RoleName::Gateway) {
                array_push($roleTools, ...$this->software($raw, $role));
            }
        }
        // The build profile includes role-owned pins. Common tools and pins outside the automatic role scope still require approval.
        $hostTools = array_diff($recipe->profile('base'), $roleTools);
        array_push($hostTools, ...ValidatedData::stringList($raw['common']));

        return self::digest([$raw['ubuntu'], $raw['architectures'], $raw['artifact_mirror'], $raw['git'],
            $this->pins($recipe, $hostTools), $this->files($root, self::HostFiles)]);
    }

    /**
     * @param  list<string>|null  $selected
     * @param  array<int,array<string,mixed>>  $renderedRoles  An admission supplies the exact plans it will dispatch.
     * @return array<int, array{role:string,digest:string}> Assignment IDs keep a recreated role from inheriting an apply.
     */
    public function roles(Node $node, bool $release = true, ?array $selected = null, ?string $root = null, array $renderedRoles = []): array
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
            $render = $renderedRoles[$assignment->id] ?? null;
            if ($render === null) {
                $runtimeSpec = app(NodeRoleRuntimeInput::class)->capture($node, $assignment, publicOnly: true);
                $dns = $assignment->role === RoleName::Gateway ? app(GatewayPrivateDnsInput::class)->capture($node) : null;
                $render = app(NodeDesiredConvergeInput::class)->roleResources(
                    app(NativeNodeRoleAddInput::class)->nodeResources($node, $assignment->role), $recipe, $assignment->role);
                $render['runtime'] = app(NativeNodeRoleRuntimeInput::class)->capture(
                    $node, $assignment, ['runtime' => $runtimeSpec, 'gateway_dns' => $dns], null, converge: true)['intent'];
            }
            $resources = array_intersect_key($render, array_flip(['directories', 'files', 'php', 'services']));
            $runtime = ValidatedData::object($render['runtime']);
            if (isset($runtime['instances'])) {
                $runtime['instances'] = InstanceHostIntent::withoutAdmission(ValidatedData::object($runtime['instances']));
            }
            if (is_array($runtime['swamp'])) {
                unset($runtime['swamp']['agent_sha256'], $runtime['swamp']['ssh_execution']);
            }

            $software = $this->software($raw, $assignment->role);
            $executor = isset(NodeExecutorInput::RoleFamilies[$role]) ? NodeExecutorInput::for($node) : null;
            $roleUnits = NodeRoleUnits::for($node, [$role]);
            $files = [
                ...$this->roleFiles($assignment->role),
                ...($resources['php'] !== [] ? ['swamp/ansible/tasks/php.yml'] : []),
                ...(in_array('vp', $software, true) ? ['swamp/ansible/tasks/node-converge-javascript.yml', 'swamp/ansible/tasks/javascript-version.yml'] : []),
                ...(in_array('chrome-headless-shell', $software, true) ? ['swamp/ansible/tasks/node-converge-browser.yml'] : []),
                ...($executor !== null || $roleUnits !== [] ? ['swamp/ansible/tasks/node-converge-base.yml'] : []),
                ...($executor !== null ? ['swamp/ansible/tasks/node-executor.yml', 'swamp/ansible/tasks/node-converge-replace.yml'] : []),
                ...($hostRole === $assignment->role ? self::InstanceHostFiles : []),
            ];
            $roles[$assignment->id] = ['role' => $role, 'digest' => self::digest(self::ordered([
                $software, $this->pins($recipe, $software), in_array($role, $recipe->phpPolicy->pcovProfiles, true),
                in_array('composer', $software, true) ? $node->user : null,
                $resources, $runtime, $executor, $roleUnits, $sharedFiles, $this->files($root, $files),
            ]))];
        }

        return $roles;
    }

    /**
     * The automatic scope of node-converge-software-plan.yml. Host common tools are covered by the host baseline.
     *
     * @param  array<string,mixed>  $recipe
     * @return list<string>
     */
    private function software(array $recipe, RoleName $role): array
    {
        $names = ValidatedData::stringList(ValidatedData::object($recipe['roles'])[$role->value] ?? []);
        $php = ValidatedData::object($recipe['php']);
        $versions = ValidatedData::object($php['versions']);
        $packages = [];
        $tools = [];
        foreach ($names as $name) {
            if (str_starts_with($name, 'php:')) {
                array_push($packages, ...ValidatedData::stringList(ValidatedData::object($versions[substr($name, 4)])['packages']));
            } else {
                $tools[] = $name;
            }
        }
        if ($packages !== []) {
            array_push($packages, ...ValidatedData::stringList($php['shared']));
        }
        if ($role === RoleName::Swamp) {
            array_push($tools, 'deno', 'unzip');
        }

        return array_values(array_unique([...$tools, ...$packages]));
    }

    /** @return list<string> Files reached from the per-role branches of the converge play and its end checks. */
    private function roleFiles(RoleName $role): array
    {
        return match ($role) {
            RoleName::Swamp => ['swamp/ansible/tasks/node-converge-deno.yml', 'swamp/ansible/tasks/swamp-start-cache.yml',
                'swamp/ansible/swamp-worker-renewal.yml', 'swamp/ansible/tasks/swamp-worker-*.yml'],
            RoleName::WebSocket => ['swamp/ansible/tasks/websocket.yml'],
            RoleName::Metrics => ['swamp/ansible/tasks/metrics.yml'],
            RoleName::CiRunner => ['swamp/ansible/tasks/ci.yml', 'swamp/ansible/tasks/ci-bridge-read.yml',
                'swamp/ansible/tasks/proof-seed-certificate.yml', 'swamp/ansible/tasks/proof-seed-listener.yml',
                'swamp/extensions/behaviors/operations/node/ci_host_spec.ts'],
            RoleName::Gateway => ['swamp/ansible/operation-node-converge-local-dns.yml', 'swamp/ansible/tasks/gateway-retire.yml',
                'swamp/ansible/tasks/gateway-runtime.yml', 'swamp/ansible/tasks/gateway-dns.yml',
                'swamp/ansible/tasks/private-dns-listener.yml', 'swamp/ansible/tasks/private-dns-withdraw.yml'],
            // These roles use the common converge play and the branches selected by their rendered resources and software.
            RoleName::AppDev, RoleName::AppProd, RoleName::Vpn, RoleName::Router, RoleName::Ingress,
            RoleName::Database, RoleName::Analytics => [],
        };
    }

    /** Sort maps without changing the application order of lists. */
    private static function ordered(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::ordered(...), $value);
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
