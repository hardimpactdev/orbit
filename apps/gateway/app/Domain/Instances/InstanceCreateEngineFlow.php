<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/**
 * A container clone's `instance:create` on the state machine (ADR 0271; docs/cli/instance/container/create.mmd). It runs the
 * admitted clone intent (`direct_provisioning.container`) as the executor's `instance` family:
 *
 * - at once, on the Node: `clone`, the copy of the default's seed with its ACL, NIC, T3 proxy and route rule; `fetch`, the branch
 *   into the Node's mirror; and for a nested-fleet copy `copy_volume`, its own inner volume from the same seed;
 * - `prepare` (the copy, after `clone`): its files before the first start, its settings merged into the seed's;
 * - `start` (after `prepare` and `copy_volume`): the copy's own volume in its disk, the network guard (for a nested-fleet copy its
 *   exact config, devices and volumes), the start, its names;
 * - after `start`, side by side: `club_key` (a nested-fleet copy), its nested Gateway's Club key in its `/run`; and `checkout`
 *   (after `fetch` too), the branch, a task copy's clean, the merged `.env`;
 * - `t3` (after `checkout`): Conn or T3 running, its sessions sealed to the Gateway, its project;
 * - `stop`, only after a failed step: the copy stops and stays for a retry or a destroy.
 *
 * A clone of a nested-fleet default whose seed holds Orbit's tunnel boot unit does not wait for its fleet: its result says
 * `fleet_start: background`, and the projection admits `instance.fleet` beside the completion ({@see InstanceFleetOperation};
 * docs/reference/development-containers.md#a-clone-starts-its-fleet). Any other nested-fleet clone keeps the completion's `clone-fleet`.
 *
 * The create's address is left to its completion (`route_publication: deferred`), as for a create whose Route Nodes were held.
 * The completion starts the Processes and publishes the address, as before. A clone this flow does not cover starts on Swamp
 * ([EngineUnsupported]): a branch selection, attached Node services, a CI hold, or an already active create.
 */
final readonly class InstanceCreateEngineFlow implements EngineDefinition
{
    /** Each step's chart label, so a failure records the step the Swamp flow would name. */
    private const array Labels = ['clone' => 'clone-container', 'fetch' => 'clone-container', 'copy_volume' => 'clone-container', 'prepare' => 'clone-container',
        'start' => 'clone-container', 'club_key' => 'clone-club-key', 'checkout' => 'clone-t3', 't3' => 'clone-t3'];

    /** The result's `failed_step` and code for a failed step, as the Swamp result names the phase (swamp result.ts). */
    private const array Failures = ['club_key' => ['workspace', 'instance.create_workspace_failed'], 'checkout' => ['workspace', 'instance.create_workspace_failed'],
        't3' => ['t3', 'instance.create_agent_failed']];

    /** The first line of Orbit's proxy drop-ins (instance_node_services.py `MARKER`). */
    private const string ProxyMarker = "# Managed by Orbit: attached Node service.\n";

    public function __construct(private DevelopmentInstanceCreateProjection $projection, private MachineBus $bus) {}

    public function steps(Operation $operation): array
    {
        $container = self::container($operation);
        $club = isset($container['club_key']);
        $volume = isset($container['inner_volume']);
        $node = ValidatedData::integer($operation->node_id);
        $id = (string) ValidatedData::integer($operation->target_id);
        // A Node step names its part of the Node, which the executor locks: the parts of one create, and other creates, run side by side.
        $part = static fn (string $scope): string => "{$node}:{$scope}";
        $default = $volume ? ValidatedData::string(ValidatedData::object(ValidatedData::object($container['clone']))['inner_volume'] ?? null) : '';
        // `club_key` only writes inside `/run` and restarts the inner nested Gateway's serve, disjoint from checkout and T3.
        // Use its own Node-part target once the Node's executor reports `instance.club_key` in `node_targets`, else keep the copy lock.
        $clubTarget = $club && in_array('instance.club_key', $this->bus->nodeTargetActions($node), true) ? $part('club-'.$id) : $id;

        return [
            new StepSpec('clone', $node, 'instance.clone', $part('clone-'.$id), budgetMs: 420_000, resendMs: 60_000),
            // Publish `copy_volume` (no external I/O) before `fetch`, so even a cold GitHub token mint never delays volume work.
            ...($volume ? [new StepSpec('copy_volume', $node, 'instance.copy_volume', $part('volume-'.$id), budgetMs: 300_000, resendMs: 60_000)] : []),
            new StepSpec('fetch', $node, 'instance.fetch_branch', $part('fetch-'.$id), budgetMs: 200_000, resendMs: 60_000),
            new StepSpec('prepare', $node, 'instance.prepare', $id, ['clone'], budgetMs: 180_000, resendMs: 30_000),
            new StepSpec('start', $node, 'instance.start', $id, ['prepare', ...($volume ? ['copy_volume'] : [])], budgetMs: 180_000, resendMs: 30_000),
            ...($club ? [new StepSpec('club_key', $node, 'instance.club_key', $clubTarget, ['start'], budgetMs: 60_000, resendMs: 30_000)] : []),
            // The default's one spare for the next copy, beside the rest: the create neither waits for it nor fails by it. One part per
            // default, so its spares are made one at a time.
            ...($volume ? [new StepSpec('spare', $node, 'instance.spare_volume', $part('spare-'.$default), ['start'], budgetMs: 600_000, resendMs: 120_000,
                optional: true)] : []),
            new StepSpec('checkout', $node, 'instance.checkout', $id, ['start', 'fetch'], budgetMs: 180_000, resendMs: 30_000),
            new StepSpec('t3', $node, 'instance.t3', $id, ['checkout'], budgetMs: 180_000, resendMs: 30_000),
            // Only after a failed step, and only once the copy exists: it stops and stays for a retry or a destroy.
            new StepSpec('stop', $node, 'instance.stop', $id, ['clone'], budgetMs: 90_000, resendMs: 45_000, onFailure: true),
        ];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $container = self::container($operation);
        $clone = ValidatedData::object($container['clone']);
        $t3 = ValidatedData::object($clone['t3']);
        // Only the steps that render the Instance's own files read it.
        $instance = static fn (): Instance => Instance::query()->findOrFail($operation->target_id);
        $where = ['project' => ValidatedData::string($container['project']), 'name' => ValidatedData::string($container['name'])];

        return match ($step) {
            'clone' => [...$where, 'source' => $clone['source'], 'snapshot' => $clone['snapshot'],
                'acl' => ['name' => $container['acl'], 'rules' => $container['acl_rules']], 'nic_acl' => $container['nic_acl'], 'address' => $container['address'],
                'config' => (object) ValidatedData::object($container['config']), 'root' => ['pool' => $container['root_disk_pool'], 'size' => $container['root_disk_size']],
                'filters' => InstanceHostIntent::NicFilters,
                'proxy' => ['device' => $t3['device'], 'listen' => 'tcp:'.ValidatedData::string($t3['listen']).':'.ValidatedData::integer($t3['port']),
                    'connect' => 'tcp:'.ValidatedData::string($container['address']).':'.ValidatedData::integer($t3['internal_port'])],
                'routes' => self::routes($container),
                // A nested-fleet copy's container and volume come from one seed take: no newer seed in place of the admitted one.
                ...(isset($container['inner_volume']) ? ['exact_seed' => true] : [])],
            'copy_volume' => [...$where, 'source' => $clone['source'], 'snapshot' => $clone['snapshot'], ...self::innerVolume($container, $clone)],
            'spare' => ['project' => $where['project'], 'source' => $clone['source'], 'snapshot' => $clone['snapshot'],
                ...array_intersect_key(self::innerVolume($container, $clone), array_flip(['volume_source', 'pool']))],
            'stop' => $where,
            'fetch' => ['mirror' => $container['mirror'], 'repository' => $container['repository'], 'branch' => $container['branch'],
                'protocol' => ValidatedData::stringList($container['protocols'])[0],
                'credential' => ['blob' => ['content' => app(InstanceGitHubReadTokens::class)->line($operation)]]],
            'prepare' => [...$where, 'units' => array_map(static fn (mixed $unit): string => ValidatedData::string(ValidatedData::object($unit)['name']),
                self::rows($container['processes'] ?? [])),
                'process_ids' => array_map(static fn (mixed $unit): string => (string) ValidatedData::integer(ValidatedData::object($unit)['id']), self::rows($container['processes'] ?? [])),
                'app_account' => ValidatedData::string(ValidatedData::object($container['app_account'])['path']),
                // The clone's files are kilobytes and an order holds 4 KB: the list goes as one blob, its contents inline.
                'files_blob' => ['blob' => ['content' => json_encode($this->files($operation, $instance(), $container, $clone), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]]],
            'start' => [...$where, 'source' => $clone['source'], 'network' => $container['network'], 'nic' => 'eth0',
                'hostname' => ValidatedData::string(ValidatedData::object($container['machine_name'])['hostname']),
                // A seed whose own swap ran with this create's swap file is converted already (prepare reads it from the seed's manifest).
                ...((self::prepared($run)['app_account_current'] ?? false) === true ? []
                    : ['app_account' => ValidatedData::string(ValidatedData::object($container['app_account'])['path'])]),
                'private_names' => array_map(static fn (mixed $name): array => ['host' => ValidatedData::object($name)['host'], 'name' => ValidatedData::object($name)['name']],
                    self::rows($container['private_names'] ?? [])), ...self::allowed($container),
                ...(isset($container['inner_volume']) ? ['volume' => ['device' => ValidatedData::object($container['inner_volume'])['device'],
                    'name' => ValidatedData::object($container['inner_volume'])['name']],
                    // The two copies ran side by side; the start compares the seed take each read with the seed's own.
                    'seed_take' => ['snapshot' => $clone['snapshot'], 'clone' => self::taken($run, 'clone'), 'volume' => self::taken($run, 'copy_volume')]] : [])],
            'club_key' => [...$where, ...array_intersect_key(ValidatedData::object($container['club_key']), array_flip(['directory', 'file', 'owner', 'group', 'tmpfiles', 'gateway', 'serve_unit'])),
                // Read at the step, as Swamp reads it at its run's start; without a value nothing is written.
                ...(($key = NestedFleetClubKey::value()) === null ? [] : ['key' => ['blob' => ['content' => $key]]])],
            'checkout' => [...$where, 'instance_id' => ValidatedData::integer($operation->target_id), 'workspace' => $container['workspace'], 'branch' => $container['branch'],
                ...self::start($run, $clone, ValidatedData::string($container['mirror'])),
                'environment_file' => $container['environment_file'], 'group' => ValidatedData::integer(ValidatedData::object($container['workspace_ids'])['group']),
                'environment' => ['blob' => ['content' => app(DevelopmentCreateEnvironment::class)->content($instance())]],
                ...(($clone['clean_workspace'] ?? false) === true && ! self::seedClean($run, $clone) ? ['clean' => true] : []),
                ...(isset($operation->input['environment_clone']) ? ['merge' => ValidatedData::object($operation->input['environment_clone'])] : [])],
            't3' => [...$where, 'unit' => $t3['unit'], 't3' => $t3['t3'], 'port' => $t3['internal_port'], 'workspace' => $container['workspace'],
                'title' => $t3['title'], 'project_mark' => $t3['project_mark'], 'seal_to' => ExecutorOrderSigner::fromConfig()->sealRecipient(),
                // A reserved clone's pairing link comes with its sessions, so it is ready to pair; only an executor that takes it is asked.
                ...(($pairing = app(InstanceLifecycleContributions::class)->createPairing($operation)) !== null
                    && in_array('instance.t3_pairing', $this->bus->nodeFeatures(ValidatedData::integer($operation->node_id)), true) ? ['pairing' => $pairing] : [])],
            default => throw new LogicException("Step [{$step}] is not an instance step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException("Step [{$step}] runs on a Node.");
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        [$session, $pairing] = [null, null];
        if ($run->failed() === null && $run->succeeded()) {
            [$result, $session, $pairing] = $this->result($operation, $run);
            $data = ['status' => 'succeeded', 'error_code' => null, 'result' => $result];
        } else {
            $data = self::ended($operation, $run);
        }
        // A failed fetch's read credential goes before the end is recorded, outside its transaction: removing it again on a retry
        // changes nothing.
        if ($run->failed()?->name === 'fetch') {
            app(InstanceGitHubReadTokens::class)->forget($operation);
        }
        // The copy started before the create failed. Its `stop` step stopped it for a retry or a destroy; when that failed, it runs,
        // and the record says so, in the transaction of the end.
        $power = $data['status'] !== 'succeeded' && ($run->steps['start'] ?? null)?->status === 'done'
            ? (($run->steps['stop'] ?? null)?->status === 'done' ? InstanceRuntimePowerState::Stopped : InstanceRuntimePowerState::Running) : null;
        $this->projection->apply($operation, [...$data, ...$run->times()], $session, $pairing, $power);
    }

    /**
     * The end of a create that did not succeed: a failed step's code, the step the Swamp result names and its chart label, or a cancel.
     *
     * @return array<string, mixed>
     */
    public static function ended(Operation $operation, EngineRun $run): array
    {
        $failed = $run->failed();
        if ($failed !== null) {
            [$step, $code] = self::Failures[$failed->name] ?? ['clone', 'instance.create_clone_failed'];

            return ['status' => 'failed', 'error_code' => $failed->error_code ?? $code,
                'failure' => ['failed_step' => self::Labels[$failed->name] ?? $failed->name, 'stderr_recorded' => false],
                // The engine never publishes the address: a failed create leaves it to a retry's completion, as a succeeded one does.
                'result' => ['ready' => false, 'instance_id' => $operation->target_id, 'failed_step' => $step, 'error_code' => $failed->error_code ?? $code,
                    'route_publication' => 'deferred',
                    ...self::observed($run)]];
        }

        return ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []];
    }

    /**
     * The clone intent this flow runs, or [EngineUnsupported] for a create it does not cover.
     *
     * @return array<string, mixed>
     */
    private static function container(Operation $operation): array
    {
        $input = $operation->input;
        $plan = ValidatedData::object($input['direct_provisioning'] ?? []);
        $container = $plan['container'] ?? null;
        if (! is_array($container) || ! is_array($container['clone'] ?? null)) {
            throw new EngineUnsupported('Only a container clone runs on the engine.');
        }
        $clone = ValidatedData::object($container['clone']);
        foreach ([
            'already active' => ($plan['already_active'] ?? false) === true,
            'a branch selection' => isset($input['branch_selection']) || isset($container['branch_selection']),
            'Node services' => ($container['node_services'] ?? []) !== [],
            'a CI hold' => isset($clone['ci_hold']),
        ] as $case => $present) {
            if ($present) {
                throw new EngineUnsupported("A clone with {$case} runs on Swamp.");
            }
        }

        self::routes($container);

        return $container;
    }

    /**
     * A nested-fleet copy's own inner volume and the default's volume it copies.
     *
     * @param  array<string, mixed>  $container
     * @param  array<string, mixed>  $clone
     * @return array<string, mixed>
     */
    private static function innerVolume(array $container, array $clone): array
    {
        $inner = ValidatedData::object($container['inner_volume']);

        return ['device' => $inner['device'], 'volume_source' => ValidatedData::string($clone['inner_volume'] ?? null), 'volume' => $inner['name'],
            'pool' => $inner['pool'], 'size' => $inner['size']];
    }

    /**
     * A nested-fleet copy's exact config, devices and volumes for the start guard (instance_nested.py), or nothing.
     *
     * @param  array<string, mixed>  $container
     * @return array{allowed?: array<string, mixed>}
     */
    private static function allowed(array $container): array
    {
        if (! isset($container['allowed_devices'])) {
            return [];
        }
        $inner = isset($container['inner_volume']) ? ValidatedData::object($container['inner_volume']) : null;
        $root = ['type' => 'disk', 'path' => '/', 'pool' => $container['root_disk_pool'], 'size' => $container['root_disk_size']];

        return ['allowed' => ['config' => (object) ValidatedData::object($container['config']),
            'devices' => (object) [...ValidatedData::object($container['allowed_devices']), 'root' => $root],
            'volumes' => (object) ($inner === null ? [] : [ValidatedData::string($inner['device']) => [...ValidatedData::object($inner['config']), 'size' => $inner['size']]])]];
    }

    /**
     * What the Node steps observed for the create's result, as the Swamp result names it: a task copy's `workspace_cleaned` from its
     * checkout, and a nested-fleet copy's Club key word, which the projection maps to `nested_fleet_club_key`.
     *
     * @return array{workspace_cleaned?: array<string, mixed>, club_key?: string}
     */
    public static function observed(EngineRun $run): array
    {
        $output = static function (string $step) use ($run): array {
            $decoded = json_decode($run->output($step) ?? 'null', true);

            return is_array($decoded) ? $decoded : [];
        };
        $cleaned = $output('checkout')['cleaned'] ?? null;
        $club = $output('club_key')['club_key'] ?? null;

        return [...(is_array($cleaned) ? ['workspace_cleaned' => ValidatedData::object($cleaned)] : []), ...(is_string($club) ? ['club_key' => $club] : [])];
    }

    /** The seed take that a copy step read before it copied, or empty when it said none. */
    private static function taken(EngineRun $run, string $step): string
    {
        $output = json_decode($run->output($step) ?? 'null', true);

        return is_array($output) && is_string($output['seed_taken_at'] ?? null) ? $output['seed_taken_at'] : '';
    }

    /**
     * Whether the clone's fleet starts in the background: a clone of a nested-fleet default, from a seed whose tunnel unit boots it
     * (prepare's `fleet_tunnel`), and an engine that runs `instance.fleet` on the Node.
     */
    /**
     * Whether the seed holds the clone's services part: the clone has no Process, unit or web config of its own, its checkout kept the
     * seed's commit, so root's workspace pass has nothing to give, and the seed's services digest is the clone's own
     * (InstanceContainer::servicesDigest).
     *
     * @param  array<string, mixed>  $checkout
     */
    private static function seedServices(Operation $operation, EngineRun $run, array $checkout): bool
    {
        $container = self::container($operation);
        $clone = ValidatedData::object($container['clone']);

        return ($container['processes'] ?? []) === [] && ($container['units'] ?? []) === [] && ($container['web_config'] ?? null) === null
            && is_string($clone['seed_commit'] ?? null) && ($checkout['commit'] ?? null) === $clone['seed_commit']
            && (self::prepared($run)['services_digest'] ?? null) === InstanceContainer::servicesDigest($container);
    }

    private static function background(Operation $operation, EngineRun $run): bool
    {
        $clone = ValidatedData::object(self::container($operation)['clone']);

        return ($clone['fleet'] ?? false) === true && (self::prepared($run)['fleet_tunnel'] ?? false) === true && InstanceFleetOperation::runs($operation);
    }

    /**
     * What prepare read from the seed's manifest.
     *
     * @return array<string, mixed>
     */
    private static function prepared(EngineRun $run): array
    {
        $output = json_decode($run->output('prepare') ?? 'null', true);

        return is_array($output) ? $output : [];
    }

    /**
     * Whether a task copy's tree is clean without its own clean: the seed recorded a clean workspace, and the checkout keeps the
     * seed's commit. A branch at another commit, or a newer seed than the admitted one, may hold files that the seed's commit ignored.
     *
     * @param  array<string, mixed>  $clone
     */
    private static function seedClean(EngineRun $run, array $clone): bool
    {
        $fetched = json_decode($run->output('fetch') ?? 'null', true);
        $copied = json_decode($run->output('clone') ?? 'null', true);
        $commit = is_array($fetched) ? ($fetched['commit'] ?? null) : null;
        $snapshot = is_array($copied) ? ($copied['snapshot'] ?? null) : null;

        return (self::prepared($run)['workspace_clean'] ?? false) === true
            && ($commit === null || $commit === ($clone['seed_commit'] ?? null))
            && ($snapshot === null || $snapshot === ($clone['snapshot'] ?? null));
    }

    /** @return list<mixed> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * The copy's ufw route rules, from the admitted firewall rows.
     *
     * @param  array<string, mixed>  $container
     * @return list<array<string, mixed>>
     */
    private static function routes(array $container): array
    {
        $routes = [];
        foreach (self::rows($container['firewall'] ?? []) as $raw) {
            $rule = ValidatedData::object($raw);
            if (($rule['route'] ?? null) !== 'yes' || ($rule['rule'] ?? null) !== 'allow' || ($rule['proto'] ?? null) !== 'tcp') {
                throw new EngineUnsupported('A firewall row other than a TCP route allow runs on Swamp.');
            }
            $routes[] = ['interface_in' => $rule['interface_in'], 'interface_out' => $rule['interface_out'], 'from' => $rule['from_ip'],
                'to' => $rule['to_ip'], 'port' => (int) ValidatedData::string($rule['to_port']), 'comment' => $rule['comment']];
        }

        return $routes;
    }

    /**
     * Where the branch starts: the mirror's branch after a fetch put it there, or the seed's commit.
     *
     * @param  array<string, mixed>  $clone
     * @return array{expected_commit: string, mirror?: string}
     */
    private static function start(EngineRun $run, array $clone, string $mirror): array
    {
        $fetched = json_decode($run->output('fetch') ?? '{}', true);
        $commit = is_array($fetched) ? ($fetched['commit'] ?? null) : null;

        return is_string($commit)
            ? ['expected_commit' => $commit, 'mirror' => $mirror]
            : ['expected_commit' => ValidatedData::string($clone['seed_commit'])];
    }

    /**
     * The copy's files before its first start, in the order the clone writes them: boot files, the model environment, T3's
     * `before_start` files with the file T3's unit waits for last, the agent folder and its links, and the mail units.
     *
     * @param  array<string, mixed>  $container
     * @param  array<string, mixed>  $clone
     * @return list<array<string, mixed>>
     */
    private function files(Operation $operation, Instance $instance, array $container, array $clone): array
    {
        // Inside the one `files_blob`, every content is inline.
        $blob = static fn (string $content): string => $content;
        $items = [];
        foreach (self::rows($clone['boot_files'] ?? []) as $raw) {
            $file = ValidatedData::object($raw);
            $items[] = match (true) {
                isset($file['source']) => ['trust' => ['source' => $file['source'], 'path' => $file['path'], 'hash_directory' => $file['hash_directory'],
                    'uid' => $file['uid'], 'gid' => $file['gid'], 'mode' => $file['mode']]],
                isset($file['entry']) => ['nss' => array_intersect_key($file, array_flip(['home', 'directories', 'path', 'entry', 'uid', 'gid', 'mode',
                    'directory_mode', 'library', 'version']))],
                default => ['file' => ['path' => $file['path'], 'uid' => $file['uid'], 'gid' => $file['gid'], 'mode' => $file['mode'],
                    'content' => $blob(ValidatedData::string($file['content'] ?? ''))]],
            };
        }
        $model = ValidatedData::object($clone['model_environment']);
        $key = app(ContainerModelRoute::class)->key();
        $content = app(InstanceContainer::class)->modelEnvironment($instance->runtime()->firstOrFail(), $key);
        $items[] = ['file' => ['path' => $model['path'], 'uid' => $model['uid'], 'gid' => $model['gid'], 'mode' => $model['mode'], 'content' => $blob($content), 'parents' => true]];
        $condition = ValidatedData::object($clone['t3'])['condition'] ?? null;
        $files = array_values(array_filter(self::rows($clone['files'] ?? []), static fn (mixed $file): bool => (ValidatedData::object($file)['before_start'] ?? false) === true));
        usort($files, static fn (mixed $a, mixed $b): int => (int) (ValidatedData::object($a)['path'] === $condition) <=> (int) (ValidatedData::object($b)['path'] === $condition));
        $last = [];
        foreach ($files as $raw) {
            $file = ValidatedData::object($raw);
            // The Node merges the settings into the copy's own as the other writers do; the key rides in the blob that holds the model
            // environment too, and goes in place of the intent's value.
            $item = isset($file['json'])
                ? ['json' => ['path' => $file['path'], 'uid' => $file['uid'], 'gid' => $file['gid'], 'mode' => $file['mode'],
                    'json' => (object) ValidatedData::object($file['json']), 'key_value' => $model['key_value'] ?? null, 'key' => $key]]
                : ['file' => ['path' => $file['path'], 'uid' => $file['uid'], 'gid' => $file['gid'], 'mode' => $file['mode'],
                    'content' => $blob(ValidatedData::string($file['content'] ?? '')), 'parents' => true]];
            $file['path'] === $condition ? $last[] = $item : $items[] = $item;
        }
        $agent = $clone['agent_instructions'] ?? null;
        if (is_array($agent)) {
            $items[] = ['folder' => ['path' => $agent['folder'], 'files' => array_map(static fn (mixed $file): array => ['path' => ValidatedData::object($file)['path'],
                'content' => $blob(ValidatedData::string(ValidatedData::object($file)['content']))], self::rows($agent['files'] ?? []))]];
        }
        foreach (self::rows($clone['links'] ?? []) as $raw) {
            $link = ValidatedData::object($raw);
            $items[] = ['link' => ['path' => $link['path'], 'target' => $link['target'], 'owner' => ['uid' => 2750, 'gid' => 2750]]];
        }
        foreach (self::rows($container['mail_units'] ?? []) as $raw) {
            $unit = ValidatedData::object($raw);
            $name = ValidatedData::string($unit['name']);
            $items[] = ['file' => ['path' => '/etc/systemd/system/'.$name, 'uid' => 0, 'gid' => 0, 'mode' => '0644', 'content' => $blob(ValidatedData::string($unit['content']))]];
            if (str_ends_with($name, '.socket')) {
                $items[] = ['link' => ['path' => '/etc/systemd/system/sockets.target.wants/'.$name, 'target' => '/etc/systemd/system/'.$name,
                    'owner' => ['uid' => 0, 'gid' => 0]]];
            }
        }

        // The proxy bypass for units and login shells, after T3's files, which may share T3's unit folder (instance_node_services.py).
        $proxy = $clone['proxy_environment'] ?? null;
        $after = [];
        if (is_array($proxy)) {
            $assignments = implode(' ', array_map(static fn (string $key): string => $key.'='.ValidatedData::string($proxy[$key] ?? ''), ['NO_PROXY', 'no_proxy']));
            foreach (['/etc/systemd/system.conf.d/99-orbit-node-services.conf' => "[Manager]\nDefaultEnvironment={$assignments}\n",
                '/etc/profile.d/99-orbit-node-services.sh' => "export {$assignments}\n"] as $path => $text) {
                $after[] = ['file' => ['path' => $path, 'uid' => 0, 'gid' => 0, 'mode' => '0644', 'content' => $blob(self::ProxyMarker.$text)]];
            }
        }

        return [...$items, ...$last, ...$after];
    }

    /**
     * The succeeded create's result as the Swamp flow builds it, the Gateway's T3 session from the sealed `t3` output, and T3's answer
     * to the pairing link the step asked for, if it did. Neither secret enters the result.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>|null}
     */
    private function result(Operation $operation, EngineRun $run): array
    {
        $json = static fn (?string $text): array => ValidatedData::object(json_decode($text ?? '{}', true, flags: JSON_THROW_ON_ERROR));
        $checkout = $json($run->output('checkout'));
        $t3 = $json($run->output('t3'));
        $sealed = $json(ExecutorOrderSigner::fromConfig()->open(ValidatedData::string($t3['sealed'] ?? null)));
        $environment = $json(ValidatedData::string($sealed['environment'] ?? null));
        $gateway = ValidatedData::object($sealed['gateway'] ?? []);
        $body = $json(ValidatedData::string($gateway['body'] ?? null));

        return [[
            'ready' => true, 'instance_id' => $operation->target_id, 'commit' => ValidatedData::string($checkout['commit'] ?? null),
            'environment' => $checkout['environment'] ?? null,
            'route_publication' => 'deferred',
            ...self::observed($run),
            // The fleet starts beside the completion only from a seed that holds Orbit's tunnel boot unit, and only when the engine runs its wait.
            ...(self::background($operation, $run) ? ['fleet_start' => 'background'] : []),
            // The seed holds the clone's services part: its completion has nothing to do for it.
            ...(self::seedServices($operation, $run, $checkout) ? ['services' => 'seed'] : []),
            't3' => ['environment_id' => $environment['environmentId'] ?? null, 'server_version' => $environment['serverVersion'] ?? null,
                'expires_at' => $body['expiresAt'] ?? null],
        ], ValidatedData::string($gateway['token'] ?? null), is_array($sealed['pairing'] ?? null) ? ValidatedData::object($sealed['pairing']) : null];
    }
}
