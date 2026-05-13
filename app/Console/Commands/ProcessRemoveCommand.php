<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\RemoveProcess;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\RemoveProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessRemoveResponse;
use App\Models\App;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('process:remove
    {name? : Existing process name}
    {--app= : Parent app slug}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove an app process definition')]
class ProcessRemoveCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(RemoveProcess $removeProcess, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        $consent = $this->confirmRemoval($input['name']);

        if (is_int($consent)) {
            return $consent;
        }

        $result = null;
        $failure = null;
        $operation = function () use ($callerRole, $input, $removeProcess, &$result, &$failure): string {
            try {
                if ($callerRole === 'control') {
                    $result = $this->forwardRemoveResult($input);

                    return 'gateway accepted';
                }

                $app = App::query()->with(['node', 'workspaces'])->where('name', $input['app'])->first();

                if (! $app instanceof App) {
                    $failure = [
                        'code' => 'validation_failed',
                        'message' => "App '{$input['app']}' not found.",
                        'meta' => ['field' => 'app', 'value' => $input['app']],
                    ];

                    return "fail:{$failure['message']}";
                }

                $result = $removeProcess->handle($app, $input['name']);

                return 'process removed';
            } catch (GatewayApiException $e) {
                $failure = [
                    'code' => $e->errorCode() ?? 'gateway_unavailable',
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to remove processes.',
                    'meta' => $e->errorMeta(),
                ];

                return "fail:{$failure['message']}";
            } catch (Throwable) {
                $failure = [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to remove processes.',
                    'meta' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Removing Process', [
                [
                    'label' => 'Validate process',
                    'doneLabel' => 'Validated process',
                    'run' => fn (): string => 'input resolved',
                ],
                [
                    'label' => 'Remove runtime units',
                    'doneLabel' => 'Removed runtime units',
                    'run' => fn (): string => 'runtime units removed',
                ],
                [
                    'label' => 'Apply and verify process removal',
                    'doneLabel' => 'Applied and verified process removal',
                    'run' => $operation,
                ],
            ], doneFooter: 'Process removed', failFooter: 'Process remove failed');

            if ($exitCode !== self::SUCCESS) {
                return is_array($failure)
                    ? $this->failCommand($failure['code'], $failure['message'], $failure['meta'])
                    : self::FAILURE;
            }
        } else {
            $operation();

            if (is_array($failure)) {
                return $this->failCommand($failure['code'], $failure['message'], $failure['meta']);
            }
        }

        return $this->successPayload($result['data'], $result['warnings']);
    }

    /**
     * @param  array{name: string, app: string}  $input
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    private function forwardRemoveResult(array $input): array
    {
        /** @var ProcessRemoveResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveProcessRequest(app: $input['app'], name: $input['name']))
            ->dto();

        return ['data' => $dto->data, 'warnings' => $dto->warnings];
    }

    /**
     * @return array{name: string, app: string}|int
     */
    private function validatedInput(): array|int
    {
        $app = $this->stringOption('app');
        $name = $this->stringArgument('name');

        if ($app === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        if ($name === null) {
            return $this->failValidation('name', 'The process name is required.');
        }

        return [
            'app' => $app,
            'name' => $name,
        ];
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('force', 'Use --force to remove this process.');
        }

        if (confirm(label: "Remove process '{$name}'?", default: false)) {
            return null;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: ['field' => 'force'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successPayload(array $data, array $warnings): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [
                        'warnings' => $warnings,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $process = is_array($data['process'] ?? null) ? $data['process'] : [];
        $this->line("Process '".(string) ($process['name'] ?? '')."' removed from app '".(string) ($process['app'] ?? '')."'");

        foreach ($warnings as $warning) {
            $this->line('  Drift detected: '.(string) ($warning['code'] ?? 'warning'));
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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
