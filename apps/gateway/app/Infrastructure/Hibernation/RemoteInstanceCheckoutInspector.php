<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\LocalRuntimeDependencies;
use App\Domain\Hibernation\RuntimeDependencyState;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use Throwable;

final readonly class RemoteInstanceCheckoutInspector implements InstanceCheckoutInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private int $restoreTimeoutSeconds = RuntimeHibernation::DefaultColdWakeTimeoutSeconds,
    ) {}

    public function inspect(Instance $instance): RuntimeDependencyState
    {
        $checkout = $this->checkout($instance);

        try {
            $facts = $this->run($instance, 'hibernation.inspect-checkout', ['repository' => $checkout, 'checkout' => $this->application($instance)]);
        } catch (Throwable) {
            throw new HibernationException(
                errorCode: 'hibernation.checkout_inspect_failed',
                message: "Checkout inspection failed on Instance [{$instance->name}].",
            );
        }

        $locks = array_values(array_filter(
            is_array($facts['javascript_locks'] ?? null) ? $facts['javascript_locks'] : [],
            static fn (mixed $lock): bool => in_array($lock, ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'], true),
        ));
        $flag = static fn (string $name): bool => ($facts[$name] ?? false) === true;

        return LocalRuntimeDependencies::inspect(
            composerJsonFile: $flag('composer_json'),
            composerLockFile: $flag('composer_lock'),
            vendorPresent: $flag('vendor_present'),
            vendorSymlink: $flag('vendor_symlink'),
            packageJsonFile: $flag('package_json'),
            javascriptLockFiles: $locks,
            nodeModulesPresent: $flag('node_modules_present'),
            nodeModulesSymlink: $flag('node_modules_symlink'),
            sourceTreeLastActivityUnix: is_int($facts['source_mtime'] ?? null) ? $facts['source_mtime'] : null,
        );
    }

    public function prune(Instance $instance, RuntimeDependencyState $state): void
    {
        $this->checkout($instance);
        $targets = [];

        if ($state->prunableVendor()) {
            $targets[] = 'vendor';
        }

        if ($state->prunableNodeModules()) {
            $targets[] = 'node_modules';
        }

        if ($targets === []) {
            return;
        }

        try {
            $this->run($instance, 'hibernation.prune-checkout', ['checkout' => $this->application($instance), 'targets' => $targets]);

            return;
        } catch (Throwable) {
        }

        throw new HibernationException(
            errorCode: 'hibernation.checkout_prune_failed',
            message: "Checkout dependency prune failed on Instance [{$instance->name}].",
        );
    }

    public function restore(Instance $instance, RuntimeDependencyState $state): void
    {
        $this->checkout($instance);

        if (! $state->restorableVendor() && ! $state->restorableNodeModules()) {
            return;
        }

        try {
            $this->run($instance, 'hibernation.restore-dependencies', [
                'user' => $this->accounts->resolve($instance->node)->user,
                'checkout' => $this->application($instance),
                'composer' => $state->restorableVendor(),
                'javascript' => $state->restorableNodeModules(),
            ], (float) $this->restoreTimeoutSeconds);
        } catch (Throwable $exception) {
            $step = $exception instanceof AgentCommandRefused && is_string($exception->details['step'] ?? null) ? $exception->details['step'] : 'restore';

            throw new HibernationException(
                errorCode: 'hibernation.checkout_restore_failed',
                message: "Checkout dependency restore step [{$step}] failed on Instance [{$instance->name}].",
            );
        }
    }

    private function checkout(Instance $instance): string
    {
        $path = StoragePath::tryParse($instance->checkout_path);

        if ($path === null) {
            throw new HibernationException(
                errorCode: 'hibernation.checkout_path_invalid',
                message: "Instance [{$instance->name}] has an invalid checkout path.",
            );
        }

        return $path->value;
    }

    private function application(Instance $instance): string
    {
        $checkout = $this->checkout($instance);
        $path = StoragePath::tryParse($instance->dependencyDirectory());
        if ($path === null || ($path->value !== $checkout && ! str_starts_with($path->value, $checkout.'/'))) {
            throw new HibernationException(
                errorCode: 'hibernation.checkout_path_invalid',
                message: "Instance [{$instance->name}] has an invalid application path.",
            );
        }

        return $path->value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, ?float $deadlineSeconds = null): array
    {
        $node = $instance->node;

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new HibernationException(
                errorCode: 'hibernation.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return $this->agents->run($node, $command, $input, $deadlineSeconds);
    }
}
