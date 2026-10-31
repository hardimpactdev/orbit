<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmUpCloud;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * The UpCloud servers and templates that Orbit owns for task VMs. A server is owned when its title and hostname are
 * the name Orbit gave it and it carries Orbit's label with the value Orbit gave it. Task VM servers carry
 * {@see TaskVmUpCloud::Label}, base image build servers {@see self::ImageLabel}. This class validates UpCloud's
 * answers once; callers trust what it returns.
 */
final readonly class UpCloudServers
{
    /** The label key of base image builder and smoke servers. */
    public const string ImageLabel = 'orbit-task-image';

    private const int PollSeconds = 3;

    public function __construct(private UpCloudApi $api) {}

    public function upcloud(): TaskVmUpCloud
    {
        return $this->api->upcloud();
    }

    /** The owned server named `$name`, found by its UUID when known, else by its label. Null when it is absent. */
    public function find(string $name, string $labelKey, string $labelValue, ?string $uuid = null): ?UpCloudServer
    {
        if ($uuid === null) {
            $matches = array_values(array_filter($this->labelled($labelKey), static fn (array $server): bool => $server['title'] === $name));
            if ($matches === []) {
                return null;
            }
            if (count($matches) !== 1) {
                throw self::invalid($name, 'more than one server has its name');
            }
            $uuid = $matches[0]['uuid'];
        }
        $server = $this->api->request('GET', 'server/'.$uuid, allowMissing: true)['server'] ?? null;
        if ($server === null) {
            return null;
        }
        $labels = is_array($server) ? data_get($server, 'labels.label') : null;
        $state = is_array($server) ? $server['state'] ?? null : null;
        if (! is_array($server) || ($server['uuid'] ?? null) !== $uuid || ($server['title'] ?? null) !== $name || ($server['hostname'] ?? null) !== $name
            || ! is_array($labels) || ! in_array(['key' => $labelKey, 'value' => $labelValue], $labels, true)
            || ! is_string($state) || ! in_array($state, ['started', 'stopped', 'maintenance', 'error'], true)) {
            throw self::invalid($name, 'the server does not match the name, label, and state that Orbit gave it');
        }

        return new UpCloudServer($uuid, $name, $state, $this->address($name, $server), $this->disk($name, $server));
    }

    /**
     * Every server with the label key `$labelKey`, as uuid and title.
     *
     * @return list<array{uuid: string, title: string, value: string}>
     */
    public function labelled(string $labelKey): array
    {
        $servers = data_get($this->api->request('GET', 'server?label='.rawurlencode($labelKey)), 'servers.server');
        if (! is_array($servers) || ! array_is_list($servers)) {
            throw self::invalid('the labelled servers', 'the server list is not a list');
        }
        $found = [];
        foreach ($servers as $server) {
            $uuid = is_array($server) ? $server['uuid'] ?? null : null;
            $title = is_array($server) ? $server['title'] ?? null : null;
            // The list carries each server's labels; a server without them there is read on its own.
            if (is_array($server) && is_string($uuid) && Str::isUuid($uuid) && data_get($server, 'labels.label') === null) {
                $server = $this->api->request('GET', 'server/'.$uuid, allowMissing: true)['server'] ?? null;
                if ($server === null) {
                    continue;
                }
            }
            $label = is_array($server) ? array_values(array_filter((array) data_get($server, 'labels.label', []), static fn (mixed $label): bool => is_array($label) && ($label['key'] ?? null) === $labelKey)) : [];
            $value = $label[0]['value'] ?? null;
            if (! is_string($uuid) || ! Str::isUuid($uuid) || ! is_string($title) || ! is_string($value)) {
                throw self::invalid('the labelled servers', 'a server has no UUID, title, or label value');
            }
            $found[] = ['uuid' => $uuid, 'title' => $title, 'value' => $value];
        }

        return $found;
    }

    /**
     * Creates an owned server from a template with the user-data, and returns its UUID. It has one public IPv4
     * interface only, so it reaches no private network of the account, and no console access. Its disk has the storage
     * tier of the plan, so a disk within the plan's storage costs nothing extra.
     */
    public function create(string $name, string $labelKey, string $labelValue, string $template, string $userData, string $gatewayPublicKey): string
    {
        $upcloud = $this->upcloud();
        $disk = ['action' => 'clone', 'storage' => $template, 'title' => $name.'-disk', 'size' => $upcloud->diskGb];
        $tier = $this->storageTier($upcloud->plan);
        if ($tier !== null) {
            $disk['tier'] = $tier;
        }
        $answer = $this->api->request('POST', 'server', ['server' => [
            'zone' => $upcloud->zone,
            'title' => $name,
            'hostname' => $name,
            'plan' => $upcloud->plan,
            'metadata' => 'yes',
            'firewall' => 'off',
            'remote_access_enabled' => 'no',
            'labels' => ['label' => [['key' => $labelKey, 'value' => $labelValue]]],
            'networking' => ['interfaces' => ['interface' => [['type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]]]]],
            'storage_devices' => ['storage_device' => [$disk]],
            // A cloud-init template needs a login key. Root gets the Gateway's key, and the user-data disables root login.
            'login_user' => ['username' => 'root', 'ssh_keys' => ['ssh_key' => [$gatewayPublicKey]]],
            'user_data' => $userData,
        ]]);
        $uuid = data_get($answer, 'server.uuid');

        return is_string($uuid) && Str::isUuid($uuid) ? $uuid : throw self::invalid($name, 'the create answer has no server UUID');
    }

    /**
     * The storage tier that the plan includes, such as `standard` for a Starter plan, or null for a plan without
     * included storage. A plan that UpCloud does not list fails, so a mistyped plan creates nothing.
     */
    public function storageTier(string $plan): ?string
    {
        $plans = data_get($this->api->request('GET', 'plan'), 'plans.plan');
        foreach (is_array($plans) ? $plans : [] as $listed) {
            if (is_array($listed) && ($listed['name'] ?? null) === $plan) {
                $tier = $listed['storage_tier'] ?? null;

                return is_string($tier) && preg_match('/\A[a-z]{1,16}\z/D', $tier) === 1 ? $tier : null;
            }
        }

        throw self::invalid($plan, 'UpCloud lists no such plan');
    }

    /** Waits until the server is in `$state`, up to `$seconds`. */
    public function await(string $name, string $labelKey, string $labelValue, string $uuid, string $state, int $seconds): UpCloudServer
    {
        $deadline = now()->getTimestamp() + $seconds;
        while (true) {
            $server = $this->find($name, $labelKey, $labelValue, $uuid) ?? throw new TaskVmException('task_vm.vm_missing', "UpCloud server [{$name}] is gone.", 502);
            if ($server->state === $state) {
                return $server;
            }
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.upcloud_timeout', "UpCloud server [{$name}] was not {$state} after {$seconds} seconds; it is {$server->state}.", 504);
            }
            Sleep::for(self::PollSeconds)->seconds();
        }
    }

    public function start(string $uuid): void
    {
        $this->api->request('POST', "server/{$uuid}/start", ['server' => (object) []]);
    }

    /** A clean shutdown with `$timeout` seconds, or a hard stop at once when `$timeout` is null. */
    public function stop(string $uuid, ?int $timeout): void
    {
        $this->api->request('POST', "server/{$uuid}/stop", ['stop_server' => $timeout === null ? ['stop_type' => 'hard'] : ['stop_type' => 'soft', 'timeout' => (string) $timeout]]);
    }

    /** Requests deletion of one owned stopped server. Swamp observes completion; absence is already satisfied. */
    public function requestDelete(string $name, string $labelKey, string $labelValue, ?string $uuid): void
    {
        $server = $this->find($name, $labelKey, $labelValue, $uuid);
        if ($server === null) {
            return;
        }
        if ($server->state !== 'stopped') {
            throw new TaskVmException('task_vm.not_settled', 'The owned UpCloud server must be stopped before requesting deletion.');
        }
        $this->deleteServer($server->uuid);
    }

    /** Retained synchronous lifecycle: stop, delete with storage, then confirm absence. Removed with its owning caller cutover. */
    public function deleteSynchronously(string $name, string $labelKey, string $labelValue, ?string $uuid): void
    {
        $server = $this->find($name, $labelKey, $labelValue, $uuid);
        if ($server === null) {
            return;
        }
        $uuid = $server->uuid;
        if ($server->state === 'started') {
            $this->stop($uuid, null);
        }
        if ($server->state !== 'stopped') {
            $this->await($name, $labelKey, $labelValue, $uuid, 'stopped', 180);
        }
        $this->deleteServer($uuid);
        $deadline = now()->getTimestamp() + 120;
        while ($this->find($name, $labelKey, $labelValue, $uuid) !== null) {
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.upcloud_timeout', "UpCloud server [{$name}] was not gone 120 seconds after its delete.", 504);
            }
            Sleep::for(self::PollSeconds)->seconds();
        }
    }

    private function deleteServer(string $uuid): void
    {
        $this->api->request('DELETE', "server/{$uuid}?storages=1&backups=delete", allowMissing: true);
    }

    /**
     * The server's one public IPv4 address, or null before it has one.
     *
     * @param  array<mixed>  $server
     */
    private function address(string $name, array $server): ?string
    {
        $found = [];
        foreach ((array) data_get($server, 'ip_addresses.ip_address', []) as $address) {
            if (is_array($address) && ($address['access'] ?? null) === 'public' && ($address['family'] ?? null) === 'IPv4' && is_string($address['address'] ?? null)) {
                $found[] = $address['address'];
            }
        }
        if (count($found) > 1 || ($found !== [] && filter_var($found[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw self::invalid($name, 'the server needs exactly one public IPv4 address');
        }

        return $found[0] ?? null;
    }

    /**
     * The UUID of the server's one disk.
     *
     * @param  array<mixed>  $server
     */
    private function disk(string $name, array $server): string
    {
        $devices = data_get($server, 'storage_devices.storage_device');
        $storage = is_array($devices) && count($devices) === 1 && is_array($devices[0] ?? null) ? $devices[0]['storage'] ?? null : null;

        return is_string($storage) && Str::isUuid($storage) ? $storage : throw self::invalid($name, 'the server needs exactly one disk');
    }

    /**
     * The private templates whose title starts with {@see TaskVmUpCloud::TemplatePrefix}, as uuid => title.
     *
     * @return array<string, string>
     */
    public function templates(?string $recipeIdentity = null): array
    {
        $storages = data_get($this->api->request('GET', 'storage/template'), 'storages.storage');
        if (! is_array($storages) || ! array_is_list($storages)) {
            throw self::invalid('the templates', 'the storage list is not a list');
        }
        $templates = [];
        foreach ($storages as $storage) {
            if (is_array($storage) && ($storage['access'] ?? null) === 'private' && is_string($storage['uuid'] ?? null) && Str::isUuid($storage['uuid'])
                && is_string($storage['title'] ?? null) && str_starts_with($storage['title'], TaskVmUpCloud::TemplatePrefix)) {
                if ($recipeIdentity !== null) {
                    if (preg_match('/\A'.preg_quote(TaskVmUpCloud::TemplatePrefix, '/').'[0-9]{14}\z/D', $storage['title']) !== 1) {
                        continue;
                    }
                    $labels = $storage['labels'] ?? data_get($this->api->request('GET', 'storage/'.$storage['uuid']), 'storage.labels', []);
                    if (! is_array($labels) || ! array_any($labels, static fn (mixed $label): bool => is_array($label) && ($label['key'] ?? null) === 'orbit.recipe' && ($label['value'] ?? null) === $recipeIdentity)) {
                        continue;
                    }
                }
                $templates[$storage['uuid']] = $storage['title'];
            }
        }

        return $templates;
    }

    /** The newest smoke-verified template for the requested recipe, or null without one. */
    public function currentTemplate(string $identity): ?string
    {
        $verified = $this->templates($identity);
        if ($verified === []) {
            return null;
        }
        arsort($verified, SORT_STRING);

        return (string) array_key_first($verified);
    }

    private static function invalid(string $subject, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.upcloud_invalid', "UpCloud answered unexpectedly for [{$subject}]: {$reason}.", 502);
    }
}
