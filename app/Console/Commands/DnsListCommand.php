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
use RuntimeException;

#[Signature('dns:list
    {--json : Output as JSON}')]
#[Description('List caller-local Orbit DNS resolver overrides')]
class DnsListCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    private ?int $activityCount = null;

    private ?string $activityResolverBackend = null;

    public function handle(LocalResolver $resolver): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeDnsList($resolver);
        } finally {
            $this->finishActivityLog();
        }
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'count' => $this->activityCount,
            'resolver_backend' => $this->activityResolverBackend,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        return 'Local DNS resolver overrides listed';
    }

    private function executeDnsList(LocalResolver $resolver): int
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
                    'field' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                ],
            );
        }

        if (! $resolver->isSupported()) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic local DNS resolver inspection.',
                meta: ['platform' => $resolver->platform()],
            );
        }

        try {
            $dns = $resolver->listOverrides();
        } catch (RuntimeException) {
            return $this->failCommand(
                code: 'local_resolver_read_failed',
                message: 'Could not read local DNS resolver configuration.',
                meta: ['resolver_backend' => $resolver->backend()],
            );
        }

        $meta = [
            'count' => count($dns),
            'resolver_backend' => $resolver->backend(),
        ];
        $this->activityCount = $meta['count'];
        $this->activityResolverBackend = $meta['resolver_backend'];

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['dns' => $dns], $meta);
        }

        $this->renderHuman($dns);

        return self::SUCCESS;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented dns:list result.
        }
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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<int, array<string, string>>  $dns
     */
    private function renderHuman(array $dns): void
    {
        if ($dns === []) {
            $this->line('No local Orbit DNS overrides found.');

            return;
        }

        $rows = array_map(fn (array $entry): array => [
            'tld' => $entry['tld'],
            'target' => $entry['target'],
            'source' => $entry['source'],
            'backend' => $entry['resolver_backend'],
            'status' => $entry['status'],
        ], $dns);

        $this->line('TLD    TARGET     SOURCE           BACKEND   STATUS');

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%-6s %-10s %-16s %-9s %s',
                $row['tld'],
                $row['target'],
                $row['source'],
                $row['backend'],
                $row['status'],
            ));
        }
    }
}
