<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\SecretRedactor;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyTarget;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class IncusHost implements GuestTransport
{
    private const int GUEST_READINESS_POLL_INTERVAL_MICROSECONDS = 1_000_000;

    /** The Incus configuration keys a guest may set besides limits and ownership metadata. */
    private const array GUEST_CONFIGURATION_KEYS = ['cloud-init.user-data'];

    private const string DEFAULT_ROUTE_INTERFACE_RESOLUTION = 'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ]';

    private const string GLOBAL_IPV4_PROBE =
        self::DEFAULT_ROUTE_INTERFACE_RESOLUTION.' && ip -4 -o addr show dev "$interface" scope global';

    /** @var array<string, IncusInstance> */
    private array $ownedInstanceCache = [];

    /**
     * @param  array<string, string>  $ownershipMetadata
     */
    public function __construct(
        private readonly string $remote = 'local',
        private readonly string $project = 'default',
        private readonly string $pool = 'default',
        private readonly array $ownershipMetadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
        private readonly SecretRedactor $redactor = new SecretRedactor,
        private readonly int $guestReadinessTimeoutSeconds = 600,
    ) {
        $this->validateName($remote, 'remote');
        $this->validateName($project, 'project');
        $this->validateName($pool, 'storage pool');
        if ($guestReadinessTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Guest readiness timeout must be positive.');
        }

        if ($ownershipMetadata === []) {
            throw new RuntimeException('Incus ownership metadata cannot be empty.');
        }

        foreach ($ownershipMetadata as $key => $value) {
            $this->validateMetadata($key, $value);
        }
    }

    /** @return array<string, array<string, string>> */
    public function harnessVmMetadata(): array
    {
        $instances = [];
        foreach ($this->readJson(['list', "{$this->remote}:", '--format=json']) as $resource) {
            if (! is_array($resource) || ($resource['type'] ?? null) !== 'virtual-machine' || ! is_string($resource['name'] ?? null)) {
                continue;
            }
            $metadata = $this->metadata($resource);
            if (($metadata['user.orbit.e2e.owner'] ?? null) === 'orbit-e2e') {
                $instances[$resource['name']] = $metadata;
            }
        }

        return $instances;
    }

    /** @phpstan-impure */
    public function instance(string $name): ?IncusInstance
    {
        $this->validateName($name, 'instance');
        $resources = $this->readJson(['list', $this->target($name), '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                return $this->instanceFromResource($resource, $name);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, IncusInstance>
     */
    public function instances(array $names): array
    {
        $resources = $this->instanceInventory($names, 'inventory');
        $instances = [];
        foreach ($resources as $name => $resource) {
            $instances[$name] = $this->instanceFromResource($resource);
        }

        return $instances;
    }

    public function network(string $name): ?IncusNetwork
    {
        $this->validateName($name, 'network');
        $resources = $this->readJson(['network', 'list', "{$this->remote}:", '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                return new IncusNetwork(
                    $this->remote,
                    $this->project,
                    $name,
                    $this->metadata($resource),
                    $this->configuration($resource),
                    $this->usedBy($resource),
                );
            }
        }

        return null;
    }

    /** @return array<string, IncusNetwork> */
    public function networks(): array
    {
        $resources = $this->readJson(['network', 'list', "{$this->remote}:", '--format=json']);
        $networks = [];
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                throw new RuntimeException('Incus network inventory identity is invalid.');
            }
            $name = $resource['name'] ?? null;
            if (! is_string($name) || isset($networks[$name])) {
                throw new RuntimeException('Incus network inventory identity is invalid.');
            }
            $networks[$name] = new IncusNetwork(
                $this->remote,
                $this->project,
                $name,
                $this->metadata($resource),
                $this->configuration($resource),
                $this->usedBy($resource),
            );
        }

        return $networks;
    }

    /**
     * @param  array<array-key, mixed>  $resource
     * @return list<string>
     */
    private function usedBy(array $resource): array
    {
        $usedBy = $resource['used_by'] ?? [];
        if (! is_array($usedBy)) {
            throw new RuntimeException('Incus network inventory identity is invalid.');
        }
        $users = [];
        foreach ($usedBy as $user) {
            if (! is_string($user)) {
                throw new RuntimeException('Incus network inventory identity is invalid.');
            }
            $users[] = $user;
        }

        return $users;
    }

    public function imageFingerprint(string $alias, string $type = 'virtual-machine'): string
    {
        $this->validateImage($alias);
        [$remote, $selector] = $this->imageSelector($alias);
        $images = $this->readJson(['image', 'list', $remote, $selector, '--format=json']);
        $matches = [];
        foreach ($images as $image) {
            if (! is_array($image) || ($image['type'] ?? null) !== $type) {
                continue;
            }
            if (($image['fingerprint'] ?? null) === $selector) {
                $matches[] = $image;

                continue;
            }
            $aliases = $image['aliases'] ?? null;
            if (! is_array($aliases)) {
                continue;
            }
            if (array_any(
                $aliases,
                fn ($imageAlias) => is_array($imageAlias) && ($imageAlias['name'] ?? null) === $selector,
            )) {
                $matches[] = $image;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException("Incus image selector [{$alias}] did not identify exactly one {$type} image.");
        }
        $fingerprint = $matches[0]['fingerprint'] ?? null;

        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Incus returned an invalid image fingerprint.');
        }

        return $fingerprint;
    }

    /** @return array{remote:string,project:string,pool:string} */
    public function scope(): array
    {
        return ['remote' => $this->remote, 'project' => $this->project, 'pool' => $this->pool];
    }

    /** @param array<string, string> $configuration */
    public function createNetwork(string $name, array $configuration): IncusNetwork
    {
        $this->validateName($name, 'network');
        if (strlen($name) > 15) {
            throw new RuntimeException('Incus network names must be 15 ASCII characters or fewer.');
        }
        $this->validateStringMap($configuration, 'network configuration');
        $configuration = [...$configuration, ...$this->ownershipMetadata];
        $arguments = ['network', 'create', "{$this->remote}:{$name}"];
        foreach ($configuration as $key => $value) {
            $this->validateConfiguration($key, $value);
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments);

        return new IncusNetwork($this->remote, $this->project, $name, $this->e2eMetadata($configuration));
    }

    /**
     * Creates guests in one parallel batch. Each one gets its fixed Incus address and MAC on the network, the
     * harness ownership metadata, and, when given, the worktree mount. A container gets an unprivileged,
     * nesting-capable container; every other guest is a VM.
     *
     * @param  array<string, array{image:string,name:string,network:string,node:string,address:int,slot:int,container:bool,metadata:array<string,string>,config?:array<string,string>,mount?:array{device:string,source:string,path:string}}>  $guests
     * @return array<string, IncusInstance>
     */
    public function initGuests(array $guests): array
    {
        if ($guests === []) {
            throw new RuntimeException('Incus guest initialization batch must be non-empty.');
        }
        $commands = [];
        $mounts = [];
        $instances = [];
        $targets = [];
        foreach ($guests as $label => $guest) {
            $this->validateName($label, 'guest initialization label');
            $this->validateImage($guest['image']);
            $this->validateName($guest['name'], 'instance');
            $this->validateName($guest['network'], 'network');
            $this->validateName($guest['node'], 'node');
            if (isset($targets[$guest['name']])) {
                throw new RuntimeException('Incus guest initialization targets must be unique.');
            }
            $targets[$guest['name']] = true;
            $this->validateStringMap($guest['metadata'], 'creation metadata');
            foreach ($guest['metadata'] as $key => $value) {
                $this->validateMetadata($key, $value);
                if (array_key_exists($key, $this->ownershipMetadata)) {
                    throw new RuntimeException('Incus creation metadata cannot override ownership metadata.');
                }
            }
            [$remote, $selector] = $this->imageSelector($guest['image']);
            $container = $guest['container'];
            $arguments = [
                'init',
                $remote.$selector,
                $this->target($guest['name']),
                ...($container ? ['--config', 'security.privileged=false', '--config', 'security.nesting=true'] : ['--vm']),
                '--storage',
                $this->pool,
                '--config',
                'limits.cpu='.$this->incusLimit('cpu', '1'),
                '--config',
                'limits.memory='.$this->incusLimit('memory', '2GiB', $guest['node']),
                '--device',
                'root,pool='.$this->pool,
                '--device',
                'root,size='.$this->incusLimit('root_size', '16GiB'),
                '--device',
                'eth0,network='.$guest['network'],
                '--device',
                'eth0,ipv4.address='.TopologyTarget::ipv4For($guest['slot'], $guest['address']),
                '--device',
                'eth0,hwaddr='.$this->deterministicMac($guest['network'], $guest['node']),
            ];
            $disks = [];
            $mount = $guest['mount'] ?? null;
            if ($mount !== null) {
                $this->validateMount($mount);
                // `init --device` only overrides profile devices, so the mount is added after creation.
                $mounts[$label] = [
                    'config',
                    'device',
                    'add',
                    $this->target($guest['name']),
                    $mount['device'],
                    'disk',
                    "source={$mount['source']}",
                    "path={$mount['path']}",
                    ...($container ? ['shift=true'] : []),
                ];
                $disks[$mount['device']] = ['source' => $mount['source'], 'path' => $mount['path']];
            }
            foreach ([...$this->ownershipMetadata, ...$guest['metadata']] as $key => $value) {
                $arguments[] = '--config';
                $arguments[] = "{$key}={$value}";
            }
            foreach ($guest['config'] ?? [] as $key => $value) {
                if (! in_array($key, self::GUEST_CONFIGURATION_KEYS, true)) {
                    throw new RuntimeException("Incus guest configuration [{$key}] is not allowed.");
                }
                $arguments[] = '--config';
                $arguments[] = "{$key}={$value}";
            }
            $commands[$label] = $arguments;
            $instances[$label] = new IncusInstance(
                $this->remote,
                $this->project,
                $guest['name'],
                $this->pool,
                [...$this->ownershipMetadata, ...$guest['metadata']],
                network: $guest['network'],
                disks: $disks,
            );
        }
        $this->runParallel($commands, 900, failureMessage: 'Incus guest initialization batch failed');
        if ($mounts !== []) {
            $this->runParallel($mounts, 120, failureMessage: 'Incus worktree mount batch failed');
        }

        return $instances;
    }

    /**
     * Every image in the project: fingerprint, type, aliases, properties, and creation time.
     *
     * @return list<array{fingerprint:string,type:string,aliases:list<string>,properties:array<string,string>,created_at:string}>
     */
    public function images(): array
    {
        $images = [];
        foreach ($this->readJson(['image', 'list', "{$this->remote}:", '--format=json']) as $image) {
            $fingerprint = is_array($image) ? ($image['fingerprint'] ?? null) : null;
            if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new RuntimeException('Incus returned an invalid image inventory.');
            }
            $aliases = [];
            foreach (is_array($image['aliases'] ?? null) ? $image['aliases'] : [] as $alias) {
                if (is_array($alias) && is_string($alias['name'] ?? null)) {
                    $aliases[] = $alias['name'];
                }
            }
            $properties = [];
            foreach (is_array($image['properties'] ?? null) ? $image['properties'] : [] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $properties[$key] = $value;
                }
            }
            $images[] = [
                'fingerprint' => $fingerprint,
                'type' => is_string($image['type'] ?? null) ? $image['type'] : '',
                'aliases' => $aliases,
                'properties' => $properties,
                'created_at' => is_string($image['created_at'] ?? null) ? $image['created_at'] : '',
            ];
        }

        return $images;
    }

    /**
     * Stops nothing: the guest must already be stopped. Publishes its disk under `$alias` with the given
     * image properties and returns the new image's fingerprint.
     *
     * @param  array<string, string>  $properties
     */
    public function publish(string $instance, string $alias, array $properties, string $type): string
    {
        $this->validatedOwnedVm($instance);
        $this->validateName($alias, 'image alias');
        $arguments = ['publish', $this->target($instance), "{$this->remote}:", '--alias', $alias, '--compression', 'none'];
        foreach ($properties as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $key) !== 1 || preg_match('/\A[\x21-\x7e]{1,255}\z/D', $value) !== 1) {
                throw new RuntimeException('Invalid Incus image property.');
            }
            $arguments[] = "{$key}={$value}";
        }
        $this->run($arguments, 1800);

        return $this->imageFingerprint($alias, $type);
    }

    /** Points `$alias` at `$fingerprint` in one request, creating the alias when it is new. */
    public function pointAlias(string $alias, string $fingerprint): void
    {
        $this->validateName($alias, 'image alias');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new RuntimeException('Invalid Incus image fingerprint.');
        }
        $exists = array_any($this->images(), static fn (array $image): bool => in_array($alias, $image['aliases'], true));
        if ($exists) {
            $this->run([
                'query',
                '--request',
                'PATCH',
                '--data',
                json_encode(['target' => $fingerprint], JSON_THROW_ON_ERROR),
                "{$this->remote}:/1.0/images/aliases/{$alias}?project={$this->project}",
            ]);

            return;
        }
        $this->run(['image', 'alias', 'create', "{$this->remote}:{$alias}", $fingerprint]);
    }

    public function deleteImage(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new RuntimeException('Invalid Incus image fingerprint.');
        }
        $this->run(['image', 'delete', "{$this->remote}:{$fingerprint}"]);
    }

    /**
     * The image fingerprint each instance in the project started from.
     *
     * @return array<string, string> instance name => fingerprint
     */
    public function instanceBaseImages(): array
    {
        $images = [];
        foreach ($this->readJson(['list', "{$this->remote}:", '--format=json']) as $resource) {
            if (! is_array($resource) || ! is_string($resource['name'] ?? null)) {
                continue;
            }
            $base = $this->configuration($resource)['volatile.base_image'] ?? null;
            if (is_string($base) && $base !== '') {
                $images[$resource['name']] = $base;
            }
        }

        return $images;
    }

    /** Every guest of the target is on its network with its fixed MAC and address, and owned by the harness. */
    public function assertTopologyNetworkIdentity(TopologyTarget $target, bool $requireRunning = false): void
    {
        $recipe = $target->recipe;
        $network = $target->network();
        $instancesByRole = $target->instances();
        $this->validateUniqueInstances(array_values($instancesByRole), 'topology network validation');

        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }
        $this->assertOwned($resource->metadata, "network {$network}");
        $slot = $this->networkSlot($resource);

        $resources = $this->instanceInventory(array_values($instancesByRole), 'topology network validation');
        foreach ($instancesByRole as $role => $instance) {
            $node = $recipe->node($role);
            $resource = $resources[$instance] ?? null;
            if ($resource === null) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $expectedType = $node->image->instanceType();
            if (($resource['type'] ?? null) !== $expectedType) {
                throw new RuntimeException("Incus instance {$instance} type does not match topology.");
            }
            $vm = $this->instanceFromResource($resource);
            $this->assertOwned($vm->metadata, "instance {$instance}");
            if ($requireRunning && ! $vm->isRunning()) {
                throw new RuntimeException("Incus instance {$instance} is not running.");
            }
            $eth0 = $this->valueAt($resource, 'devices', 'eth0') ?? $this->valueAt($resource, 'expanded_devices', 'eth0');
            if (! is_array($eth0) || ($eth0['network'] ?? null) !== $network) {
                throw new RuntimeException("Incus instance {$instance} network identity does not match topology.");
            }
            if (($eth0['hwaddr'] ?? null) !== $this->deterministicMac($network, $node->key)) {
                throw new RuntimeException("Incus instance {$instance} MAC identity does not match topology.");
            }
            if (($eth0['ipv4.address'] ?? null) !== TopologyTarget::ipv4For($slot, $node->address)) {
                throw new RuntimeException("Incus instance {$instance} IPv4 identity does not match topology.");
            }
        }
    }

    /** @param array<string, string> $configuration */
    public function setNetworkConfiguration(string $name, array $configuration): void
    {
        $this->validateName($name, 'network');
        $network = $this->network($name);
        if ($network === null) {
            throw new RuntimeException("Incus network {$name} does not exist.");
        }
        $this->assertOwned($network->metadata, "network {$name}");
        $this->validateStringMap($configuration, 'network configuration');
        $arguments = ['network', 'set', $this->target($name)];
        foreach ($configuration as $key => $value) {
            $this->validateConfiguration($key, $value);
            $arguments[] = "{$key}={$value}";
        }
        $this->run($arguments);
    }

    /** Reserve the one session device before starting its unit. Incus rejects a duplicate atomically. */
    public function publishWeb(string $instance, int $port, string $token): void
    {
        $owned = $this->operationOwnedInstances([$instance], 'web publication');
        if ($owned[$instance]->webPublished) {
            throw new RuntimeException('A web session already owns this topology; stop it before starting another.');
        }
        $this->assertWebToken($token);
        if ($port < 1024 || $port > 65535) {
            throw new RuntimeException('Invalid web loopback port.');
        }
        $this->run([
            'config', 'device', 'add', $this->target($instance), 'orbit-e2e-web', 'proxy',
            "listen=tcp:127.0.0.1:{$port}", 'connect=tcp:127.0.0.1:5173', 'bind=host',
            'user.orbit.e2e.web-session='.$token,
        ]);
    }

    /** Remove only the reserved session device, after stopping its unit. Guest deletion also removes it. */
    public function stopWeb(string $instance, ?string $expectedToken = null): void
    {
        $resource = $this->instance($instance);
        if ($resource === null) {
            return;
        }
        $this->assertOwned($resource->metadata, "instance {$instance}");
        if (! $resource->webPublished) {
            return;
        }
        if ($expectedToken !== null && $resource->webSessionToken !== $expectedToken) {
            return;
        }
        $token = $resource->webSessionToken;
        if ($token === null) {
            throw new RuntimeException('The web reservation has no ownership token; retain it for recovery.');
        }
        $this->assertWebToken($token);
        if ($resource->isRunning()) {
            $stopped = $this->exec($instance, GuestCommand::asOrbitUser([
                'sudo', 'bash', '/home/orbit/orbit/apps/e2e/resources/web-unit.sh', 'stop', $token,
            ]));
            if (! $stopped->successful()) {
                throw new RuntimeException('Could not confirm the owned topology web unit stopped; retain its reservation for retry.');
            }
        }
        $this->run(['config', 'device', 'remove', $this->target($instance), 'orbit-e2e-web']);
    }

    private function assertWebToken(string $token): void
    {
        if (preg_match('/\\A[0-9a-f]{32}\\z/D', $token) !== 1) {
            throw new RuntimeException('Invalid web session ownership token.');
        }
    }

    public function start(string $instance): void
    {
        if ($this->validatedOwnedVm($instance)->isRunning()) {
            return;
        }

        $this->run(['start', $this->target($instance)], 120);
    }

    /** @param list<string> $instances */
    public function startAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'start');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'start') as $instance => $vm) {
            if (! $vm->isRunning()) {
                $commands[$instance] = ['start', $this->target($instance)];
            }
        }
        if ($commands === []) {
            return;
        }

        $this->runParallel($commands, 120, failureMessage: 'Incus instance start batch failed');
    }

    /** @param list<string> $instances */
    public function waitForAgents(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'guest agent');

        $this->operationOwnedInstances($instances, 'guest agent');

        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        $pending = array_fill_keys($instances, true);

        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $commands = [];
            foreach (array_keys($pending) as $instance) {
                $commands[$instance] = ['exec', $this->target($instance), '--', '/bin/true'];
            }
            try {
                $probes = $this->runParallel($commands, 10, false);
            } catch (RuntimeException) {
                $probes = [];
            }
            foreach ($probes as $instance => $probe) {
                if ($probe->successful()) {
                    unset($pending[$instance]);
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            if ($pending === []) {
                break;
            }

            usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
        }

        if ($pending !== []) {
            throw new RuntimeException('Timed out waiting for Incus guest agents to become ready.');
        }
    }

    /** @param list<string> $instances */
    public function waitForGlobalIpv4(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'IPv4');

        $this->operationOwnedInstances($instances, 'IPv4');

        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        $pending = array_fill_keys($instances, true);
        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $commands = [];
            foreach (array_keys($pending) as $instance) {
                $commands[$instance] = [
                    'exec',
                    $this->target($instance),
                    '--',
                    'sh',
                    '-c',
                    self::GLOBAL_IPV4_PROBE,
                ];
            }
            try {
                $probes = $this->runParallel($commands, 10, false);
            } catch (RuntimeException) {
                $probes = [];
            }
            foreach ($probes as $instance => $probe) {
                if ($probe->successful() && $this->hasUsableGlobalIpv4($probe->output())) {
                    unset($pending[$instance]);
                }
            }
            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }
            usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
        }

        if ($pending !== []) {
            throw new RuntimeException('Timed out waiting for Incus guests to receive global IPv4 addresses.');
        }
    }

    /**
     * @param  array<string, string>  $instances
     * @return array<string, string>
     */
    public function globalIpv4All(array $instances): array
    {
        $commands = [];
        foreach ($instances as $label => $instance) {
            $commands[$label] = [
                'instance' => $instance,
                'command' => new GuestCommand(
                    [
                        'sh',
                        '-c',
                        self::GLOBAL_IPV4_PROBE,
                    ],
                    10,
                ),
            ];
        }

        $addressesByLabel = [];
        foreach ($this->execAll($commands) as $label => $probe) {
            $instance = $instances[$label];
            if (! $probe->successful()) {
                throw new RuntimeException("Failed to read global IPv4 address for Incus guest {$instance}.");
            }

            $addresses = $this->globalIpv4Candidates($probe->stdout);
            if (count($addresses) === 1) {
                $addressesByLabel[$label] = $addresses[0];

                continue;
            }
            if ($addresses === []) {
                throw new RuntimeException("Incus guest {$instance} has no usable global IPv4 address.");
            }

            throw new RuntimeException("Incus guest {$instance} does not have exactly one usable global IPv4 address.");
        }

        return $addressesByLabel;
    }

    /** @return list<string> */
    private function globalIpv4Candidates(string $output): array
    {
        $addresses = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $inet = array_search('inet', $fields, true);
            $address = is_int($inet) ? explode('/', $fields[$inet + 1] ?? '', 2)[0] : '';
            if (
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || str_starts_with($address, '127.')
            ) {
                continue;
            }

            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    private function deterministicMac(string $topologyId, string $role): string
    {
        $this->validateName($topologyId, 'topology');
        $this->validateName($role, 'role');

        return TopologyTarget::macFor($topologyId, $role);
    }

    private function incusLimit(string $key, string $default, ?string $node = null): string
    {
        $configured = function_exists('config') && app()->bound('config')
            ? config("e2e.incus.{$key}", $default)
            : $default;
        if ($key === 'memory' && is_array($configured)) {
            $configured = $configured[$node ?? 'default'] ?? $configured['default'] ?? $default;
        }
        if (is_int($configured) || is_float($configured)) {
            $value = (string) $configured;
        } elseif (is_string($configured)) {
            $value = $configured;
        } else {
            throw new InvalidArgumentException(match ($key) {
                'cpu' => 'Incus CPU limit must be a positive integer.',
                'memory' => 'Incus memory limit must use MiB or GiB.',
                'root_size' => 'Incus root volume size must use MiB or GiB.',
                default => 'Incus limit must be a string.',
            });
        }
        if ($key === 'cpu' && ! preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new InvalidArgumentException('Incus CPU limit must be a positive integer.');
        }
        if ($key === 'memory' && ! preg_match('/^[1-9][0-9]*(MiB|GiB)$/', $value)) {
            throw new InvalidArgumentException('Incus memory limit must use MiB or GiB.');
        }
        if ($key === 'root_size' && ! preg_match('/^[1-9][0-9]*(MiB|GiB)$/', $value)) {
            throw new InvalidArgumentException('Incus root volume size must use MiB or GiB.');
        }

        return $value;
    }

    private function hasUsableGlobalIpv4(string $output): bool
    {
        return count($this->globalIpv4Candidates($output)) === 1;
    }

    public function stop(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        try {
            $this->run(['stop', $this->target($instance)], 120);
        } catch (RuntimeException) {
            $this->run(['stop', $this->target($instance), '--force'], 120);
        }
    }

    /** @param list<string> $instances */
    public function stopAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'stop');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'stop') as $instance => $vm) {
            if ($vm->isRunning()) {
                $commands[$instance] = ['stop', $this->target($instance)];
            }
        }
        if ($commands !== []) {
            try {
                $this->runParallel($commands, 120, false);
            } catch (Interrupted $interrupted) {
                throw $interrupted;
            } catch (Throwable) {
                // A guest that does not stop in time is forced below.
            }
            $forced = [];
            $observed = $this->instances(array_keys($commands));
            foreach (array_keys($commands) as $instance) {
                $vm = $observed[$instance] ?? null;
                if ($vm === null || $vm->isRunning()) {
                    $forced[$instance] = ['stop', $this->target($instance), '--force'];
                }
            }
            if ($forced !== []) {
                $this->runParallel($forced, 120, failureMessage: 'Incus forced instance stop batch failed');
            }
        }
    }

    /** @param list<string> $instances */
    public function forceStopAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'forced stop');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'forced stop') as $instance => $vm) {
            if ($vm->isRunning()) {
                $commands[$instance] = ['stop', $this->target($instance), '--force'];
            }
        }
        if ($commands !== []) {
            $this->runParallel($commands, 120, failureMessage: 'Incus forced stop batch failed');
        }
    }

    public function deleteInstance(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        $this->run(['delete', $this->target($instance)], 300);
    }

    /** @param list<string> $instances */
    public function deleteInstances(array $instances): void
    {
        $this->ownedInstances($instances, 'deletion');
        $commands = [];
        foreach ($instances as $instance) {
            $commands[$instance] = ['delete', $this->target($instance)];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus instance deletion batch failed');

        $remaining = $this->instances($instances);
        if ($remaining !== []) {
            throw new RuntimeException(
                'Incus instances still exist after deletion: '.implode(', ', array_keys($remaining)).'.',
            );
        }
    }

    public function deleteNetwork(string $network): void
    {
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }

        $this->assertOwned($resource->metadata, "network {$network}");
        $this->run(['network', 'delete', "{$this->remote}:{$network}"]);
    }

    /**
     * Delete one orphaned harness network. The name must carry the harness prefix and the network must still
     * have no users at deletion time; the prefix is the ownership rule here.
     */
    public function deleteOrphanNetwork(string $network): void
    {
        $this->validateName($network, 'network');
        if (! OrphanNetworkSweep::isHarnessNetworkName($network)) {
            throw new RuntimeException("Incus network {$network} is outside the harness prefixes.");
        }
        $resource = $this->networks()[$network] ?? null;
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }
        if ($resource->usedBy !== []) {
            throw new RuntimeException("Incus network {$network} is still in use.");
        }
        $this->run(['network', 'delete', "{$this->remote}:{$network}"]);
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->validatedOwnedVm($instance);
        $this->validateFilePushPaths($source, $destination);

        $this->run(['file', 'push', $source, "{$this->target($instance)}{$destination}"], 300);
    }

    /** @param array<string, array<array-key, mixed>> $files */
    public function pushFiles(array $files): void
    {
        if ($files === []) {
            throw new RuntimeException('Incus file push batch must be non-empty.');
        }

        $requests = [];
        $instances = [];
        foreach ($files as $label => $request) {
            $this->validateName($label, 'file push label');
            $instance = $request['instance'] ?? null;
            $source = $request['source'] ?? null;
            $destination = $request['destination'] ?? null;
            if (! is_string($instance) || ! is_string($source) || ! is_string($destination)) {
                throw new RuntimeException('Incus file push batch request is invalid.');
            }
            $this->validateFilePushPaths($source, $destination);
            $requests[$label] = compact('instance', 'source', 'destination');
            $instances[$instance] = true;
        }

        $this->operationOwnedInstances(array_keys($instances), 'file push');

        $commands = [];
        foreach ($requests as $label => $request) {
            $commands[$label] = [
                'file',
                'push',
                $request['source'],
                $this->target($request['instance']).$request['destination'],
            ];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus file push batch failed');
    }

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        if (! isset($this->ownedInstanceCache[$instance])) {
            $this->ownedInstanceCache[$instance] = $this->validatedOwnedVm($instance);
        } else {
            $this->assertOwned($this->ownedInstanceCache[$instance]->metadata, "instance {$instance}");
        }
        $result = $this->run(
            ['exec', $this->target($instance), '--', ...$command->command],
            $command->timeout,
            false,
            $command->stdin,
        );

        return new GuestCommandResult($result->output(), $result->errorOutput(), (int) $result->exitCode());
    }

    /** @param array<string, array<array-key, mixed>> $commands
     *  @return array<string, GuestCommandResult> */
    public function execAll(array $commands): array
    {
        if ($commands === []) {
            throw new RuntimeException('Incus guest command batch must be non-empty.');
        }
        $full = [];
        $batch = [];
        $instances = [];
        foreach ($commands as $label => $request) {
            $this->validateName($label, 'guest command label');
            $instance = $request['instance'] ?? null;
            $command = $request['command'] ?? null;
            if (! is_string($instance) || ! $command instanceof GuestCommand) {
                throw new RuntimeException('Incus guest command batch request is invalid.');
            }
            $instances[$instance] = true;
            $batch[$label] = ['instance' => $instance, 'command' => $command];
            $full[$label] = ['exec', $this->target($instance), '--', ...$command->command];
        }
        $this->operationOwnedInstances(array_keys($instances), 'guest command');
        try {
            $requests = [];
            foreach ($full as $label => $argv) {
                $request = $batch[$label];
                $requests[] = [
                    'label' => $label,
                    'project' => $this->project,
                    'instance' => $this->target($request['instance']),
                    'argv' => $request['command']->command,
                    'timeout' => $request['command']->timeout,
                    'stdin' => $request['command']->stdin,
                ];
            }
            $input = json_encode(['requests' => $requests], JSON_THROW_ON_ERROR);
            $timeout = max(array_map(static fn (array $request): int => $request['timeout'], $requests)) + 10;
            $result = Process::timeout($timeout)->input($input)->run([
                'python3',
                __DIR__.'/../../resources/host/exec-all.py',
            ]);
            if (! $result->successful()) {
                $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
                $detail = $error !== '' ? $error : 'exit code '.$result->exitCode();

                throw new RuntimeException('Batch helper failed: '.$detail.'.');
            }
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new RuntimeException('Batch helper returned invalid output.');
            }
            $results = [];
            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('Batch helper returned invalid result.');
                }
                $invalidTimeout =
                    array_key_exists('timed_out', $item)
                    && (! is_bool($item['timed_out']) || $item['timed_out'] && ($item['exit_code'] ?? null) !== 124);
                if (
                    ! is_string($item['label'] ?? null)
                    || ! array_key_exists($item['label'], $full)
                    || array_key_exists($item['label'], $results)
                    || ! is_string($item['stdout'] ?? null)
                    || ! is_string($item['stderr'] ?? null)
                    || ! is_int($item['exit_code'] ?? null)
                    || $invalidTimeout
                    || array_diff(array_keys($item), ['label', 'stdout', 'stderr', 'exit_code', 'timed_out']) !== []
                ) {
                    throw new RuntimeException('Batch helper returned invalid result.');
                }
                $results[$item['label']] = Process::result(
                    $item['stdout'],
                    $item['stderr'],
                    $item['exit_code'],
                );
            }
            if (array_keys($results) !== array_keys($full)) {
                throw new RuntimeException('Batch helper returned incomplete results.');
            }
        } catch (Interrupted $interrupted) {
            throw $interrupted;
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());

            throw new RuntimeException('Incus guest command batch failed: '.$message, 0, $exception);
        }
        $resolved = [];
        foreach ($full as $label => $argv) {
            $result = $results[$label];
            $resolved[$label] = new GuestCommandResult(
                $result->output(),
                $result->errorOutput(),
                (int) $result->exitCode(),
            );
        }

        return $resolved;
    }

    private function validateFilePushPaths(string $source, string $destination): void
    {
        if (
            $source === ''
            || str_contains($source, "\0")
            || ! str_starts_with($destination, '/')
            || str_contains($destination, "\0")
        ) {
            throw new RuntimeException('Invalid Incus file path.');
        }
    }

    private function validatedVm(string $name): IncusInstance
    {
        $instance = $this->instance($name);
        if ($instance === null) {
            throw new RuntimeException("Incus instance {$name} does not exist.");
        }

        return $instance;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<array-key, mixed>>
     */
    private function instanceInventory(array $names, string $label): array
    {
        $this->validateUniqueInstances($names, $label);
        $wanted = array_fill_keys($names, true);
        $found = [];
        foreach ($this->readJson(['list', "{$this->remote}:", '--format=json']) as $resource) {
            if (! is_array($resource)) {
                continue;
            }
            $name = $resource['name'] ?? null;
            if (! is_string($name) || ! isset($wanted[$name])) {
                continue;
            }
            if (isset($found[$name])) {
                throw new RuntimeException("Incus instance {$name} appears more than once in inventory.");
            }
            $found[$name] = $resource;
        }

        return $found;
    }

    /** @param array<array-key, mixed> $resource */
    private function instanceFromResource(array $resource, ?string $requestedName = null): IncusInstance
    {
        $name = $resource['name'] ?? null;
        if (! is_string($name) || ! in_array($resource['type'] ?? null, ['virtual-machine', 'container'], true)) {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$requestedName} is not a virtual machine.");
            }
            throw new RuntimeException('Incus instance identity is not a virtual machine.');
        }
        $pool = $this->valueAt($resource, 'devices', 'root', 'pool')
            ?? $this->valueAt($resource, 'expanded_devices', 'root', 'pool');
        if (! is_string($pool) || $pool === '') {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$name} has no storage pool identity.");
            }
            throw new RuntimeException("Incus instance {$name} identity is invalid.");
        }
        if ($pool !== $this->pool) {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$name} storage pool identity does not match.");
            }
            throw new RuntimeException("Incus instance {$name} identity is invalid.");
        }
        $status = $resource['status'] ?? null;
        $statusCode = $resource['status_code'] ?? null;
        if (! is_string($status) || ! is_int($statusCode)) {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$name} has no valid power status.");
            }
            throw new RuntimeException("Incus instance {$name} identity is invalid.");
        }
        $network = $this->valueAt($resource, 'devices', 'eth0', 'network')
            ?? $this->valueAt($resource, 'expanded_devices', 'eth0', 'network');
        $mac = $this->valueAt($resource, 'devices', 'eth0', 'hwaddr')
            ?? $this->valueAt($resource, 'expanded_devices', 'eth0', 'hwaddr');
        if ($network !== null && ! is_string($network)) {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$name} has an invalid network identity.");
            }
            throw new RuntimeException("Incus instance {$name} network identity is invalid.");
        }
        if ($mac !== null && ! is_string($mac)) {
            if ($requestedName !== null) {
                throw new RuntimeException("Incus instance {$name} has an invalid MAC identity.");
            }
            throw new RuntimeException("Incus instance {$name} MAC identity is invalid.");
        }

        $webToken = $this->valueAt($resource, 'devices', 'orbit-e2e-web', 'user.orbit.e2e.web-session');

        return new IncusInstance(
            $this->remote,
            $this->project,
            $name,
            $pool,
            $this->metadata($resource),
            strtoupper($status),
            $statusCode,
            $network,
            $mac,
            $this->disks($resource, $name),
            webPublished: is_array($resource['devices'] ?? null) && isset($resource['devices']['orbit-e2e-web']),
            webSessionToken: is_string($webToken) ? $webToken : null,
        );
    }

    /**
     * Every non-root disk device with a host source: the exact mount identity release must re-check.
     *
     * @param  array<array-key, mixed>  $resource
     * @return array<string, array{source:string,path:string}>
     */
    private function disks(array $resource, string $name): array
    {
        $devices = $resource['devices'] ?? $resource['expanded_devices'] ?? [];
        if (! is_array($devices)) {
            throw new RuntimeException("Incus instance {$name} has invalid devices.");
        }
        $disks = [];
        foreach ($devices as $device => $configuration) {
            if (
                ! is_string($device)
                || $device === 'root'
                || ! is_array($configuration)
                || ($configuration['type'] ?? null) !== 'disk'
            ) {
                continue;
            }
            $source = $configuration['source'] ?? null;
            $path = $configuration['path'] ?? null;
            if (! is_string($source) || ! is_string($path) || $source === '' || $path === '') {
                throw new RuntimeException("Incus instance {$name} disk device {$device} identity is invalid.");
            }
            $disks[$device] = ['source' => $source, 'path' => $path];
        }

        return $disks;
    }

    private function validatedOwnedVm(string $name): IncusInstance
    {
        $instance = $this->validatedVm($name);
        $this->assertOwned($instance->metadata, "instance {$name}");

        return $instance;
    }

    /** @param array<string, string> $metadata */
    private function assertOwned(array $metadata, string $resource): void
    {
        foreach ($this->ownershipMetadata as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException("Incus {$resource} ownership metadata does not match.");
            }
        }
    }

    /**
     * Read a nested Incus JSON value. Missing levels and non-arrays match the
     * null-coalescing chains this used to inline.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function valueAt(array $value, string ...$path): mixed
    {
        $current = $value;
        foreach ($path as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<mixed>
     */
    private function readJson(array $arguments): array
    {
        $result = $this->run($arguments);

        try {
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Incus returned malformed JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, list<string>>  $commands
     * @return array<string, ProcessResult>
     */
    private function runParallel(
        array $commands,
        int $timeout,
        bool $failOnError = true,
        string $failureMessage = 'Incus parallel command batch failed',
    ): array {
        if ($commands === []) {
            throw new RuntimeException('Incus parallel command batch must be non-empty.');
        }

        $fullCommands = [];
        foreach ($commands as $label => $arguments) {
            $this->validateName($label, 'parallel command label');
            if ($arguments === []) {
                throw new RuntimeException('Incus parallel command arguments must be non-empty.');
            }
            $fullCommands[$label] = ['incus', '--project', $this->project, ...$arguments];
        }

        try {
            $results = Process::pool(function (Pool $pool) use ($fullCommands, $timeout): void {
                foreach ($fullCommands as $label => $command) {
                    $pool->as($label)->timeout($timeout)->command($command);
                }
            })->run()->collect()->all();
        } catch (Interrupted $interrupted) {
            throw $interrupted;
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());

            throw new RuntimeException("{$failureMessage}: {$message}", 0, $exception);
        }

        $resolved = [];
        $failed = [];
        foreach ($fullCommands as $label => $command) {
            $result = $results[$label] ?? null;
            if (! $result instanceof ProcessResult) {
                throw new RuntimeException('Incus parallel command result is invalid.');
            }
            $resolved[$label] = $result;
            if ($result->successful()) {
                continue;
            }

            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            $failed[$label] = $error !== '' ? $error : 'exit code '.$result->exitCode();
        }
        if ($failOnError && $failed !== []) {
            $details = [];
            foreach ($failed as $label => $error) {
                $details[] = "{$label}: {$error}";
            }

            throw new RuntimeException($failureMessage.' for '.implode('; ', $details).'.');
        }

        if (
            $this->ownedInstanceCache !== []
            && array_any(
                $commands,
                fn (array $arguments): bool => $this->invalidatesOwnershipCache($arguments),
            )
        ) {
            $this->ownedInstanceCache = [];
        }

        return $resolved;
    }

    /**
     * A source mount must name an existing host directory; the guest path and the
     * device name are passed to Incus verbatim, so they must be free of separators.
     *
     * @param  array{device:string,source:string,path:string}  $mount
     */
    private function validateMount(array $mount): void
    {
        $this->validateName($mount['device'], 'device');
        if ($mount['device'] === 'root' || $mount['device'] === 'eth0') {
            throw new RuntimeException('Invalid Incus device identity.');
        }
        foreach (['source', 'path'] as $key) {
            if (! MountPath::isSafe($mount[$key])) {
                throw new RuntimeException("Invalid Incus mount {$key}.");
            }
        }
        if (! MountPath::isMountableDirectory($mount['source'])) {
            throw new RuntimeException('The Incus mount source must be an existing directory.');
        }
    }

    private function networkSlot(IncusNetwork $network): int
    {
        $address = $network->config['ipv4.address'] ?? null;
        if (! is_string($address) || preg_match('/\A10\.232\.(\d{1,3})\.1\/24\z/D', $address, $matches) !== 1) {
            throw new RuntimeException('Incus network IPv4 configuration does not contain a valid topology slot.');
        }

        $slot = (int) $matches[1];
        if ($slot < 1 || $slot > 200) {
            throw new RuntimeException('Incus network IPv4 configuration contains an invalid topology slot.');
        }

        return $slot;
    }

    /** @param list<string> $instances */
    private function validateUniqueInstances(array $instances, string $label): void
    {
        if ($instances === [] || count($instances) !== count(array_unique($instances))) {
            throw new RuntimeException("Incus {$label} instance list must be non-empty and unique.");
        }

        foreach ($instances as $instance) {
            $this->validateName($instance, 'instance');
        }
    }

    /**
     * @param  list<string>  $instances
     * @return array<string, IncusInstance>
     */
    private function ownedInstances(array $instances, string $label): array
    {
        $this->validateUniqueInstances($instances, $label);
        $found = $this->instances($instances);
        foreach ($instances as $instance) {
            if (! isset($found[$instance])) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $this->assertOwned($found[$instance]->metadata, "instance {$instance}");
        }

        return $found;
    }

    /**
     * Reuse ownership proof only within one CLI operation. A new command builds
     * a new IncusHost, so it must validate external state again.
     *
     * @param  list<string>  $instances
     * @return array<string, IncusInstance>
     */
    private function operationOwnedInstances(array $instances, string $label): array
    {
        $missing = array_values(array_filter(
            $instances,
            fn (string $instance): bool => ! isset($this->ownedInstanceCache[$instance]),
        ));
        if ($missing !== []) {
            $this->ownedInstanceCache = [
                ...$this->ownedInstanceCache,
                ...$this->ownedInstances($missing, $label),
            ];
        }
        foreach ($instances as $instance) {
            if (! isset($this->ownedInstanceCache[$instance])) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $this->assertOwned($this->ownedInstanceCache[$instance]->metadata, "instance {$instance}");
        }

        return array_intersect_key($this->ownedInstanceCache, array_fill_keys($instances, true));
    }

    /** @param list<string> $arguments */
    private function run(
        array $arguments,
        int $timeout = 60,
        bool $failOnError = true,
        ?string $stdin = null,
    ): ProcessResult {
        // `incus query` refuses --project; its URL names the project instead.
        $command = ($arguments[0] ?? '') === 'query'
            ? ['incus', ...$arguments]
            : ['incus', '--project', $this->project, ...$arguments];

        try {
            $process = Process::timeout($timeout);
            if ($stdin !== null) {
                $process = $process->input($stdin);
            }
            $result = $process->run($command);
        } catch (Interrupted $interrupted) {
            throw $interrupted;
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            if ($this->isGuestExecCommand($command)) {
                $message = 'Incus guest command could not run.';
            }

            throw new RuntimeException("Incus command timed out or could not run: {$message}", 0, $exception);
        }

        if (! $result->successful()) {
            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            if ($failOnError) {
                throw new RuntimeException("Incus command failed with exit code {$result->exitCode()}: {$error}");
            }
        }

        if ($this->invalidatesOwnershipCache($arguments)) {
            $this->ownedInstanceCache = [];
        }

        return $result;
    }

    /** @param list<string> $arguments */
    private function invalidatesOwnershipCache(array $arguments): bool
    {
        return in_array(
            $arguments[0] ?? '',
            [
                'config',
                'copy',
                'delete',
                'init',
                'launch',
                'move',
                'rebuild',
                'rename',
            ],
            true,
        );
    }

    /** @param list<string> $command */
    private function isGuestExecCommand(array $command): bool
    {
        return
            count($command) >= 6
            && $command[0] === 'incus'
            && $command[1] === '--project'
            && $command[3] === 'exec'
            && $command[5] === '--';
    }

    /**
     * @param  array<array-key, mixed>  $resource
     * @return array<string, string>
     */
    private function metadata(array $resource): array
    {
        $configuration = $resource['config'] ?? [];
        if (! is_array($configuration)) {
            throw new RuntimeException('Incus returned invalid resource configuration.');
        }

        return $this->e2eMetadata($configuration);
    }

    /**
     * @param  array<array-key, mixed>  $resource
     * @return array<string, string>
     */
    private function configuration(array $resource): array
    {
        $configuration = $resource['config'] ?? [];
        if (! is_array($configuration)) {
            throw new RuntimeException('Incus returned invalid resource configuration.');
        }

        $validated = [];
        foreach ($configuration as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException('Incus returned invalid resource configuration.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     * @return array<string, string>
     */
    private function e2eMetadata(array $configuration): array
    {
        $metadata = [];

        foreach ($configuration as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'user.orbit.e2e.') && is_string($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function validateName(string $value, string $label): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $value) !== 1) {
            throw new RuntimeException("Invalid Incus {$label} identity.");
        }
    }

    private function target(string $name): string
    {
        return "{$this->remote}:{$name}";
    }

    /** @return array{string, string} */
    private function imageSelector(string $image): array
    {
        $separator = strpos($image, ':');
        if ($separator === false) {
            return ["{$this->remote}:", $image];
        }

        $remote = substr($image, 0, $separator);
        $selector = substr($image, $separator + 1);
        $this->validateName($remote, 'image remote');
        if ($selector === '' || str_contains($selector, ':')) {
            throw new RuntimeException('Invalid Incus image identity.');
        }

        return ["{$remote}:", $selector];
    }

    private function validateImage(string $image): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,254}\z/', $image) !== 1) {
            throw new RuntimeException('Invalid Incus image identity.');
        }
    }

    private function validateMetadata(string $key, string $value): void
    {
        if (preg_match('/\Auser\.orbit\.e2e\.[a-z0-9.-]+\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus ownership metadata.');
        }
    }

    private function validateConfiguration(string $key, string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus network configuration.');
        }
    }

    /** @param array<array-key, mixed> $values */
    private function validateStringMap(array $values, string $label): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException("Incus {$label} must contain string keys and values.");
            }
        }
    }
}
