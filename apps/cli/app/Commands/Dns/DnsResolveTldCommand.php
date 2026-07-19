<?php

declare(strict_types=1);

namespace App\Commands\Dns;

use App\Commands\Concerns\WithStepTree;
use App\Commands\LocalOnlyCommand;
use App\Services\Dns\ResolvesLocalDns;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class DnsResolveTldCommand extends LocalOnlyCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'dns:resolve-tld
        {tld? : Development TLD to configure, without a leading dot}
        {target? : IP address that local wildcard hostnames under the TLD should resolve to}
        {--reset : Remove the local resolver override for the TLD}
        {--force : Confirm destructive operation without prompting}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Configure or remove a caller-local development TLD resolver override';

    public function handle(ResolvesLocalDns $resolver): int
    {
        $tld = $this->resolveTld();

        if (is_int($tld)) {
            return $tld;
        }

        $validationResult = $this->validateTld($tld);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        $isReset = (bool) $this->option('reset');

        if ($isReset && $this->argument('target') !== null) {
            return $this->renderFailure(
                'validation_failed',
                'Target is not allowed when --reset is present.',
                ['field' => 'target', 'reason' => 'forbidden'],
            );
        }

        if (! $isReset && $this->option('force')) {
            return $this->renderFailure(
                'validation_failed',
                '--force is only allowed with --reset.',
                ['field' => 'force', 'reason' => 'forbidden'],
            );
        }

        if ($isReset) {
            return $this->handleReset($resolver, $tld);
        }

        return $this->handleResolve($resolver, $tld);
    }

    private function handleResolve(ResolvesLocalDns $resolver, string $tld): int
    {
        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        $validationResult = $this->validateTarget($target);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        if (! $resolver->supportsMutation()) {
            return $this->renderFailure(
                'node.unsupported_platform',
                'This platform does not support automatic local DNS resolver configuration.',
                ['platform' => $resolver->platform()],
            );
        }

        if (! $resolver->isDnsmasqInstalled()) {
            return $this->renderFailure(
                'node.unsupported_platform',
                'This platform does not support automatic local DNS resolver configuration.',
                ['platform' => $resolver->platform(), 'reason' => 'dnsmasq_not_installed'],
            );
        }

        if (! $this->wantsJson()) {
            return $this->renderResolveTree($resolver, $tld, $target);
        }

        $result = $resolver->resolve($tld, $target);

        $data = $this->resolveData($resolver, $tld, $target, $result);

        if ($result['status'] === 'write_failed') {
            return $this->renderFailure(
                'local_resolver_write_failed',
                'Failed to update local DNS resolver configuration.',
                $this->resolverFailureMeta($resolver, $tld, $result),
            );
        }

        if ($result['status'] === 'refresh_failed') {
            return $this->renderFailure(
                'local_resolver_refresh_failed',
                'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                $this->resolverFailureMeta($resolver, $tld, $result),
                $data,
            );
        }

        return $this->renderSuccess($data);
    }

    /**
     * Render the documented animated tree for the resolve path. Convergence is
     * detected before any mutation so the tree shows the documented two-step
     * "Check resolver override" shape when nothing would change, and the
     * three-step Write/Refresh shape otherwise.
     */
    private function renderResolveTree(ResolvesLocalDns $resolver, string $tld, string $target): int
    {
        $alreadyResolved =
            $resolver->supportsMutation()
            && $resolver->isDnsmasqInstalled()
            && $resolver->existingTarget($tld) === $target;

        if ($alreadyResolved) {
            $result = null;
            $outcome = $this->runStepOperation(
                'Configuring Local DNS',
                [
                    ['label' => "Validate .{$tld}"],
                    ['label' => 'Check resolver override'],
                ],
                work: function () use ($resolver, $tld, $target, &$result): void {
                    $result = $resolver->resolve($tld, $target);

                    if ($result['status'] === 'write_failed') {
                        throw new RuntimeException('Failed to update local DNS resolver configuration.');
                    }

                    if ($result['status'] === 'refresh_failed') {
                        throw new RuntimeException(
                            'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                        );
                    }
                },
                doneFooter: function () use ($tld, $target, &$result): string {
                    if (is_array($result) && ($result['changed'] ?? false) === true) {
                        return ".{$tld} resolves to {$target}.";
                    }

                    return ".{$tld} already resolves to {$target}.";
                },
            );

            return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
        }

        $outcome = $this->runStepOperation(
            'Configuring Local DNS',
            [
                ['label' => "Validate .{$tld}"],
                ['label' => 'Write resolver override'],
                ['label' => 'Refresh resolver'],
            ],
            work: function () use ($resolver, $tld, $target): void {
                $this->guardResolverForHuman($resolver);

                $result = $resolver->resolve($tld, $target);

                if ($result['status'] === 'write_failed') {
                    throw new RuntimeException('Failed to update local DNS resolver configuration.');
                }

                if ($result['status'] === 'refresh_failed') {
                    throw new RuntimeException(
                        'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                    );
                }
            },
            doneFooter: ".{$tld} resolves to {$target}.",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{status: string, changed: bool, error?: string}  $result
     * @return array<string, array<string, mixed>>
     */
    private function resolveData(ResolvesLocalDns $resolver, string $tld, string $target, array $result): array
    {
        return [
            'dns' => [
                'tld' => $tld,
                'target' => $target,
                'action' => 'resolve',
                'status' => $result['status'],
                'changed' => $result['changed'],
                'source' => 'local_resolver',
                'resolver_backend' => $resolver->backend(),
            ],
        ];
    }

    private function handleReset(ResolvesLocalDns $resolver, string $tld): int
    {
        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->renderFailure(
                    'validation_failed',
                    'Reset requires --force in non-interactive mode.',
                    ['field' => 'force', 'reason' => 'destructive_consent_required'],
                );
            }

            if (! confirm("Remove the local resolver override for .{$tld}?", default: false)) {
                return $this->renderFailure(
                    'validation_failed',
                    'Reset cancelled. No local resolver changes were made.',
                    ['field' => 'force', 'reason' => 'destructive_consent_required'],
                );
            }
        }

        if (! $this->wantsJson()) {
            return $this->renderResetTree($resolver, $tld);
        }

        if (! $resolver->supportsMutation()) {
            return $this->renderFailure(
                'node.unsupported_platform',
                'This platform does not support automatic local DNS resolver configuration.',
                ['platform' => $resolver->platform()],
            );
        }

        if (! $resolver->isDnsmasqInstalled()) {
            return $this->renderFailure(
                'node.unsupported_platform',
                'This platform does not support automatic local DNS resolver configuration.',
                ['platform' => $resolver->platform(), 'reason' => 'dnsmasq_not_installed'],
            );
        }

        $result = $resolver->reset($tld);

        $data = $this->resetData($resolver, $tld, $result);

        if ($result['status'] === 'write_failed') {
            return $this->renderFailure(
                'local_resolver_write_failed',
                'Failed to update local DNS resolver configuration.',
                $this->resolverFailureMeta($resolver, $tld, $result),
            );
        }

        if ($result['status'] === 'refresh_failed') {
            return $this->renderFailure(
                'local_resolver_refresh_failed',
                'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                $this->resolverFailureMeta($resolver, $tld, $result),
                $data,
            );
        }

        return $this->renderSuccess($data);
    }

    /**
     * Render the documented animated tree for the reset path. Convergence is
     * detected before any mutation so the tree shows the documented two-step
     * "Check resolver override" shape when the override is already absent, and
     * the three-step Remove/Refresh shape otherwise.
     */
    private function renderResetTree(ResolvesLocalDns $resolver, string $tld): int
    {
        $alreadyAbsent =
            $resolver->supportsMutation()
            && $resolver->isDnsmasqInstalled()
            && $resolver->existingTarget($tld) === null;

        if ($alreadyAbsent) {
            $outcome = $this->runStepOperation(
                'Resetting Local DNS',
                [
                    ['label' => "Validate .{$tld}"],
                    ['label' => 'Check resolver override'],
                ],
                work: fn (): bool => true,
                doneFooter: ".{$tld} resolver override already absent.",
            );

            return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
        }

        $outcome = $this->runStepOperation(
            'Resetting Local DNS',
            [
                ['label' => "Validate .{$tld}"],
                ['label' => 'Remove resolver override'],
                ['label' => 'Refresh resolver'],
            ],
            work: function () use ($resolver, $tld): void {
                $this->guardResolverForHuman($resolver);

                $result = $resolver->reset($tld);

                if ($result['status'] === 'write_failed') {
                    throw new RuntimeException('Failed to update local DNS resolver configuration.');
                }

                if ($result['status'] === 'refresh_failed') {
                    throw new RuntimeException(
                        'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                    );
                }
            },
            doneFooter: ".{$tld} resolver override removed.",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{status: string, changed: bool, error?: string}  $result
     * @return array<string, array<string, mixed>>
     */
    private function resetData(ResolvesLocalDns $resolver, string $tld, array $result): array
    {
        return [
            'dns' => [
                'tld' => $tld,
                'target' => null,
                'action' => 'reset',
                'status' => $result['status'],
                'changed' => $result['changed'],
                'source' => 'local_resolver',
                'resolver_backend' => $resolver->backend(),
            ],
        ];
    }

    /**
     * Throw the documented unsupported-platform prose so the human tree footer
     * surfaces the failure instead of a structured envelope.
     */
    private function guardResolverForHuman(ResolvesLocalDns $resolver): void
    {
        if (! $resolver->supportsMutation() || ! $resolver->isDnsmasqInstalled()) {
            throw new RuntimeException('This platform does not support automatic local DNS resolver configuration.');
        }
    }

    private function resolveTld(): string|int
    {
        $tld = $this->argument('tld');

        if ($tld === null) {
            if ($this->isInteractiveInput()) {
                $tld = text(label: 'Development TLD', required: true);

                if ($tld === '') {
                    return $this->renderFailure(
                        'validation_failed',
                        'Development TLD is required.',
                        ['field' => 'tld', 'reason' => 'missing'],
                    );
                }
            } else {
                return $this->renderFailure(
                    'validation_failed',
                    'Development TLD is required.',
                    ['field' => 'tld', 'reason' => 'missing'],
                );
            }
        }

        return (string) $tld;
    }

    private function resolveTarget(): string|int
    {
        $target = $this->argument('target');

        if ($target === null) {
            if ($this->isInteractiveInput()) {
                $target = text(label: 'Target IP address', required: true);

                if ($target === '') {
                    return $this->renderFailure(
                        'validation_failed',
                        'Target IP address is required.',
                        ['field' => 'target', 'reason' => 'missing'],
                    );
                }
            } else {
                return $this->renderFailure(
                    'validation_failed',
                    'Target IP address is required.',
                    ['field' => 'target', 'reason' => 'missing'],
                );
            }
        }

        return (string) $target;
    }

    private function validateTld(string $tld): ?int
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld)) {
            return $this->renderFailure(
                'validation_failed',
                'Development TLD must be a single lowercase DNS label without a leading dot.',
                ['field' => 'tld', 'reason' => 'invalid'],
            );
        }

        return null;
    }

    private function validateTarget(string $target): ?int
    {
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            return $this->renderFailure(
                'validation_failed',
                'Target must be an IPv4 or IPv6 address.',
                ['field' => 'target', 'reason' => 'invalid'],
            );
        }

        return null;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->option('json') && $this->input->isInteractive();
    }

    /**
     * @param  array{status: string, changed: bool, error?: string}  $result
     * @return array<string, string>
     */
    private function resolverFailureMeta(ResolvesLocalDns $resolver, string $tld, array $result): array
    {
        $meta = [
            'tld' => $tld,
            'resolver_backend' => $resolver->backend(),
        ];

        if (isset($result['error']) && $result['error'] !== '') {
            $meta['diagnostics'] = $result['error'];
        }

        return $meta;
    }
}
