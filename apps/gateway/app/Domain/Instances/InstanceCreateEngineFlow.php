<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
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
 * - `clone` and `fetch` (the Node): the copy of the default's seed with its ACL, NIC, T3 proxy and route rule; the branch
 *   into the Node's mirror, beside it;
 * - `prepare` (the copy): its files before the first start;
 * - `start` (the copy): the network guard, the start, its names;
 * - `checkout` (the copy): the branch, the merged `.env`;
 * - `t3` (the copy): T3 running, its sessions sealed to the Gateway, its project.
 *
 * The create's address is left to its completion (`route_publication: deferred`), as for a create whose Route Nodes were held.
 * A clone this flow does not cover starts on Swamp ([EngineUnsupported]): a nested-fleet default's copy (inner volume, Club
 * key), a task copy (`clean_workspace`), a branch selection, attached Node services, Processes, or an already active create.
 */
final readonly class InstanceCreateEngineFlow implements EngineDefinition
{
    /** Each step's chart label, so a failure records the step the Swamp flow would name. */
    private const array Labels = ['clone' => 'clone-container', 'fetch' => 'clone-container', 'prepare' => 'clone-container',
        'start' => 'clone-container', 'checkout' => 'clone-t3', 't3' => 'clone-t3'];

    /** The result's `failed_step` and code for a failed step (swamp result.ts). */
    private const array Failures = ['clone-container' => ['clone', 'instance.create_clone_failed'], 'clone-t3' => ['t3', 'instance.create_agent_failed']];

    /** The first line of Orbit's proxy drop-ins (instance_node_services.py `MARKER`). */
    private const string ProxyMarker = "# Managed by Orbit: attached Node service.\n";

    public function __construct(private DevelopmentInstanceCreateProjection $projection) {}

    public function steps(Operation $operation): array
    {
        self::container($operation);
        $node = ValidatedData::integer($operation->node_id);
        $id = (string) ValidatedData::integer($operation->target_id);

        return [
            new StepSpec('clone', $node, 'instance.clone', (string) $node, budgetMs: 420_000, resendMs: 60_000),
            new StepSpec('fetch', $node, 'instance.fetch_branch', (string) $node, budgetMs: 200_000, resendMs: 60_000),
            new StepSpec('prepare', $node, 'instance.prepare', $id, ['clone'], budgetMs: 180_000, resendMs: 30_000),
            new StepSpec('start', $node, 'instance.start', $id, ['prepare'], budgetMs: 180_000, resendMs: 30_000),
            new StepSpec('checkout', $node, 'instance.checkout', $id, ['start', 'fetch'], budgetMs: 180_000, resendMs: 30_000),
            new StepSpec('t3', $node, 'instance.t3', $id, ['checkout'], budgetMs: 180_000, resendMs: 30_000),
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
                'routes' => self::routes($container)],
            'fetch' => ['mirror' => $container['mirror'], 'repository' => $container['repository'], 'branch' => $container['branch'],
                'protocol' => ValidatedData::stringList($container['protocols'])[0],
                'credential' => ['blob' => ['content' => app(InstanceGitHubReadTokens::class)->line($operation)]]],
            'prepare' => [...$where, 'units' => array_map(static fn (mixed $unit): string => ValidatedData::string(ValidatedData::object($unit)['name']),
                self::rows($container['processes'] ?? [])), 'process_ids' => [],
                // The clone's files are kilobytes and an order holds 4 KB: the list goes as one blob, its contents inline.
                'files_blob' => ['blob' => ['content' => json_encode($this->files($operation, $instance(), $container, $clone), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]]],
            'start' => [...$where, 'source' => $clone['source'], 'network' => $container['network'], 'nic' => 'eth0',
                'hostname' => ValidatedData::string(ValidatedData::object($container['machine_name'])['hostname']),
                'app_account' => ValidatedData::string(ValidatedData::object($container['app_account'])['path']),
                'private_names' => array_map(static fn (mixed $name): array => ['host' => ValidatedData::object($name)['host'], 'name' => ValidatedData::object($name)['name']],
                    self::rows($container['private_names'] ?? []))],
            'checkout' => [...$where, 'instance_id' => ValidatedData::integer($operation->target_id), 'workspace' => $container['workspace'], 'branch' => $container['branch'],
                ...self::start($run, $clone, ValidatedData::string($container['mirror'])),
                'environment_file' => $container['environment_file'], 'group' => ValidatedData::integer(ValidatedData::object($container['workspace_ids'])['group']),
                'environment' => ['blob' => ['content' => app(DevelopmentCreateEnvironment::class)->content($instance())]],
                ...(isset($operation->input['environment_clone']) ? ['merge' => ValidatedData::object($operation->input['environment_clone'])] : [])],
            't3' => [...$where, 'unit' => $t3['unit'], 't3' => $t3['t3'], 'port' => $t3['internal_port'], 'workspace' => $container['workspace'],
                'title' => $t3['title'], 'project_mark' => $t3['project_mark'], 'seal_to' => ExecutorOrderSigner::fromConfig()->sealRecipient()],
            default => throw new LogicException("Step [{$step}] is not an instance step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException("Step [{$step}] runs on a Node.");
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $session = null;
        if ($failed === null && $run->succeeded()) {
            [$result, $session] = $this->result($operation, $run);
            $data = ['status' => 'succeeded', 'error_code' => null, 'result' => $result];
        } elseif ($failed !== null) {
            [$step, $code] = self::Failures[self::Labels[$failed->name] ?? 'clone-container'];
            $data = ['status' => 'failed', 'error_code' => $failed->error_code ?? $code,
                'failure' => ['failed_step' => self::Labels[$failed->name] ?? $failed->name, 'stderr_recorded' => false],
                'result' => ['ready' => false, 'instance_id' => $operation->target_id, 'failed_step' => $step, 'error_code' => $failed->error_code ?? $code]];
        } else {
            $data = ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []];
        }
        $this->projection->apply($operation, [...$data, ...$run->times()], $session);
        if ($data['status'] !== 'succeeded' && ($run->steps['start'] ?? null)?->status === 'done') {
            // The copy started before the create failed: it runs until a retry or a destroy, and the record says so.
            Instance::query()->findOrFail($operation->target_id)->runtime()->first()?->update(['power_state' => InstanceRuntimePowerState::Running]);
        }
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
            'a nested-fleet copy' => isset($container['inner_volume']) || isset($container['club_key']) || isset($clone['inner_volume']),
            'a task copy' => isset($clone['clean_workspace']),
            'a branch selection' => isset($input['branch_selection']) || isset($container['branch_selection']),
            'Node services' => ($container['node_services'] ?? []) !== [],
            'Processes' => ($container['processes'] ?? []) !== [],
            'a CI hold' => isset($clone['ci_hold']),
        ] as $case => $present) {
            if ($present) {
                throw new EngineUnsupported("A clone with {$case} runs on Swamp.");
            }
        }

        self::routes($container);

        return $container;
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
            // The key goes in place of the intent's value here, in the blob that holds the model environment too.
            $item = isset($file['json'])
                ? ['json' => ['path' => $file['path'], 'uid' => $file['uid'], 'gid' => $file['gid'], 'mode' => $file['mode'],
                    'json' => (object) InstanceContainer::withModelKey(ValidatedData::object($file['json']), $key)]]
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
     * The succeeded create's result as the Swamp flow builds it, and the Gateway's T3 session from the sealed `t3` output.
     *
     * @return array{0: array<string, mixed>, 1: string}
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
            't3' => ['environment_id' => $environment['environmentId'] ?? null, 'server_version' => $environment['serverVersion'] ?? null,
                'expires_at' => $body['expiresAt'] ?? null],
        ], ValidatedData::string($gateway['token'] ?? null)];
    }
}
