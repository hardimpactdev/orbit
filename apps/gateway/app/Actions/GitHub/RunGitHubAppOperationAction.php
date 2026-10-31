<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\GitHub\HttpGitHubAppManagement;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Only manifest conversion and App metadata sync execute here. No Node work. */
final readonly class RunGitHubAppOperationAction
{
    public function __construct(private GitHubAppStore $apps, private OperationStore $operations, private HttpGitHubAppManagement $github) {}

    public function handle(int $operationId): void
    {
        $operation = DB::transaction(function () use ($operationId): ?Operation {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
            if (! OperationKinds::gatewayJob($operation->kind)) {
                return null;
            }
            if ($operation->status->terminal()) {
                if ($operation->kind === 'github.app_register') {
                    $this->apps->finishExchange($operation->id);
                }

                return null;
            }
            if ($operation->status !== OperationStatus::Queued) {
                return null;
            }
            if ($operation->cancel_requested_at !== null) {
                $this->finish($operation, OperationStatus::Cancelled);

                return null;
            }
            $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'attempts' => $operation->attempts + 1]);

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        try {
            if ($operation->kind === 'github.app_register') {
                $code = DB::transaction(function () use ($operation): string {
                    $this->lockSettings();
                    $exchange = $this->apps->exchange();
                    if (($exchange['operation_id'] ?? null) !== $operation->id || ($exchange['registration_id'] ?? null) !== ($operation->input['registration_id'] ?? null)
                        || ! is_string($exchange['code'] ?? null) || $exchange['code'] === '' || $this->apps->credentials() !== null) {
                        throw new RuntimeException('github.registration_failed');
                    }
                    $this->apps->consumeExchangeCode($operation->id);

                    return $exchange['code'];
                });
                $credentials = $this->github->register($code);
                unset($code);
                DB::transaction(function () use ($operation, $credentials): void {
                    $operation->refresh();
                    $this->lockSettings();
                    if (($this->apps->exchange()['operation_id'] ?? null) !== $operation->id || $this->apps->credentials() !== null) {
                        throw new RuntimeException('github.registration_failed');
                    }
                    if ($operation->cancel_requested_at !== null) {
                        $this->finish($operation, OperationStatus::Cancelled);

                        return;
                    }
                    $this->apps->put($credentials);
                    $this->finish($operation, OperationStatus::Succeeded, ['registered' => true]);
                });
            } else {
                $credentials = $this->apps->credentials();
                if ($credentials === null || $credentials->appId !== ($operation->input['app_id'] ?? null)) {
                    throw new RuntimeException('github.app_sync_failed');
                }
                $result = $this->github->sync($credentials);
                DB::transaction(function () use ($operation, $result, $credentials): void {
                    $operation->refresh();
                    $this->lockSettings();
                    if ($this->apps->credentials()?->appId !== $credentials->appId) {
                        throw new RuntimeException('github.app_sync_failed');
                    }
                    if ($operation->cancel_requested_at !== null) {
                        $this->finish($operation, OperationStatus::Cancelled);

                        return;
                    }
                    $this->apps->observe($operation, $result);
                    $this->finish($operation, OperationStatus::Succeeded, $result);
                });
            }
        } catch (Throwable) {
            $this->fail($operationId);
        }
    }

    public function fail(int $operationId): void
    {
        DB::transaction(function () use ($operationId): void {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation !== null && OperationKinds::gatewayJob($operation->kind) && ! $operation->status->terminal()) {
                $this->finish($operation, $operation->cancel_requested_at !== null ? OperationStatus::Cancelled : OperationStatus::Failed);
            }
        });
    }

    /** @param array<string,mixed>|null $result */
    private function finish(Operation $operation, OperationStatus $status, ?array $result = null): void
    {
        $this->operations->change($operation, ['status' => $status, 'result' => $result, 'finished_at' => now(),
            'error_code' => $status === OperationStatus::Failed ? ($operation->kind === 'github.app_register' ? 'github.registration_failed' : 'github.app_sync_failed') : null]);
        if ($operation->kind === 'github.app_register') {
            $this->apps->finishExchange($operation->id);
        }
    }

    private function lockSettings(): void
    {
        Setting::query()->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'like', 'github.app%')->lockForUpdate()->get();
    }
}
