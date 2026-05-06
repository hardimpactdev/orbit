<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('update:all {--json : Output as JSON}')]
#[Description('Update this Orbit checkout and every active registered node')]
class UpdateAllCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    /**
     * @var list<array{target: string, node: string|null, role: string|null}>
     */
    private array $targets = [];

    /**
     * @var array{total: int, completed: int, failed: int}|null
     */
    private ?array $activitySummary = null;

    private ?string $activityStatus = null;

    private ?string $activityFailedStep = null;

    public function handle(OrbitUpdater $updater): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeUpdateAll($updater);
        } finally {
            $this->finishActivityLog();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'scope' => 'fleet',
            'status' => $this->activityStatus,
            'summary' => $this->activitySummary,
            'targets' => $this->targets,
            'failed_step' => $this->activityFailedStep,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function description(): ?string
    {
        return match ($this->activityStatus) {
            'completed' => 'Orbit installations updated',
            'failed' => 'One or more Orbit installations failed to update',
            default => 'Orbit fleet update attempted',
        };
    }

    private function executeUpdateAll(OrbitUpdater $updater): int
    {
        $nodes = Node::query()
            ->where('status', 'active')
            ->where('is_local', false)
            ->where('role', '!=', 'control')
            ->orderBy('name')
            ->get();

        $this->targets = $this->buildTargets($nodes);

        if ($this->wantsJson()) {
            return $this->handleJson($updater, $nodes);
        }

        return $this->handleHuman($updater, $nodes);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function handleJson(OrbitUpdater $updater, $nodes): int
    {
        $updates = [];
        $localResult = $updater->updateLocal();

        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            $this->captureActivitySummary($updates, 'failed', 'local_checkout');

            return $this->jsonError(
                code: 'local_update_failed',
                message: 'Failed to update local Orbit checkout.',
                data: [
                    'output' => trim($localResult->errorOutput() ?: $localResult->output()),
                ],
                meta: ['failed_step' => 'local_checkout'],
                updates: $updates,
            );
        }

        foreach ($nodes as $node) {
            $result = $updater->updateRemote($node);
            $updates[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'status' => $result->successful() ? 'completed' : 'failed',
                ...($result->successful() ? [] : ['output' => trim($result->errorOutput() ?: $result->output())]),
            ];
        }

        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($failed > 0) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->jsonError(
                code: 'remote_update_failed',
                message: 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => [
                        'total' => count($updates),
                        'completed' => $completed,
                        'failed' => $failed,
                    ],
                ],
            );
        }

        $this->captureActivitySummary($updates, 'completed');

        return $this->jsonSuccess($updates);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function handleHuman(OrbitUpdater $updater, $nodes): int
    {
        $this->renderProgressTree();

        $results = [];
        $localResult = $updater->updateLocal();
        $results['local'] = $localResult->successful() ? 'completed' : 'failed';
        $this->updateProgressTree($results);

        if (! $localResult->successful()) {
            $this->captureActivitySummaryFromResults($results, 'failed', 'local_checkout');

            $this->line('');
            $this->error('Failed to update local Orbit checkout.');
            $output = trim($localResult->errorOutput() ?: $localResult->output());

            if ($output !== '') {
                $this->line($output);
            }

            return self::FAILURE;
        }

        $failed = false;

        foreach ($nodes as $node) {
            $result = $updater->updateRemote($node);
            $results[$node->name] = $result->successful() ? 'completed' : 'failed';
            $this->updateProgressTree($results);

            if (! $result->successful()) {
                $failed = true;
                $this->line('');
                $this->error("Failed to update node {$node->name}.");
                $output = trim($result->errorOutput() ?: $result->output());

                if ($output !== '') {
                    $this->line($output);
                }

                continue;
            }
        }

        $this->captureActivitySummaryFromResults($results, $failed ? 'failed' : 'completed', $failed ? 'remote_update' : null);

        $this->line('');
        $this->info('Updated local Orbit checkout.');

        foreach ($nodes as $node) {
            if ($results[$node->name] === 'completed') {
                $this->info("Updated node {$node->name}.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function captureActivitySummary(array $updates, string $status, ?string $failedStep = null): void
    {
        $this->activityStatus = $status;
        $this->activityFailedStep = $failedStep;
        $this->activitySummary = [
            'total' => count($updates),
            'completed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'completed')),
            'failed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'failed')),
        ];
    }

    /**
     * @param  array<string, string>  $results
     */
    private function captureActivitySummaryFromResults(array $results, string $status, ?string $failedStep = null): void
    {
        $updates = [];

        foreach ($this->targets as $target) {
            $updates[] = [
                ...$target,
                'status' => $results[$target['target']] ?? 'pending',
            ];
        }

        $this->captureActivitySummary($updates, $status, $failedStep);
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented update:all result.
        }
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array{target: string, node: string|null, role: string|null}>
     */
    private function buildTargets($nodes): array
    {
        $targets = [
            [
                'target' => 'local',
                'node' => null,
                'role' => null,
            ],
        ];

        foreach ($nodes as $node) {
            $targets[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
            ];
        }

        return $targets;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function jsonSuccess(array $updates): int
    {
        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        $this->line(json_encode([
            'success' => [
                'data' => [
                    'updates' => $updates,
                ],
                'meta' => [
                    'summary' => [
                        'total' => count($updates),
                        'completed' => $completed,
                        'failed' => $failed,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonError(
        string $code,
        string $message,
        array $data = [],
        array $meta = [],
        array $updates = [],
    ): int {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        if ($updates !== []) {
            $error['data']['updates'] = $updates;
        }

        $this->line(json_encode([
            'error' => $error,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }

    private function renderProgressTree(): void
    {
        $this->line('┌ Updating Orbit Installations');

        foreach ($this->targets as $target) {
            $label = $target['target'] === 'local'
                ? 'Update local checkout'
                : "Update {$target['target']}";
            $this->line("○ {$label}");
        }

        $this->line('└ Working...');
    }

    /**
     * @param  array<string, string>  $results
     */
    private function updateProgressTree(array $results): void
    {
        $lines = count($this->targets) + 2;

        for ($i = 0; $i < $lines; $i++) {
            $this->output->write("\e[1A\e[2K");
        }

        $this->line('┌ Updating Orbit Installations');

        foreach ($this->targets as $target) {
            $label = $target['target'] === 'local'
                ? 'Update local checkout'
                : "Update {$target['target']}";
            $status = $results[$target['target']] ?? 'pending';
            $symbol = match ($status) {
                'completed' => '●',
                'failed' => '✖',
                default => '○',
            };
            $this->line("{$symbol} {$label}");
        }

        $hasFailure = in_array('failed', $results, true);
        $footer = $hasFailure ? 'Failed' : 'Working...';
        $this->line("└ {$footer}");
    }
}
