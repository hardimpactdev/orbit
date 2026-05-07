<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\ShowProcessLogs;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\ShowProcessLogsRequest;
use App\Http\Gateway\Responses\Processes\ProcessLogsResponse;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('process:logs
    {name? : Existing process name}
    {--app= : Parent app slug}
    {--workspace= : Workspace name}
    {--follow : Follow log output}
    {--lines=100 : Number of historical lines}
    {--json : Output JSON}')]
#[Description('Read app process runtime logs')]
class ProcessLogsCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(ShowProcessLogs $showProcessLogs, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'The local Orbit caller role could not be resolved.',
                meta: ['caller_role' => 'unknown'],
            );
        }

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardLogs($input);
            }

            $context = $this->resolveContext($input['app'], $input['workspace']);

            if (is_int($context)) {
                return $context;
            }

            [$app, $workspace] = $context;

            if (! $this->wantsJson()) {
                $result = null;
                $exitCode = $this->runStepTree('Streaming Process Logs', [
                    [
                        'label' => 'Resolve runtime unit',
                        'doneLabel' => 'Resolved runtime unit',
                        'run' => fn (): string => 'runtime unit resolved',
                    ],
                    [
                        'label' => 'Open log stream',
                        'doneLabel' => 'Opened log stream',
                        'run' => function () use ($showProcessLogs, $app, $workspace, $input, &$result): string {
                            $result = $showProcessLogs->handle($app, $workspace, $input['name'], $input['lines'], $input['follow']);

                            return 'log stream opened';
                        },
                    ],
                ], doneFooter: 'Log stream opened', failFooter: 'Process logs failed');

                if ($exitCode !== self::SUCCESS) {
                    return self::FAILURE;
                }
            } else {
                $result = $showProcessLogs->handle($app, $workspace, $input['name'], $input['lines'], $input['follow']);
            }
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to read process logs.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to read process logs.',
                meta: [],
            );
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    /**
     * @param  array{name: string, app: string|null, workspace: string|null, lines: int, follow: bool}  $input
     */
    private function forwardLogs(array $input): int
    {
        /** @var ProcessLogsResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ShowProcessLogsRequest(
                name: $input['name'],
                app: $input['app'],
                workspace: $input['workspace'],
                lines: $input['lines'],
            ))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    /**
     * @return array{name: string, app: string|null, workspace: string|null, lines: int, follow: bool}|int
     */
    private function validatedInput(): array|int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failValidation('name', 'The process name is required.');
        }

        $lines = $this->lines();

        if ($lines < 1) {
            return $this->failValidation('lines', 'The --lines value must be a positive integer.');
        }

        if ($this->wantsJson() && $this->option('follow') === true) {
            return $this->failValidation('json', '--json cannot be combined with --follow.');
        }

        return [
            'name' => $name,
            'app' => $this->stringOption('app'),
            'workspace' => $this->stringOption('workspace'),
            'lines' => $lines,
            'follow' => $this->option('follow') === true,
        ];
    }

    /**
     * @return array{App, Workspace|null}|int
     */
    private function resolveContext(?string $appName, ?string $workspaceName): array|int
    {
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

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function successPayload(array $data, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $lines = is_array($logs['lines'] ?? null) ? $logs['lines'] : [];

        if ($lines === []) {
            $this->line('No log lines found.');

            return self::SUCCESS;
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $timestamp = is_string($line['timestamp'] ?? null) ? $line['timestamp'].' ' : '';
            $this->line($timestamp.(string) ($line['message'] ?? ''));
        }

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

    private function lines(): int
    {
        $value = $this->option('lines');

        return is_numeric($value) ? (int) $value : 0;
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
