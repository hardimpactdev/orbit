<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/** Host-local builder lifecycle, smoke checks, and base alias promotion for recipe builds. */
final readonly class IncusTaskVmBaseImage
{
    private const string FingerprintPattern = '/\A256 SHA256:[A-Za-z0-9+\/]{43} .* \(ED25519\)\n?\z/D';

    private const int BootSeconds = 600;

    public function __construct(
        private IncusHost $incus,
    ) {}

    /** Deletes builder and smoke VMs that an interrupted recipe build left. */
    public function sweep(TaskVmHost $host, Node $node, bool $discardFailed = false): void
    {
        foreach (array_keys($this->instances($host, $node)) as $name) {
            if (str_starts_with($name, TaskVmImageRecipe::WorkPrefix) && TaskVmHostAudit::claimBuildCleanup($node, $name, $discardFailed)) {
                $this->delete($host, $node, $name);
            }
        }
    }

    public function launch(TaskVmHost $host, Node $node, string $name, string $image, string $userData): void
    {
        $result = $this->incus->launch($host, $node, $name, $image, $userData);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image VM [{$name}]", 'launch', $result);
        }
    }

    /** The guest shell of the VM `$name` on the host. */
    public function shell(TaskVmHost $host, Node $node, string $name): IncusGuestShell
    {
        return new IncusGuestShell($this->incus, $host, $node, $name);
    }

    /** Waits until cloud-init is done without errors. */
    public function awaitCloudInit(TaskVmHost $host, Node $node, string $name): void
    {
        $shell = $this->shell($host, $node, $name);
        TaskVmGuest::awaitCloudInit("VM [{$name}]", self::BootSeconds, static fn (array $command, float $timeout): CommandResult => $shell->asRoot($command, timeout: $timeout));
    }

    public function checkSmoke(IncusGuestShell $shell): void
    {
        $name = $shell->vm;
        $result = $this->checked($name, 'exec ssh-keygen', $shell->asRoot(['ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']));
        if (preg_match(self::FingerprintPattern, $result->stdout) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it has no ed25519 SSH host key');
        }
        // Cloud-init found the user-data without the seed: the Gateway's key is in place.
        if (! $shell->asRoot(['test', '-s', TaskVmCloudInit::Home.'/.ssh/authorized_keys'])->succeeded()) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'cloud-init did not read its user-data, so '.TaskVmCloudInit::User.' has no authorized key');
        }
        $boots = $this->checked($name, 'exec journalctl', $shell->asRoot(['journalctl', '--list-boots', '--no-pager', '--quiet']));
        if (count(array_filter(explode("\n", trim($boots->stdout)), static fn (string $line): bool => trim($line) !== '')) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it rebooted during its first boot');
        }
    }

    /** Points `orbit-task-base` at the fingerprint, or creates the alias on the first image. */
    public function promote(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        $result = $this->incus->aliasTarget($host, $node, $host->imageAlias) === null
            ? $this->incus->run($host, $node, ['image', 'alias', 'create', '--', $host->imageAlias, $fingerprint])
            : $this->incus->query($host, $node, 'PATCH', '/1.0/images/aliases/'.rawurlencode($host->imageAlias), json_encode(['target' => $fingerprint], JSON_THROW_ON_ERROR));
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image of Node [{$node->name}]", 'image alias', $result);
        }
    }

    /** Deletes a VM; an absent VM counts as deleted. */
    public function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $result = $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        if (! $result->succeeded() && array_key_exists($name, $this->instances($host, $node))) {
            throw IncusHost::failed("the base image VM [{$name}]", 'delete', $result);
        }
    }

    /**
     * The instances of the host's project, as name => the fingerprint of the image each one launched.
     *
     * @return array<string, ?string>
     */
    public function instances(TaskVmHost $host, Node $node): array
    {
        $subject = "the instances of Node [{$node->name}]";
        $result = $this->incus->run($host, $node, ['list', '--format', 'json']);
        if (! $result->succeeded()) {
            throw IncusHost::failed($subject, 'list', $result);
        }
        $list = IncusHost::json($subject, $result);
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the instance list is not a JSON list');
        }
        $instances = [];
        foreach ($list as $instance) {
            $name = is_array($instance) ? $instance['name'] ?? null : null;
            $config = is_array($instance) ? $instance['config'] ?? [] : null;
            if (! is_string($name) || ! is_array($config)) {
                throw IncusHost::invalid($subject, 'an instance has no name or config');
            }
            $image = $config['volatile.base_image'] ?? null;
            $instances[$name] = is_string($image) ? $image : null;
        }

        return $instances;
    }

    public function checked(string $name, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the base image VM [{$name}]", $operation, $result);
    }
}
