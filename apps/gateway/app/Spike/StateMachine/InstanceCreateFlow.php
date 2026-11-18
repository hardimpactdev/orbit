<?php

declare(strict_types=1);

namespace App\Spike\StateMachine;

use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\ContainerModelRoute;
use App\Models\Instance;
use App\Models\Operation;
use App\Spike\Nats\Bus;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * SPIKE (slices 2 and 3): a container clone's `instance.create` as a state machine, from the intent the Gateway already admitted
 * (`input.direct_provisioning.container` and `input.route_create`). The Node work of instance_clone.py's container phase
 * becomes Incus primitives, each on one resource; the Route runs beside it with RouteCreateFlow's own steps. The slice ends
 * when T3 answers and the site is published. The guest part (checkout, T3 sessions, register) is slice 3, so the operation
 * closes as `spike.slice2_end` with its timings, and `instance:destroy` removes the copy.
 *
 * Left out of slice 2, each named so the gap is visible: the seed's unit cleanup (no Processes here), the NSS store and trust
 * bundle, proxy drop-ins, the NIC guard check, private names, Node services, mail, the branch. JSON files replace the seed's
 * file instead of merging into it.
 */
final class InstanceCreateFlow
{
    private const array RouteKinds = ['csr', 'sign', 'cert', 'current', 'copy', 'acurrent', 'trust', 'caddy', 'records', 'catalog', 'restart', 'probe'];

    public static function start(Operation $create): Operation
    {
        $c = $create->input['direct_provisioning']['container'] ?? null;
        if (! is_array($c) || ! is_array($c['clone'] ?? null) || ! empty($c['inner_volume']) || ! empty($c['processes'])) {
            throw new RuntimeException('spike: only a plain container clone runs on the engine');
        }
        $n = (int) $create->node_id;
        $clone = $c['clone'];
        $step = static fn (string $action, array $after, int $budget = 60): array => ['node' => $n, 'action' => $action, 'after' => $after, 'budget_s' => $budget];

        $steps = [
            'acl-0' => $step('incus.acl_ensure', []),
            'clone-0' => $step('incus.copy', ['acl-0'], 180) + ['resend_s' => 30],
            't3dev-0' => $step('incus.device_ensure', ['clone-0']),
            'mid-0' => $step('incus.file_put', ['clone-0']),
            'model-0' => $step('incus.file_put', ['clone-0']),
        ];
        $files = ['mid-0', 'model-0'];
        foreach ($clone['boot_files'] ?? [] as $i => $item) {
            if (isset($item['content'], $item['path'])) {
                $steps["boot-$i"] = $step('incus.file_put', ['clone-0']);
                $files[] = "boot-$i";
            }
        }
        $condition = null;
        foreach ($clone['files'] ?? [] as $i => $item) {
            if (($item['before_start'] ?? false) !== true) {
                continue;
            }
            // T3's unit waits for its condition file; it is written last, after every other file.
            if ($item['path'] === $clone['t3']['condition']) {
                $condition = "file-$i";

                continue;
            }
            $steps["file-$i"] = $step(isset($item['json']) ? 'container.json_merge' : 'incus.file_put', ['clone-0']);
            $files[] = "file-$i";
        }
        // clone.links are the agent part's (it makes their folders as `agent`): slice 3.
        if ($condition !== null) {
            $steps[$condition] = $step('incus.file_put', $files);
            $files[] = $condition;
        }
        $steps['unshift-0'] = $step('incus.rootfs_unshift', $files, 120) + ['resend_s' => 30];
        $before = ['unshift-0', 't3dev-0'];
        foreach ($c['firewall'] ?? [] as $i => $rule) {
            $steps["fw-$i"] = $step('ufw.ensure_route', []);
            $before[] = "fw-$i";
        }
        $steps['start-0'] = $step('incus.start', $before, 120) + ['resend_s' => 30];
        $steps['t3wait-0'] = $step('http.wait', ['start-0'], 60) + ['resend_s' => 40];

        // Slice 3, the guest part (instance_clone_guest.py), split by resource, beside T3's boot. The executor bounds its own fan-out
        // by the Node's CPUs, so the flow need not.
        $steps['checkout-0'] = $step('container.git_checkout', ['start-0']);
        $steps['env-0'] = $step('dotenv.merge', ['checkout-0']);
        $steps['gitcfg-0'] = $step('container.git_config', ['start-0']);
        $folders = ['/home/agent/.claude', '/home/agent/.t3/userdata'];
        foreach ($clone['links'] ?? [] as $link) {
            $folders[] = dirname($link['path']);
        }
        $agentDirs = [];
        foreach (array_values(array_unique($folders)) as $i => $folder) {
            $steps["adir-$i"] = $step('container.dir', ['start-0']);
            $agentDirs[] = "adir-$i";
        }
        if (isset($clone['agent_instructions'])) {
            $steps['agentdir-0'] = $step('container.dir_sync', ['start-0']);
            $agentDirs[] = 'agentdir-0';
        }
        foreach ($clone['links'] ?? [] as $i => $link) {
            $steps["link-$i"] = $step('incus.file_link', $agentDirs);
        }
        $steps['t3unit-0'] = $step('container.unit', ['start-0']);
        $steps['sess-0'] = $step('t3.session_issue', ['t3unit-0']);
        $steps['sess-1'] = $step('t3.session_issue', ['t3unit-0']);
        $steps['project-0'] = $step('t3.project_add', ['t3wait-0', 'checkout-0']);
        $guest = array_keys(array_filter($steps, static fn (array $s, string $k): bool => in_array(explode('-', $k)[0],
            ['checkout', 'env', 'gitcfg', 'adir', 'agentdir', 'link', 'sess', 'project'], true), ARRAY_FILTER_USE_BOTH));
        // On the Gateway: both sessions checked against the running T3 over its published port, then the create registers it.
        $steps['check-0'] = ['node' => 0, 'action' => 'gateway.t3_check', 'after' => [...$guest, 't3wait-0'], 'budget_s' => 30];

        if (isset($create->input['route_create'])) {
            $steps += RouteCreateFlow::steps($create->input['route_create']);
        }

        // Spike fault injection: e.g. {"fail_step": ["probe-0"]}.
        $faults = (array) \Illuminate\Support\Facades\Cache::get('spike:create-faults', []);
        $engine = Engine::start($steps, $faults, self::class, ['route_operation' => $create->id, 'create_operation' => $create->id]);
        $create->refresh()->forceFill(['state' => [...($create->state ?? []), 'swamp_run_id' => 'spike-sm-'.$engine->id]])->save();

        return $engine;
    }

    public static function input(string $name, Operation $engine): array
    {
        [$kind, $key] = explode('-', $name, 2);
        if (in_array($kind, self::RouteKinds, true)) {
            return RouteCreateFlow::input($name, $engine);
        }
        $create = self::create($engine);
        $c = $create->input['direct_provisioning']['container'];
        $clone = $c['clone'];
        $node = (int) $engine->input['steps'][$name]['node'];
        $blob = static fn (string $content): array => Bus::putContent($node, $content);
        $put = static fn (array $item, string $content): array => ['instance' => $c['name'], 'path' => $item['path'], 'blob' => $blob($content),
            'uid' => (int) $item['uid'], 'gid' => (int) $item['gid'], 'mode' => (string) $item['mode']];

        return match ($kind) {
            'acl' => ['name' => $c['acl'], 'rules' => $c['acl_rules']],
            'clone' => ['source' => $clone['source'], 'snapshot' => $clone['snapshot'], 'name' => $c['name'], 'config' => $c['config'],
                'devices' => [
                    'root' => ['type' => 'disk', 'path' => '/', 'pool' => $c['root_disk_pool'], 'size' => $c['root_disk_size']],
                    'eth0' => ['ipv4.address' => $c['address'], 'security.acls' => $c['nic_acl'].','.$c['acl'],
                        'security.acls.default.egress.action' => 'reject', 'security.acls.default.ingress.action' => 'reject',
                        'security.ipv4_filtering' => 'true', 'security.mac_filtering' => 'true'],
                ]],
            't3dev' => ['instance' => $c['name'], 'device' => $clone['t3']['device'], 'config' => ['type' => 'proxy',
                'listen' => 'tcp:'.$clone['t3']['listen'].':'.$clone['t3']['port'],
                'connect' => 'tcp:'.$c['address'].':'.$clone['t3']['internal_port'], 'nat' => 'true']],
            // The machine id is the copy's own Incus UUID, which the copy step answered.
            'mid' => $put(['path' => '/etc/machine-id', 'uid' => 0, 'gid' => 0, 'mode' => '0444'],
                str_replace('-', '', trim((string) $engine->state['steps']['clone-0']['output']))."\n"),
            'model' => $put($clone['model_environment'], app(InstanceContainer::class)->modelEnvironment(
                Instance::query()->findOrFail($create->target_id)->runtime()->firstOrFail(), app(ContainerModelRoute::class)->key())),
            'boot' => $put($clone['boot_files'][(int) $key], $clone['boot_files'][(int) $key]['content']),
            'file' => isset($clone['files'][(int) $key]['json'])
                ? ['instance' => $c['name'], 'path' => $clone['files'][(int) $key]['path'], 'json' => (object) $clone['files'][(int) $key]['json'],
                    'uid' => (int) $clone['files'][(int) $key]['uid'], 'gid' => (int) $clone['files'][(int) $key]['gid'], 'mode' => (string) $clone['files'][(int) $key]['mode']]
                : $put($clone['files'][(int) $key], $clone['files'][(int) $key]['content']),
            'link' => ['instance' => $c['name'], 'path' => $clone['links'][(int) $key]['path'], 'target' => $clone['links'][(int) $key]['target']],
            'unshift' => ['instance' => $c['name'], 'source' => $clone['source']],
            'fw' => array_intersect_key($c['firewall'][(int) $key] + ['interface_out' => null],
                array_flip(['rule', 'interface_in', 'interface_out', 'from_ip', 'to_ip', 'to_port', 'proto', 'comment'])),
            'start' => ['instance' => $c['name']],
            'checkout' => ['instance' => $c['name'], 'workspace' => $c['workspace'], 'branch' => $c['branch'],
                'commit' => (string) $clone['seed_commit'], 'instance_id' => (int) $c['instance_id'],
                'exclude' => substr($c['environment_file'], strlen($c['workspace']) + 1)],
            'env' => ['instance' => $c['name'], 'path' => $c['environment_file'],
                'blob' => $blob(app(\App\Domain\Instances\Environment\DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($create->target_id))),
                'own' => $create->input['environment_clone']['own'] ?? [], 'drop' => $create->input['environment_clone']['drop'] ?? [],
                'guard' => $create->input['environment_clone']['guard'] ?? [], 'hosts' => $create->input['environment_clone']['hosts'] ?? null,
                'shared' => $create->input['environment_clone']['shared'] ?? [], 'uid' => 2750, 'gid' => (int) $c['workspace_ids']['group'], 'mode' => '0640'],
            'gitcfg' => ['instance' => $c['name'], 'replace_all' => ['safe.directory' => $c['workspace']],
                'set' => ['user.name' => 'Orbit Agent', 'user.email' => 'agent@orbit.local']],
            'adir' => ['instance' => $c['name'], 'path' => self::agentFolders($clone)[(int) $key], 'uid' => 2750, 'mode' => '0700'],
            'agentdir' => ['instance' => $c['name'], 'folder' => $clone['agent_instructions']['folder'],
                'files' => array_map(static fn (array $f): array => ['path' => $f['path'], 'blob' => $blob($f['content'])], $clone['agent_instructions']['files'])],
            't3unit' => ['instance' => $c['name'], 'unit' => $clone['t3']['unit']],
            'sess' => ['instance' => $c['name'], 't3' => $clone['t3']['t3'], 'label' => $key === '0' ? 'Orbit Gateway' : 'Orbit Swamp'],
            'project' => ['instance' => $c['name'], 't3' => $clone['t3']['t3'], 'workspace' => $c['workspace'], 'title' => $clone['t3']['title'],
                'mark' => $clone['t3']['project_mark']],
            't3wait' => ['url' => $clone['t3']['url'], 'seconds' => (int) $clone['t3']['ready_seconds']],
        };
    }

    public static function local(string $name, Operation $engine): string
    {
        if (! str_starts_with($name, 'check-')) {
            return RouteCreateFlow::local($name, $engine);
        }
        // Both sessions against the running T3, from the Gateway over T3's published port: authenticated, read and write.
        $t3 = self::create($engine)->input['direct_provisioning']['container']['clone']['t3'];
        $state = $engine->state['steps'];
        $environment = \Illuminate\Support\Facades\Http::timeout(5)->get($t3['url'].'/.well-known/t3/environment')->throw()->json();
        $sessions = [];
        foreach (['gateway' => 'sess-0', 'swamp' => 'sess-1'] as $who => $step) {
            $body = \Illuminate\Support\Facades\Http::timeout(5)->withToken((string) $state[$step]['output'])->get($t3['url'].'/api/auth/session')->throw()->json();
            if (($body['authenticated'] ?? false) !== true || array_diff(['access:read', 'access:write'], $body['scopes'] ?? []) !== []) {
                throw new RuntimeException("instance.t3_session_unauthenticated: {$who}");
            }
            $sessions[$who] = ['expires_at' => (string) ($body['expiresAt'] ?? '')];
        }
        if ($state['sess-0']['output'] === $state['sess-1']['output']) {
            throw new RuntimeException('instance.t3_sessions_equal');
        }

        return json_encode(['environment_id' => $environment['environmentId'], 'server_version' => $environment['serverVersion'],
            'expires_at' => $sessions['gateway']['expires_at']], JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function agentFolders(array $clone): array
    {
        $folders = ['/home/agent/.claude', '/home/agent/.t3/userdata'];
        foreach ($clone['links'] ?? [] as $link) {
            $folders[] = dirname($link['path']);
        }

        return array_values(array_unique($folders));
    }

    /**
     * The create ends through its own projection, as after a Swamp run: the result names the commit, the merged `.env` key names and
     * the registered T3 server. SPIKE: the Gateway session's token reaches the projection through a one-minute cache entry, not the
     * Swamp vault the projection reads today (the engine has the token from the receipt; there is no vault hop).
     */
    public static function finished(Operation $engine, string $status): void
    {
        $create = self::create($engine);
        $steps = $engine->state['steps'];
        $failed = collect($steps)->filter(fn (array $s): bool => $s['status'] === 'failed')->keys()->first();
        $at = fn (int $ms): string => Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond');
        $timings = collect($steps)->map(fn (array $s): ?int => isset($s['done_ms'], $s['first_sent_ms']) ? $s['done_ms'] - $s['first_sent_ms'] : null)->all();
        $times = ['run_id' => 'spike-sm-'.$engine->id, 'started_at' => $at((int) $engine->state['started_ms']),
            'finished_at' => $at($engine->state['finished_ms'] ?? Engine::nowMs())];
        if ($status !== 'succeeded') {
            $failure = ['failed_step' => $failed, 'error' => $steps[$failed]['error'] ?? null, 'spike_engine' => $engine->id, 'spike_timings_ms' => $timings];
            // As Swamp's create: a clone keeps a Route it published; a Route that failed partway is taken back before the failure is
            // recorded, and the recorded failure stays the original one.
            $routeSteps = array_filter($steps, static fn (string $k): bool => in_array(explode('-', $k)[0], self::RouteKinds, true), ARRAY_FILTER_USE_KEY);
            $routeFailed = array_filter($routeSteps, static fn (array $s): bool => $s['status'] === 'failed') !== [];
            $routeStarted = array_filter($routeSteps, static fn (array $s): bool => ($s['sends'] ?? []) !== []) !== [];
            if ($routeFailed && $routeStarted) {
                RouteWithdrawFlow::start($create, $failure);

                return;
            }
            self::fail($create, $failure, $times);

            return;
        }
        $server = json_decode((string) $steps['check-0']['output'], true, flags: JSON_THROW_ON_ERROR);
        $key = 'nckrtl-instance-t3-spike-'.$engine->id.'-gateway-token';
        \Illuminate\Support\Facades\Cache::put('spike:t3-session:'.$key, (string) $steps['sess-0']['output'], 60);
        app(DevelopmentInstanceCreateProjection::class)->apply($create, ['status' => 'succeeded', ...$times, 'result' => [
            'instance_id' => $create->target_id, 'ready' => true, 'name_converted_from' => null,
            'commit' => trim((string) $steps['checkout-0']['output']),
            't3' => [...$server, 'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => $key]],
            'environment' => json_decode((string) $steps['env-0']['output'], true),
            'spike_engine' => $engine->id, 'spike_timings_ms' => $timings,
        ]]);
    }

    /** The create's failure through its own projection; the error names the failed step's code. */
    public static function fail(Operation $create, array $failure, array $times): void
    {
        app(DevelopmentInstanceCreateProjection::class)->apply($create, ['status' => 'failed', ...$times,
            'error_code' => 'spike.create_failed', 'result' => $failure]);
    }

    private static function create(Operation $engine): Operation
    {
        return Operation::query()->findOrFail($engine->input['context']['create_operation']);
    }
}
