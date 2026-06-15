<?php

declare(strict_types=1);

namespace App\Commands\Dns;

use App\Commands\LocalOnlyCommand;
use App\Services\Dns\ResolvesLocalDns;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class DnsResolveTldCommand extends LocalOnlyCommand
{
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

        $result = $resolver->resolve($tld, $target);

        $data = [
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

        if ($result['status'] === 'write_failed') {
            return $this->renderFailure(
                'local_resolver_write_failed',
                'Failed to update local DNS resolver configuration.',
                ['tld' => $tld, 'resolver_backend' => $resolver->backend()],
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

        if ($this->wantsJson()) {
            return $this->renderSuccess($data);
        }

        if ($result['status'] === 'already_resolved') {
            $this->line(".{$tld} already resolves to {$target}.");
        } else {
            $this->line(".{$tld} resolves to {$target}.");
        }

        return self::SUCCESS;
    }

    private function handleReset(ResolvesLocalDns $resolver, string $tld): int
    {
        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->renderFailure(
                    'destructive_consent_required',
                    'Reset requires --force in non-interactive mode.',
                    ['field' => 'force', 'reason' => 'missing'],
                );
            }

            if (! confirm("Remove the local resolver override for .{$tld}?", default: false)) {
                return $this->renderFailure(
                    'destructive_consent_required',
                    'Reset cancelled. No local resolver changes were made.',
                    [],
                );
            }
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

        $data = [
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

        if ($result['status'] === 'write_failed') {
            return $this->renderFailure(
                'local_resolver_write_failed',
                'Failed to update local DNS resolver configuration.',
                ['tld' => $tld, 'resolver_backend' => $resolver->backend()],
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

        if ($this->wantsJson()) {
            return $this->renderSuccess($data);
        }

        if ($result['status'] === 'already_absent') {
            $this->line(".{$tld} resolver override already absent.");
        } else {
            $this->line(".{$tld} resolver override removed.");
        }

        return self::SUCCESS;
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
