<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Dns\LocalResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

#[Signature('dns:resolve-tld
    {tld? : Development TLD to configure, without a leading dot}
    {target? : IP address that local wildcard hostnames under the TLD should resolve to}
    {--reset : Remove the local resolver override for the TLD}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Configure or remove a caller-local development TLD resolver override')]
class DnsResolveTldCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    private ?string $activityTld = null;

    private ?string $activityTarget = null;

    private ?string $activityAction = null;

    private ?string $activityStatus = null;

    private ?bool $activityChanged = null;

    private ?string $activityResolverBackend = null;

    public function handle(LocalResolver $resolver): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeDnsResolveTld($resolver);
        } finally {
            $this->finishActivityLog();
        }
    }

    public function effect(): ActivityLogType
    {
        return (bool) $this->option('reset')
            ? ActivityLogType::Destructive
            : ActivityLogType::Write;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $tld = $this->activityTld;
        $target = $this->activityTarget;

        if ($tld === null) {
            $argument = $this->argument('tld');
            $tld = is_string($argument) && $argument !== '' ? $argument : null;
        }

        if ($target === null) {
            $argument = $this->argument('target');
            $target = is_string($argument) && $argument !== '' ? $argument : null;
        }

        return array_filter([
            'tld' => $tld,
            'target' => $target,
            'action' => $this->activityAction ?? ((bool) $this->option('reset') ? 'reset' : 'resolve'),
            'status' => $this->activityStatus,
            'changed' => $this->activityChanged,
            'resolver_backend' => $this->activityResolverBackend,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        if ($this->activityAction === 'reset' && $this->activityTld !== null) {
            return "Local DNS .{$this->activityTld} resolver override reset";
        }

        if ($this->activityAction === 'resolve' && $this->activityTld !== null) {
            return "Local DNS .{$this->activityTld} resolver override configured";
        }

        return 'Local DNS resolver override attempted';
    }

    private function executeDnsResolveTld(LocalResolver $resolver): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'gateway') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => 'gateway'],
            );
        }

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => 'app'],
            );
        }

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $tld = $this->resolveTld();

        if (is_int($tld)) {
            return $tld;
        }

        $validation = $this->validateTld($tld);
        $this->activityTld = $tld;

        if (is_int($validation)) {
            return $validation;
        }

        $isReset = (bool) $this->option('reset');
        $this->activityAction = $isReset ? 'reset' : 'resolve';

        if ($isReset && $this->argument('target') !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Target is not allowed when --reset is present.',
                meta: ['field' => 'target', 'reason' => 'forbidden'],
            );
        }

        if (! $isReset && $this->option('force')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--force is only allowed with --reset.',
                meta: ['field' => 'force', 'reason' => 'forbidden'],
            );
        }

        if ($isReset) {
            return $this->handleReset($resolver, $tld);
        }

        return $this->handleResolve($resolver, $tld);
    }

    private function handleResolve(LocalResolver $resolver, string $tld): int
    {
        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        $validation = $this->validateTarget($target);
        $this->activityTarget = $target;

        if (is_int($validation)) {
            return $validation;
        }

        if (! $resolver->supportsMutation()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver configuration.',
                meta: ['platform' => $resolver->platform()],
            );
        }

        if (! $resolver->isDnsmasqInstalled()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver configuration.',
                meta: ['platform' => $resolver->platform(), 'reason' => 'dnsmasq_not_installed'],
            );
        }

        $existing = $resolver->existingTarget($tld);

        if (! $this->wantsJson()) {
            if ($existing === $target) {
                $this->renderAlreadyResolvedTree($tld, $target);
            } else {
                $this->renderResolveTree($tld);
            }
        }

        $result = $resolver->resolve($tld, $target);

        if ($result['status'] === 'write_failed') {
            return $this->failCommand(
                code: 'local_resolver_write_failed',
                message: 'Failed to update local DNS resolver configuration.',
                meta: [
                    'tld' => $tld,
                    'resolver_backend' => $resolver->backend(),
                ],
            );
        }

        if ($result['status'] === 'refresh_failed') {
            return $this->failCommand(
                code: 'local_resolver_refresh_failed',
                message: 'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                meta: [
                    'tld' => $tld,
                    'resolver_backend' => $resolver->backend(),
                ],
                data: [
                    'dns' => [
                        'tld' => $tld,
                        'target' => $target,
                        'action' => 'resolve',
                        'status' => 'refresh_failed',
                        'changed' => true,
                        'source' => 'local_resolver',
                        'resolver_backend' => $resolver->backend(),
                    ],
                ],
            );
        }

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
        $this->captureActivityResult($data['dns']);

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        if ($result['status'] === 'already_resolved') {
            $this->line(".{$tld} already resolves to {$target}.");
        } else {
            $this->line(".{$tld} resolves to {$target}.");
        }

        return self::SUCCESS;
    }

    private function handleReset(LocalResolver $resolver, string $tld): int
    {
        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'destructive_consent_required',
                    message: 'Reset requires --force in non-interactive mode.',
                    meta: ['field' => 'force', 'reason' => 'missing'],
                );
            }

            if (! confirm("Remove the local resolver override for .{$tld}?", default: false)) {
                return $this->failCommand(
                    code: 'destructive_consent_required',
                    message: 'Reset cancelled. No local resolver changes were made.',
                    meta: [],
                );
            }
        }

        if (! $resolver->supportsMutation()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver configuration.',
                meta: ['platform' => $resolver->platform()],
            );
        }

        if (! $resolver->isDnsmasqInstalled()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver configuration.',
                meta: ['platform' => $resolver->platform(), 'reason' => 'dnsmasq_not_installed'],
            );
        }

        $existing = $resolver->existingTarget($tld);
        $hasResolver = Process::timeout(10)->run("test -f /etc/resolver/{$tld}")->successful();

        if (! $this->wantsJson()) {
            if ($existing === null && ! $hasResolver) {
                $this->renderAlreadyAbsentTree($tld);
            } else {
                $this->renderResetTree($tld);
            }
        }

        $result = $resolver->reset($tld);

        if ($result['status'] === 'write_failed') {
            return $this->failCommand(
                code: 'local_resolver_write_failed',
                message: 'Failed to update local DNS resolver configuration.',
                meta: [
                    'tld' => $tld,
                    'resolver_backend' => $resolver->backend(),
                ],
            );
        }

        if ($result['status'] === 'refresh_failed') {
            return $this->failCommand(
                code: 'local_resolver_refresh_failed',
                message: 'Local DNS resolver configuration changed, but the resolver could not be refreshed.',
                meta: [
                    'tld' => $tld,
                    'resolver_backend' => $resolver->backend(),
                ],
                data: [
                    'dns' => [
                        'tld' => $tld,
                        'target' => null,
                        'action' => 'reset',
                        'status' => 'refresh_failed',
                        'changed' => true,
                        'source' => 'local_resolver',
                        'resolver_backend' => $resolver->backend(),
                    ],
                ],
            );
        }

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
        $this->captureActivityResult($data['dns']);

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        if ($result['status'] === 'already_absent') {
            $this->line(".{$tld} resolver override already absent.");
        } else {
            $this->line(".{$tld} resolver override removed.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $dns
     */
    private function captureActivityResult(array $dns): void
    {
        $this->activityTld = is_string($dns['tld'] ?? null) ? $dns['tld'] : $this->activityTld;
        $this->activityTarget = is_string($dns['target'] ?? null) ? $dns['target'] : null;
        $this->activityAction = is_string($dns['action'] ?? null) ? $dns['action'] : $this->activityAction;
        $this->activityStatus = is_string($dns['status'] ?? null) ? $dns['status'] : null;
        $this->activityChanged = is_bool($dns['changed'] ?? null) ? $dns['changed'] : null;
        $this->activityResolverBackend = is_string($dns['resolver_backend'] ?? null) ? $dns['resolver_backend'] : null;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented dns:resolve-tld result.
        }
    }

    private function resolveTld(): string|int
    {
        $tld = $this->argument('tld');

        if ($tld === null) {
            if ($this->isInteractiveInput()) {
                $tld = text(label: 'Development TLD', required: true);

                if ($tld === '') {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: 'Development TLD is required.',
                        meta: ['field' => 'tld', 'reason' => 'missing'],
                    );
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Development TLD is required.',
                    meta: ['field' => 'tld', 'reason' => 'missing'],
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
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: 'Target IP address is required.',
                        meta: ['field' => 'target', 'reason' => 'missing'],
                    );
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Target IP address is required.',
                    meta: ['field' => 'target', 'reason' => 'missing'],
                );
            }
        }

        return (string) $target;
    }

    private function validateTld(string $tld): ?int
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Development TLD must be a single lowercase DNS label without a leading dot.',
                meta: ['field' => 'tld', 'reason' => 'invalid'],
            );
        }

        return null;
    }

    private function validateTarget(string $target): ?int
    {
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Target must be an IPv4 or IPv6 address.',
                meta: ['field' => 'target', 'reason' => 'invalid'],
            );
        }

        return null;
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->option('json')
            && function_exists('posix_isatty')
            && @posix_isatty(STDOUT);
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $data
     */
    private function failCommand(string $code, string $message, array $meta, ?array $data = null): int
    {
        if ($this->wantsJson()) {
            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ];

            if ($data !== null) {
                $payload['error']['data'] = $data;
            }

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function renderResolveTree(string $tld): void
    {
        $this->line('┌ Configuring Local DNS');
        $this->line("○ Validate .{$tld}");
        $this->line('○ Write resolver override');
        $this->line('○ Refresh resolver');
        $this->line('└ Working...');
    }

    private function renderAlreadyResolvedTree(string $tld, string $target): void
    {
        $this->line('┌ Configuring Local DNS');
        $this->line("○ Validate .{$tld}");
        $this->line('○ Check resolver override');
        $this->line("└ .{$tld} already resolves to {$target}");
    }

    private function renderResetTree(string $tld): void
    {
        $this->line('┌ Resetting Local DNS');
        $this->line("○ Validate .{$tld}");
        $this->line('○ Remove resolver override');
        $this->line('○ Refresh resolver');
        $this->line("└ .{$tld} resolver override removed");
    }

    private function renderAlreadyAbsentTree(string $tld): void
    {
        $this->line('┌ Resetting Local DNS');
        $this->line("○ Validate .{$tld}");
        $this->line('○ Check resolver override');
        $this->line("└ .{$tld} resolver override already absent");
    }
}
