<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\StopProcesses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\StopProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessStopResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('process:stop
    {name? : Existing process name}
    {--app= : Parent app slug}
    {--workspace= : Workspace name}
    {--json : Output JSON}')]
#[Description('Stop app process runtime units')]
class ProcessStopCommand extends Command
{
    public function handle(StopProcesses $stopProcesses, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'The local Orbit caller role could not be resolved.',
                meta: ['caller_role' => 'unknown'],
            );
        }

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardStop();
            }

            $context = $this->resolveContext();

            if (is_int($context)) {
                return $context;
            }

            [$app, $workspace] = $context;

            if (! $this->wantsJson()) {
                $this->renderProgressTree();
            }

            $result = $stopProcesses->handle($app, $workspace, $this->stringArgument('name'));
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to stop processes.',
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to stop processes.',
                meta: [],
            );
        }

        if ($result['failed']) {
            return $this->failCommand(
                code: 'process.runtime_action_failed',
                message: $result['message'],
                meta: $result['meta'],
                data: $result['data'],
            );
        }

        return $this->successPayload($result['data']);
    }

    private function forwardStop(): int
    {
        /** @var ProcessStopResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new StopProcessesRequest(
                app: $this->stringOption('app'),
                workspace: $this->stringOption('workspace'),
                name: $this->stringArgument('name'),
            ))
            ->dto();

        return $this->successPayload($dto->data);
    }

    /**
     * @return array{App, Workspace|null}|int
     */
    private function resolveContext(): array|int
    {
        $appName = $this->stringOption('app');
        $workspaceName = $this->stringOption('workspace');

        if ($workspaceName !== null) {
            $workspaces = Workspace::query()
                ->with('app.node')
                ->where('name', $workspaceName)
                ->when($appName !== null, fn ($query) => $query->whereHas('app', fn ($query) => $query->where('name', $appName)))
                ->get();

            if ($workspaces->isEmpty()) {
                return $this->failValidation('workspace', "Workspace '{$workspaceName}' not found.");
            }

            if ($workspaces->count() > 1) {
                return $this->failValidation('workspace', "Workspace name '{$workspaceName}' is ambiguous.");
            }

            $workspace = $workspaces->first();

            if (! $workspace->app instanceof App) {
                return $this->failValidation('workspace', "Workspace '{$workspaceName}' is not attached to an app.");
            }

            return [$workspace->app, $workspace];
        }

        if ($appName === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        $app = App::query()->with('node')->where('name', $appName)->first();

        if (! $app instanceof App) {
            return $this->failValidation('app', "App '{$appName}' not found.");
        }

        return [$app, null];
    }

    private function renderProgressTree(): void
    {
        $this->line('┌ Stopping Processes');
        $this->line('○ Resolve runtime units');
        $this->line('○ Stop runtime units');
        $this->line('○ Record process events');
        $this->line('└ Working...');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successPayload(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => (object) [],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $runtimes = is_array($data['runtimes'] ?? null) ? $data['runtimes'] : [];
        $first = is_array($runtimes[0] ?? null) ? $runtimes[0] : [];
        $workspace = is_string($first['workspace'] ?? null) ? $first['workspace'] : null;

        if (count($runtimes) === 1) {
            $process = (string) ($first['process'] ?? '');

            $this->line($workspace === null
                ? "Process '{$process}' stopped for app '".(string) ($first['app'] ?? '')."'"
                : "Process '{$process}' stopped for workspace '{$workspace}'");

            return self::SUCCESS;
        }

        $this->line($workspace === null
            ? count($runtimes)." processes stopped for app '".(string) ($first['app'] ?? '')."'"
            : count($runtimes)." processes stopped for workspace '{$workspace}'");

        return self::SUCCESS;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta, array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $error['meta'] = empty($meta) ? (object) [] : $meta;

            $this->line(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
