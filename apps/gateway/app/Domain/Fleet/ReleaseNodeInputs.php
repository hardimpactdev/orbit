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
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Process;
use LogicException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Fingerprints of the declared inputs to release-time Node work. Agent and CLI delivery have their own pins.
 * Not final: a test decorates its reads to prove the fleet pass never runs them under the SQLite writer.
 */
readonly class ReleaseNodeInputs
{
    private const string Recipe = 'apps/gateway/resources/machine-software/core.yaml';

    /** These affect the host as a whole. A release may report their drift but may not apply them. */
    private const array HostFiles = [
        'apps/gateway/app/Domain/Nodes/NodeKernelPolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeUpdatePolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeNetworkConvergeInput.php',
        'swamp/ansible/tasks/node-kernel-policy.yml',
        'swamp/ansible/tasks/node-update-policy.yml',
    ];

    /** The Gateway runtime: node-role-runtime.yml applies it only on a Node with the `gateway` role, so only that Node's host input holds it. */
    private const array GatewayHostFiles = [
        'apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php',
        'swamp/ansible/tasks/gateway-runtime.yml',
    ];

    /** The network phase reconciles the target and can import the hub separately before a peer. */
    private const array HostNetworkTasks = [
        'swamp/ansible/tasks/node-converge-network.yml',
        'swamp/ansible/tasks/node-converge-hub.yml',
    ];

    /** The plays and materializer shared by every role converge. */
    private const array SharedRoleFiles = [
        'swamp/ansible/ansible.cfg',
        'swamp/workflows/workflow-operation-node-converge-direct.yaml',
        'swamp/extensions/behaviors/operations/ansible-input/write.ts',
        'swamp/extensions/behaviors/operations/ansible-input/converge_input.ts',
        'swamp/extensions/behaviors/operations/ansible-input/phase_code.ts',
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

    /**
     * production-release-host.yml, the files it installs from the Ansible tree, and the role files beside that tree which a converge
     * stages: `roleSiblings` in swamp/extensions/behaviors/operations/ansible-input/converge_input.ts.
     */
    public const array ProductionReleaseHostFiles = [
        'swamp/extensions/behaviors/operations/instance-deploy/vp_requirements.ts',
        'swamp/extensions/behaviors/operations/instance-vp-command/requirements.ts',
        'swamp/scripts/production-release-phase',
        'swamp/scripts/release-container.py',
        'swamp/ansible/tasks/production-release-host.yml',
        'swamp/ansible/files/production-release-host.py',
        'swamp/ansible/files/instance-release-*',
        'swamp/ansible/files/instance-control-*',
        'swamp/ansible/files/instance-legacy-schedule.py',
        'swamp/ansible/module_utils/orbit_route_withdraw.py',
    ];

    /** Shipped assets consumed by the shared Instance-host branch on app-dev and CI-only Nodes. */
    private const array InstanceHostFiles = [
        'swamp/ansible/tasks/instance-host*.yml',
        'swamp/ansible/tasks/instance-folder*.yml',
        'swamp/ansible/tasks/instance-bridge-*.yml',
        'swamp/ansible/tasks/instance-container-files.yml',
        'swamp/ansible/tasks/instance-containers-gone.yml',
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

    /** The host inputs that act on the Node. Without a Node, those of every Node without the `gateway` role. */
    public function host(?string $root = null, ?Node $node = null): string
    {
        return self::NodeScope.$this->hostDigest($root, $node !== null && $this->coversLegacy($node) ? self::GatewayHostFiles : []);
    }

    /** Whether the Node's host inputs are the same files the legacy digest covered: then that digest is the Node's own. */
    public function coversLegacy(Node $node): bool
    {
        return $node->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists();
    }

    /** Marks a host digest classified by Node; a bare digest is an approval recorded before. Drop after one release. */
    public const string NodeScope = 'node:';

    /** The digest before host inputs were classified by Node: every Node counted the Gateway runtime. Drop after one release. */
    public function legacyHost(?string $root = null): string
    {
        return $this->hostDigest($root, self::GatewayHostFiles);
    }

    /**
     * Writes a commit's host input files from the Git objects of the repository into an empty folder, so legacyHost() and host()
     * can read them. It needs no release checkout, so it works in the worktree and in the container release layout alike.
     */
    public function extractHostTree(string $repository, string $commit, string $folder): void
    {
        $archive = Process::run(['git', '-C', $repository, 'archive', '--format=tar', '-o', $folder.'/host.tar', $commit, '--',
            dirname(self::Recipe), 'apps/gateway/app/Domain/Nodes', 'swamp/ansible/tasks']);
        $extract = $archive->successful() ? Process::path($folder)->run(['tar', '-xf', 'host.tar']) : null;
        @unlink($folder.'/host.tar');
        if ($extract === null || ! $extract->successful()) {
            throw new RuntimeException('Cannot read the host inputs of a release commit.');
        }
        $core = $folder.'/'.self::Recipe;
        $previous = dirname($core).'/recipe.json';
        if (! is_file($core) && is_file($previous)) {
            $raw = ValidatedData::object(json_decode((string) file_get_contents($previous), true, flags: JSON_THROW_ON_ERROR));
            if (isset($raw['instances'])) {
                $instances = ValidatedData::object($raw['instances']);
                $agent = ValidatedData::object($instances['agent']);
                if (isset($agent['conn-server'])) {
                    $conn = ValidatedData::object($agent['conn-server']);
                    unset($conn['macos_arm64'], $conn['release_canary']);
                    $agent['conn-server'] = $conn;
                }
                $raw['instances'] = [...$instances, 'agent' => $agent];
            }
            file_put_contents($core, json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    /** @param list<string> $nodeFiles */
    private function hostDigest(?string $root, array $nodeFiles): string
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

        $network = [];
        foreach (self::HostNetworkTasks as $file) {
            $this->readNetworkTask($root, $file, $network);
        }
        ksort($network);

        return self::digest([$raw['ubuntu'], $raw['architectures'], $raw['artifact_mirror'], $raw['git'],
            $this->pins($recipe, $hostTools), $this->files($root, [...self::HostFiles, ...$nodeFiles]), $network]);
    }

    /** @param array<string, string> $files */
    private function readNetworkTask(string $root, string $file, array &$files): void
    {
        if (isset($files[$file])) {
            return;
        }
        $path = $root.'/'.$file;
        if (! is_file($path) || ($contents = file_get_contents($path)) === false) {
            throw new RuntimeException('Cannot read a host network task.');
        }
        $files[$file] = hash('sha256', $contents);
        foreach ($this->networkIncludes(Yaml::parse($contents)) as $include) {
            $this->readNetworkTask($root, dirname($file).'/'.$include, $files);
        }
    }

    /** @return iterable<string> */
    private function networkIncludes(mixed $tasks): iterable
    {
        if (! is_array($tasks) || ! array_is_list($tasks)) {
            throw new RuntimeException('Expected a host network task list.');
        }
        foreach ($tasks as $entry) {
            $task = ValidatedData::object($entry);
            foreach ($task as $name => $value) {
                if (in_array($name, ['block', 'rescue', 'always'], true)) {
                    yield from $this->networkIncludes($value);
                }
                if (in_array($name, ['ansible.builtin.include_tasks', 'ansible.builtin.import_tasks', 'include_tasks', 'import_tasks'], true)) {
                    $file = is_array($value) ? ($value['file'] ?? null) : $value;
                    if (! is_string($file) || preg_match('~\A(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.ya?ml\z~D', $file) !== 1) {
                        throw new RuntimeException('Host network tasks must include a literal relative task file.');
                    }
                    yield $file;
                }
            }
        }
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
            ->whereNotIn('role', app(RoleRegistry::class)->recordOnly())
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
            RoleName::AppProd => self::ProductionReleaseHostFiles,
            RoleName::Swamp => ['swamp/ansible/tasks/node-converge-deno.yml', 'swamp/ansible/tasks/swamp-start-cache.yml',
                'swamp/ansible/swamp-worker-renewal.yml', 'swamp/ansible/tasks/swamp-worker-*.yml', 'swamp/ansible/tasks/swamp-token-renewal-start.yml'],
            RoleName::WebSocket => ['swamp/ansible/tasks/websocket.yml'],
            RoleName::Metrics => ['swamp/ansible/tasks/metrics.yml'],
            RoleName::CiRunner => ['swamp/ansible/tasks/ci.yml', 'swamp/ansible/tasks/ci-bridge-read.yml',
                'swamp/ansible/tasks/proof-seed-certificate.yml', 'swamp/ansible/tasks/proof-seed-listener.yml',
                'swamp/extensions/behaviors/operations/node/ci_host_spec.ts'],
            RoleName::Gateway => ['swamp/ansible/operation-node-converge-local-dns.yml', 'swamp/ansible/tasks/gateway-retire.yml',
                'swamp/ansible/tasks/gateway-runtime.yml', 'swamp/ansible/tasks/gateway-nats.yml', 'swamp/ansible/tasks/gateway-dns.yml',
                'swamp/ansible/tasks/private-dns-listener.yml', 'swamp/ansible/tasks/private-dns-withdraw.yml'],
            // These roles use the common converge play and the branches selected by their rendered resources and software.
            RoleName::AppDev, RoleName::Vpn, RoleName::Router, RoleName::Ingress,
            RoleName::Database, RoleName::Analytics => [],
            // roles() leaves out a record-only role: it has no host input.
            RoleName::Agent => throw new LogicException('A record-only role has no release role input.'),
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
                $result[substr($file, strlen($root) + 1)] = hash_file('sha256', $file) ?: throw new RuntimeException('Cannot hash a release input.');
            }
        }

        return $result;
    }

    private static function digest(mixed $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
