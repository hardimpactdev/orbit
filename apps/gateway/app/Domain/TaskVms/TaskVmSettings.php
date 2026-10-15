<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The validated `task_vms` config and Node-owned host intent. Resolve it from the container: validation runs once
 * per request or queued job. The defaults live in
 * `config/task_vms.php`; this class only validates, with Laravel's validator, and each host and the UpCloud overflow
 * validate their own entry ({@see TaskVmHost::fromConfig()}, {@see TaskVmUpCloud::fromConfig()}). The scheduler reads
 * the raw config leniently through {@see self::scheduled()}, so an invalid value never stops it.
 *
 * Every value is validated whenever it is set, also while task VMs are off, so an operator can
 * prepare hosts and the hub before enabling claims. An unset value stays absent: no hosts, a null
 * Cluster, a null origin. `enabled` gates behaviour only, and requires the Cluster, the origin and
 * at least one host.
 *
 * The reserved WireGuard range is always a private network. As soon as task VMs are enabled or the
 * Cluster, the origin or a host is set, it must also lie inside the Gateway's VPN subnet, and no
 * bridge may overlap that subnet. With nothing set, the Gateway reads no VPN setting, so a fleet on
 * another subnet keeps working: a range outside the subnet reserves no fleet address.
 */
final readonly class TaskVmSettings
{
    /**
     * @param  list<TaskVmHost>  $hosts
     * @param  list<string>  $piModels
     * @param  list<int>  $mediumProjectIds
     * @param  array<int, list<string>>  $topologyPaths  Project id => path patterns that need a declared topology
     */
    public function __construct(
        public bool $enabled,
        public ?int $devClusterId,
        public string $wireguardRange,
        public array $hosts,
        public ?string $modelProxyOrigin,
        public ?string $piArtifactPath,
        public ?string $piArtifactSha256,
        public array $piModels,
        public int $parkAfterMinutes = 30,
        public ?TaskVmUpCloud $upcloud = null,
        public array $mediumProjectIds = [],
        public array $topologyPaths = [],
    ) {}

    /** @throws TaskVmException `task_vm.invalid_config` for the first invalid value */
    public static function fromConfig(): self
    {
        $config = self::config();
        $enabled = ($config['enabled'] ?? null) === true;
        $range = TaskVmConfig::wireguardRange($config['wireguard_range'] ?? null);
        $clusterId = TaskVmConfig::filled($config['dev_cluster_id'] ?? null);
        $origin = TaskVmConfig::filled($config['model_proxy_origin'] ?? null);
        $scalars = TaskVmConfig::validate(['dev_cluster_id' => $clusterId, 'model_proxy_origin' => $origin], [
            'dev_cluster_id' => $clusterId === null ? [] : ['required', 'integer', 'min:1'],
            'model_proxy_origin' => $origin === null ? [] : ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (self::origin($value) === null) {
                    $fail('model_proxy_origin must be an http(s) origin, for example http://10.44.0.3:8317.');
                }
            }],
        ], [
            'dev_cluster_id' => 'dev_cluster_id must be a Cluster id.',
            'model_proxy_origin' => 'model_proxy_origin must be an http(s) origin, for example http://10.44.0.3:8317.',
        ]);

        $hosts = self::configured($config) ? self::hosts($config['incus'] ?? [], self::vpnSubnet($range)) : [];

        if ($enabled) {
            match (true) {
                $clusterId === null => throw TaskVmConfig::invalid('dev_cluster_id is required while task VMs are enabled.'),
                $origin === null => throw TaskVmConfig::invalid('model_proxy_origin is required while task VMs are enabled.'),
                $hosts === [] => throw TaskVmConfig::invalid('incus.hosts needs at least one host while task VMs are enabled.'),
                default => null,
            };
        }

        $pi = is_array($config['pi'] ?? null) ? $config['pi'] : [];
        $artifactPath = TaskVmConfig::filled($pi['artifact_path'] ?? null);
        $artifactSha256 = TaskVmConfig::filled($pi['artifact_sha256'] ?? null);
        $values = [
            'pi' => ['artifact_path' => $artifactPath, 'artifact_sha256' => $artifactSha256, 'models' => $pi['models'] ?? null],
            'park_after_minutes' => $config['park_after_minutes'] ?? null,
            'medium_projects' => $config['medium_projects'] ?? null,
            'topology_paths' => $config['topology_paths'] ?? null,
        ];
        $together = 'pi.artifact_path and pi.artifact_sha256 must be set together.';
        $topologyPaths = 'topology_paths must be a JSON object from Project id to a list of path patterns.';
        $valid = TaskVmConfig::validate($values, [
            'pi.artifact_path' => $artifactPath === null ? ['required_with:pi.artifact_sha256'] : ['required', 'string', 'starts_with:/', 'not_regex:/\x00/'],
            'pi.artifact_sha256' => $artifactSha256 === null ? ['required_with:pi.artifact_path'] : ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'pi.models' => ['present', 'list'],
            'pi.models.*' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}\z/D'],
            'park_after_minutes' => ['required', 'integer', 'between:1,10080'],
            'medium_projects' => ['present', 'list'],
            'medium_projects.*' => ['integer:strict', 'min:1'],
            'topology_paths' => ['present', static function (string $attribute, mixed $value, Closure $fail) use ($topologyPaths): void {
                if (! self::validTopologyPaths($value)) {
                    $fail($topologyPaths);
                }
            }],
        ], [
            'pi.artifact_path.required_with' => $together,
            'pi.artifact_sha256.required_with' => $together,
            'pi.artifact_path' => 'pi.artifact_path must be an absolute path.',
            'pi.artifact_sha256' => 'pi.artifact_sha256 must be a lowercase SHA-256 digest.',
            'pi.models' => 'pi.models must be a JSON list of model names.',
            'pi.models.*' => 'pi.models must contain only model names.',
            'park_after_minutes' => 'park_after_minutes must be a whole number of minutes from 1 to 10080.',
            'medium_projects' => 'medium_projects must be a JSON list of Project ids.',
            'medium_projects.*' => 'medium_projects must be a JSON list of Project ids.',
            'topology_paths' => $topologyPaths,
        ]);
        $topology = [];
        foreach (is_array($values['topology_paths']) ? $values['topology_paths'] : [] as $projectId => $patterns) {
            $topology[(int) $projectId] = array_values(array_unique(TaskVmConfig::strings($patterns)));
        }

        return new self(
            enabled: $enabled,
            devClusterId: $clusterId === null ? null : $scalars->integer('dev_cluster_id'),
            wireguardRange: $range->value(),
            hosts: $hosts,
            modelProxyOrigin: self::origin($origin),
            piArtifactPath: is_string($artifactPath) ? $artifactPath : null,
            piArtifactSha256: is_string($artifactSha256) ? $artifactSha256 : null,
            piModels: array_values(array_unique(TaskVmConfig::strings($values['pi']['models']))),
            parkAfterMinutes: $valid->integer('park_after_minutes'),
            upcloud: TaskVmUpCloud::fromConfig(is_array($config['upcloud'] ?? null) ? $config['upcloud'] : [], $enabled),
            mediumProjectIds: array_values(array_unique(TaskVmConfig::ints($values['medium_projects']))),
            topologyPaths: $topology,
        );
    }

    /**
     * The task VM switches that the scheduler reads every minute. It reads the raw config leniently, so an invalid
     * value never stops the scheduler: a job it queues validates through the settings and fails loudly.
     */
    public static function scheduled(): TaskVmSchedule
    {
        $enabled = Config::get('task_vms.enabled') === true;
        $hosts = self::hostEntries();

        return new TaskVmSchedule(
            enabled: $enabled,
            upcloudConfigured: filled(Config::get('task_vms.upcloud.token_file')),
            warmVmsWanted: $enabled && array_any($hosts, static fn (array $host): bool => is_int($host['warm_vms'] ?? null) && $host['warm_vms'] > 0),
        );
    }

    /**
     * The reserved range that every Node's Caddy refuses on every site except the Gateway API and Reverb, or
     * null while task VMs are not configured. It reads only what the guard needs, so another invalid task VM
     * value never blocks a Caddy build. Once task VMs are configured, an invalid range throws: the guard
     * fails closed.
     */
    public static function caddyGuardRange(): ?string
    {
        $config = self::config();

        return self::configured($config) ? TaskVmConfig::wireguardRange($config['wireguard_range'] ?? null)->value() : null;
    }

    public function host(int $nodeId): TaskVmHost
    {
        foreach ($this->hosts as $host) {
            if ($host->nodeId === $nodeId) {
                return $host;
            }
        }

        throw new TaskVmException(TaskVmError::UnknownHost, "Node [{$nodeId}] is not a task VM host.");
    }

    public function hasHost(int $nodeId): bool
    {
        return array_any($this->hosts, static fn (TaskVmHost $host): bool => $host->nodeId === $nodeId);
    }

    /** The host that the nightly refresh builds on and syncs from: the first configured host, or null without hosts. */
    public function sourceHost(): ?TaskVmHost
    {
        return $this->hosts[0] ?? null;
    }

    /** @return array<string, mixed> the raw `task_vms` config */
    private static function config(): array
    {
        $config = Config::get('task_vms');

        $config = is_array($config) ? $config : [];
        $config['incus'] = [...(is_array($config['incus'] ?? null) ? $config['incus'] : []), 'hosts' => self::hostEntries()];

        return $config;
    }

    /**
     * Task VMs are enabled, or the Cluster, the origin, or an active Node's host intent is set.
     *
     * @param  array<string, mixed>  $config
     */
    private static function configured(array $config): bool
    {
        return ($config['enabled'] ?? null) === true
            || TaskVmConfig::filled($config['dev_cluster_id'] ?? null) !== null
            || TaskVmConfig::filled($config['model_proxy_origin'] ?? null) !== null
            || (is_array($config['incus'] ?? null) ? $config['incus']['hosts'] ?? null : null) !== [];
    }

    /** @return list<array<string, mixed>> */
    private static function hostEntries(): array
    {
        $entries = [];
        foreach (Node::query()->where('status', LifecycleStatus::Active->value)->orderBy('id')->get() as $node) {
            $settings = $node->settings;
            if (is_array($settings) && is_array($settings['task_vms'] ?? null)) {
                $entries[] = [...$settings['task_vms'], 'node_id' => $node->id];
            }
        }

        return $entries;
    }

    private static function vpnSubnet(Ipv4Subnet $range): Ipv4Subnet
    {
        $value = resolve(VpnSettings::class)->subnet();
        try {
            $subnet = Ipv4Subnet::from($value);
        } catch (InvalidArgumentException) {
            throw TaskVmConfig::invalid("the VPN subnet [{$value}] is not an IPv4 network.");
        }
        if ($range->prefixLength() <= $subnet->prefixLength() || ! $subnet->contains($range->networkAddress())) {
            throw TaskVmConfig::invalid("wireguard_range must be a smaller network inside the VPN subnet [{$subnet->value()}].");
        }

        return $subnet;
    }

    /**
     * @param  mixed  $incus  `task_vms.incus`: the host entries and their defaults
     * @return list<TaskVmHost>
     */
    private static function hosts(mixed $incus, Ipv4Subnet $vpnSubnet): array
    {
        $entries = is_array($incus) ? $incus['hosts'] ?? null : null;
        TaskVmConfig::validate(['hosts' => $entries], ['hosts' => ['present', 'list']], ['hosts' => 'incus.hosts must be a JSON list.']);
        $defaults = is_array($incus) && is_array($incus['host_defaults'] ?? null) ? $incus['host_defaults'] : [];
        $hosts = [];
        foreach (is_array($entries) ? array_values($entries) : [] as $index => $entry) {
            $host = TaskVmHost::fromConfig($entry, $index, $vpnSubnet, $defaults);
            if (isset($hosts[$host->nodeId])) {
                throw TaskVmConfig::invalid("incus.hosts.{$index}.node_id is listed twice.");
            }
            $hosts[$host->nodeId] = $host;
        }

        foreach ($hosts as $host) {
            foreach ($hosts as $other) {
                if ($host->nodeId !== $other->nodeId && (Ipv4Subnet::from($host->cidr)->contains($other->bridgeAddress()) || Ipv4Subnet::from($other->cidr)->contains($host->bridgeAddress()))) {
                    throw TaskVmConfig::invalid('task_vms host bridges must not overlap.');
                }
            }
        }

        return array_values($hosts);
    }

    /** The origin without a trailing slash, or null when the value is not an http(s) origin. */
    private static function origin(mixed $value): ?string
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * A JSON object from Project id to a list of path patterns, in the syntax of file deliverables. A key is a JSON
     * string, so `{"46": [...]}`; PHP decodes a numeric key to an integer.
     */
    private static function validTopologyPaths(mixed $value): bool
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }

        return array_all($value, static fn (mixed $patterns, int|string $projectId): bool => is_int($projectId) && $projectId >= 1
            && is_array($patterns) && array_is_list($patterns)
            && array_all($patterns, static fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== '' && ! str_starts_with($pattern, '/')));
    }
}
