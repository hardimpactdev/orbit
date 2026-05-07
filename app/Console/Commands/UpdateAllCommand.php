<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Contracts\UpdateAllGatewayStream;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Operations\UpdateAllRequest;
use App\Http\Gateway\Responses\Operations\UpdateAllResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\OrbitUpdater;
use App\Support\Cli\UpdateAllProgress;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

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

    public function handle(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeUpdateAll($updater, $gatewayStream);
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

    private function executeUpdateAll(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
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

        if ($callerRole === 'control' && ! $this->hasConfiguredGateway()) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update the fleet.',
                meta: [],
            );
        }

        if ($callerRole === 'control') {
            return $this->executeControlPath($updater, $gatewayStream);
        }

        return $this->executeGatewayPath($updater);
    }

    private function executeControlPath(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        if (! $this->wantsJson()) {
            return $this->executeControlHumanPath($updater, $gatewayStream);
        }

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

            return $this->failCommand(
                code: 'local_update_failed',
                message: 'Failed to update local Orbit checkout.',
                data: ['output' => trim($localResult->errorOutput() ?: $localResult->output())],
                meta: ['failed_step' => 'local_checkout'],
            );
        }

        try {
            /** @var UpdateAllResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new UpdateAllRequest)
                ->dto();
        } catch (GatewayApiException $e) {
            $errorData = $e->errorData();
            $updates = array_merge($updates, $errorData['updates'] ?? []);
            $errorData['updates'] = $updates;

            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to update the fleet.',
                meta: $e->errorMeta(),
                data: $errorData,
            );
        } catch (Throwable) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update the fleet.',
                meta: [],
            );
        }

        $updates = array_merge($updates, $dto->updates);

        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($failed > 0) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
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

        return $this->respondSuccess($updates);
    }

    private function executeControlHumanPath(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $this->targets = [['target' => 'local', 'node' => null, 'role' => null]];
        $localUpdate = ['target' => 'local', 'node' => null, 'role' => null, 'status' => 'pending'];

        $progress = $this->openProgress();
        $localResult = $this->updateLocalWithProgress($updater, $progress);
        $localUpdate['status'] = $localResult->successful() ? 'completed' : 'failed';

        if (! $localResult->successful()) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary([$localUpdate], 'failed', 'local_checkout');
            $this->writePostTreeFailure('Failed to update local Orbit checkout.', $localResult->errorOutput() ?: $localResult->output());

            return self::FAILURE;
        }

        $streamUpdates = [];
        $streamError = null;
        $streamData = [];

        $streamResult = $gatewayStream->run(function (string $event, array $payload) use ($progress, &$streamUpdates, &$streamError, &$streamData): void {
            if ($event === 'tree') {
                $this->mergeStreamTargets($payload, $progress);

                return;
            }

            if ($event === 'step') {
                $this->applyStreamStep($payload, $progress);

                return;
            }

            if (in_array($event, ['complete', 'error'], true)) {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $streamUpdates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
                $streamData = $data;

                if ($event === 'error') {
                    $streamError = is_string($payload['message'] ?? null)
                        ? $payload['message']
                        : 'One or more Orbit installations failed to update.';
                }
            }
        });

        $updates = array_merge([$localUpdate], $streamUpdates);

        if ($streamResult instanceof GatewayApiException) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: $streamResult->errorCode() ?? 'gateway_unavailable',
                message: $streamResult->getMessage() !== ''
                    ? $streamResult->getMessage()
                    : 'Gateway connection is required to update the fleet.',
                meta: $streamResult->errorMeta(),
                data: $streamResult->errorData(),
            );
        }

        if ($streamResult !== self::SUCCESS || $streamError !== null) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: is_string($streamData['code'] ?? null) ? $streamData['code'] : 'remote_update_failed',
                message: $streamError ?? 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => $this->summary($updates),
                ],
            );
        }

        $progress->finish(success: true, footer: 'Successfully updated '.$this->nodeCountLabel(count($updates)));
        $this->captureActivitySummary($updates, 'completed');

        return $this->respondSuccess($updates);
    }

    private function executeGatewayPath(OrbitUpdater $updater): int
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

            return $this->failCommand(
                code: 'local_update_failed',
                message: 'Failed to update local Orbit checkout.',
                data: [
                    'output' => trim($localResult->errorOutput() ?: $localResult->output()),
                ],
                meta: ['failed_step' => 'local_checkout'],
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

            return $this->failCommand(
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

        return $this->respondSuccess($updates);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function handleHuman(OrbitUpdater $updater, $nodes): int
    {
        $progress = $this->openProgress();
        $updates = [];

        $localResult = $this->updateLocalWithProgress($updater, $progress);
        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'local_checkout');
            $this->writePostTreeFailure('Failed to update local Orbit checkout.', $localResult->errorOutput() ?: $localResult->output());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($nodes as $node) {
            $result = $this->updateRemoteWithProgress($updater, $node, $progress);
            $updates[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'status' => $result->successful() ? 'completed' : 'failed',
            ];

            if (! $result->successful()) {
                $failed = true;
                $this->writePostTreeFailure("Failed to update node {$node->name}.", $result->errorOutput() ?: $result->output());
            }
        }

        if ($failed) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return self::FAILURE;
        }

        $progress->finish(success: true, footer: 'Successfully updated '.$this->nodeCountLabel(count($updates)));
        $this->captureActivitySummary($updates, 'completed');

        return self::SUCCESS;
    }

    private function updateLocalWithProgress(OrbitUpdater $updater, UpdateAllProgress $progress): ProcessResult
    {
        $progress->start('local');
        $progress->stage('local', 'pulling_source');
        $result = $updater->pullSource();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage('local', 'installing_dependencies');
        $result = $updater->installDependencies();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage('local', 'running_migrations');
        $result = $updater->runMigrations();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->done('local');

        return $result;
    }

    private function updateRemoteWithProgress(OrbitUpdater $updater, Node $node, UpdateAllProgress $progress): RemoteShellResult
    {
        $progress->start($node->name);
        $progress->stage($node->name, 'pulling_source');
        $result = $updater->pullRemoteSource($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage($node->name, 'installing_dependencies');
        $result = $updater->installRemoteDependencies($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage($node->name, 'running_migrations');
        $result = $updater->runRemoteMigrations($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->done($node->name);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeStreamTargets(array $payload, UpdateAllProgress $progress): void
    {
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $remoteTargets = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $key = $step['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $remoteTargets[] = ['target' => $key, 'node' => $key, 'role' => null];
        }

        $this->targets = array_merge(
            [['target' => 'local', 'node' => null, 'role' => null]],
            $remoteTargets,
        );

        $progress->extendWith($remoteTargets);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStreamStep(array $payload, UpdateAllProgress $progress): void
    {
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : null;
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : null;

        if ($key === null || $status === null) {
            return;
        }

        match ($status) {
            'start' => $progress->start($key),
            'pulling_source', 'installing_dependencies', 'running_migrations' => $progress->stage($key, $status),
            'done' => $progress->done($key),
            'fail' => $progress->fail($key, is_string($payload['message'] ?? null) ? $payload['message'] : 'failed'),
            'skip' => $progress->done($key),
            default => null,
        };
    }

    private function failureMessage(string $output): string
    {
        $message = trim($output);

        return $message !== '' ? $message : 'failed';
    }

    private function writePostTreeFailure(string $headline, string $captured): void
    {
        $this->line('');
        $this->error($headline);

        $captured = trim($captured);

        if ($captured !== '') {
            $this->line($captured);
        }
    }

    private function openProgress(): UpdateAllProgress
    {
        return new UpdateAllProgress(
            output: $this->output,
            initialTargets: $this->targets,
        );
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

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (Throwable) {
            // Activity logging must not change the documented update:all result.
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

    private function hasConfiguredGateway(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->exists();
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array{target: string, node: string|null, role: string|null}>
     */
    private function buildTargets($nodes): array
    {
        $targets = [['target' => 'local', 'node' => null, 'role' => null]];

        foreach ($nodes as $node) {
            $targets[] = ['target' => $node->name, 'node' => $node->name, 'role' => $node->role];
        }

        return $targets;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{total: int, completed: int, failed: int}
     */
    private function summary(array $updates): array
    {
        return [
            'total' => count($updates),
            'completed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'completed')),
            'failed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'failed')),
        ];
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function respondSuccess(array $updates): int
    {
        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($this->wantsJson()) {
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
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode([
                'error' => $error,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);

        $output = $data['output'] ?? null;

        if (is_string($output) && trim($output) !== '') {
            $this->line(trim($output));
        }

        return self::FAILURE;
    }

    private function nodeCountLabel(int $count): string
    {
        return $count.' '.($count === 1 ? 'node' : 'nodes');
    }
}
