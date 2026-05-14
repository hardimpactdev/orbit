<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\RestartProcesses;
use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\RestartProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessRestartResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;

#[Signature('process:restart
    {name? : Existing process name}
    {--app= : Parent app slug}
    {--workspace= : Workspace name}
    {--json : Output JSON}')]
#[Description('Restart app process runtime units')]
class ProcessRestartCommand extends Command
{
    use HandlesPromptCancellation;
    use WithSpinner;
    use WithStepTree;

    public function handle(RestartProcesses $restartProcesses, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardRestart();
            }

            $context = $this->resolveContext();

            if (is_int($context)) {
                return $context;
            }

            [$app, $workspace] = $context;

            if (! $this->wantsJson()) {
                $result = null;
                $exitCode = $this->runProcessRuntimeTree(
                    title: 'Restarting Processes',
                    actionLabel: 'Restart runtime units',
                    actionDoneLabel: 'Restarted runtime units',
                    doneFooter: 'Processes restarted',
                    failFooter: 'Process restart failed',
                    operation: function () use ($restartProcesses, $app, $workspace, &$result): string {
                        $result = $restartProcesses->handle($app, $workspace, $this->stringArgument('name'));

                        return $result['failed'] ? 'fail:'.$result['message'] : 'runtime units restarted';
                    },
                );

                if ($exitCode !== self::SUCCESS) {
                    return is_array($result)
                        ? $this->failCommand(
                            code: 'process.runtime_action_failed',
                            message: $result['message'],
                            meta: $result['meta'],
                            data: $result['data'],
                        )
                        : self::FAILURE;
                }
            } else {
                $result = $restartProcesses->handle($app, $workspace, $this->stringArgument('name'));
            }
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to restart processes.',
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to restart processes.',
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

    private function forwardRestart(): int
    {
        /** @var ProcessRestartResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RestartProcessesRequest(
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
        $isInteractive = ! $this->wantsJson() && $this->input->isInteractive();
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
            if (! $isInteractive) {
                return $this->failValidation('app', 'An app context is required.');
            }

            try {
                $appNames = App::query()->orderBy('name')->pluck('name')->all();
                $appName = (string) ($appNames !== []
                    ? select(label: 'App', options: $appNames, required: true)
                    : $this->promptText(label: 'App', required: true));
            } catch (PromptAborted) {
                return $this->promptAborted();
            }
        }

        $app = App::query()->with('node')->where('name', $appName)->first();

        if (! $app instanceof App) {
            return $this->failValidation('app', "App '{$appName}' not found.");
        }

        return [$app, null];
    }

    private function promptAborted(): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
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
                ? "Process '{$process}' restarted for app '".(string) ($first['app'] ?? '')."'"
                : "Process '{$process}' restarted for workspace '{$workspace}'");

            return self::SUCCESS;
        }

        $this->line($workspace === null
            ? count($runtimes)." processes restarted for app '".(string) ($first['app'] ?? '')."'"
            : count($runtimes)." processes restarted for workspace '{$workspace}'");

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

    private function runProcessRuntimeTree(
        string $title,
        string $actionLabel,
        string $actionDoneLabel,
        string $doneFooter,
        string $failFooter,
        callable $operation,
    ): int {
        return $this->runStepTree($title, [
            [
                'label' => 'Resolve runtime units',
                'doneLabel' => 'Resolved runtime units',
                'run' => fn (): string => 'runtime units resolved',
            ],
            [
                'label' => $actionLabel,
                'doneLabel' => $actionDoneLabel,
                'run' => $operation,
            ],
            [
                'label' => 'Record process events',
                'doneLabel' => 'Recorded process events',
                'run' => fn (): string => 'events recorded',
            ],
        ], doneFooter: $doneFooter, failFooter: $failFooter);
    }
}
