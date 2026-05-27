<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Data\Php\PhpRuntimeFailure;
use App\Data\Php\PhpRuntimeOperation;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Php\UsePhpRuntimeRequest;
use App\Http\Gateway\Responses\Php\PhpRuntimeUseResponse;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('php:use
    {version? : PHP version to select}
    {--app= : App selector}
    {--workspace= : Workspace selector}
    {--node= : Node selector}
    {--inherit : Restore workspace PHP inheritance}
    {--cli : Select node CLI PHP default}
    {--json : Output as JSON}')]
#[Description('Select PHP runtime intent for an app, workspace, or node CLI default')]
class PhpUseCommand extends Command
{
    use HandlesPromptCancellation;
    use WithSpinner;
    use WithStepTree;

    private ?string $resolvedVersion = null;

    public function handle(PhpRuntimeManager $php): int
    {
        $this->resolvedVersion = null;

        $onGateway = (bool) config('orbit.is_gateway', false);

        if ($this->option('inherit') !== true) {
            $versionResult = $this->resolveVersionInput();

            if (is_int($versionResult)) {
                return $versionResult;
            }

            $this->resolvedVersion = $versionResult;
        }

        if (! $this->wantsJson()) {
            return $this->handleHuman($php, $onGateway);
        }

        $result = $this->runPhpUseOperation($php, $onGateway);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(new PhpRuntimeFailure(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== '' ? $result->getMessage() : 'Gateway connection is required to change PHP runtime selection.',
                meta: $result->errorMeta(),
            ));
        }

        if ($result->failed()) {
            return $this->failCommand($result->failure);
        }

        return $this->jsonSuccess($result->payload, $result->meta);
    }

    private function resolveVersionInput(): string|int
    {
        $version = $this->stringArgument('version');

        if ($version !== null) {
            return $version;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand(new PhpRuntimeFailure(
                code: 'validation_failed',
                message: 'A PHP version is required.',
                meta: ['field' => 'version', 'reason' => 'missing'],
            ));
        }

        try {
            return (string) $this->promptSelect(
                label: 'PHP version',
                options: PhpRuntimeCatalog::SUPPORTED,
                default: PhpRuntimeCatalog::DEFAULT,
            );
        } catch (PromptAborted) {
            return $this->failCommand(new PhpRuntimeFailure(
                code: 'validation_failed',
                message: 'Operation cancelled.',
                meta: [],
            ));
        }
    }

    private function handleHuman(PhpRuntimeManager $php, bool $onGateway): int
    {
        $result = null;
        $failure = null;

        $operation = function () use ($php, $onGateway, &$result, &$failure): string {
            $result = $this->runPhpUseOperation($php, $onGateway);

            if ($result instanceof GatewayApiException) {
                $failure = new PhpRuntimeFailure(
                    code: $result->errorCode() ?? 'gateway_unavailable',
                    message: $result->getMessage() !== '' ? $result->getMessage() : 'Gateway connection is required to change PHP runtime selection.',
                    meta: $result->errorMeta(),
                );

                return "fail:{$failure->message}";
            }

            if ($result->failed()) {
                $failure = $result->failure;

                return "fail:{$failure?->message}";
            }

            return 'change verified';
        };

        $exitCode = $this->runStepTree($this->progressTitle(), [
            [
                'label' => 'Resolve target',
                'doneLabel' => 'Resolved target',
                'run' => fn (): string => 'target resolved',
            ],
            [
                'label' => 'Validate version',
                'doneLabel' => 'Validated version',
                'run' => fn (): string => $this->option('inherit') === true ? 'inheritance selected' : 'version selected',
            ],
            [
                'label' => $this->applyStepLabel(),
                'doneLabel' => $this->applyStepDoneLabel(),
                'run' => $operation,
            ],
        ], doneFooter: $this->progressDoneFooter(), failFooter: 'PHP runtime update failed');

        if ($exitCode !== self::SUCCESS) {
            return $this->failCommand($failure);
        }

        if (! $result instanceof PhpRuntimeOperation) {
            return self::FAILURE;
        }

        $this->renderHuman($result->payload['result'] ?? []);

        return self::SUCCESS;
    }

    private function runPhpUseOperation(PhpRuntimeManager $php, bool $onGateway): PhpRuntimeOperation|GatewayApiException
    {
        if (! $onGateway) {
            return $this->forwardToGateway();
        }

        return $php->use(
            version: $this->effectiveVersion(),
            app: $this->stringOption('app'),
            workspace: $this->stringOption('workspace'),
            node: $this->stringOption('node'),
            inherit: (bool) $this->option('inherit'),
            cli: (bool) $this->option('cli'),
        );
    }

    private function forwardToGateway(): PhpRuntimeOperation|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new UsePhpRuntimeRequest(
                    version: $this->effectiveVersion(),
                    app: $this->stringOption('app'),
                    workspace: $this->stringOption('workspace'),
                    node: $this->stringOption('node'),
                    inherit: (bool) $this->option('inherit'),
                    cli: (bool) $this->option('cli'),
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException('Gateway connection is required to change PHP runtime selection.', 'gateway_unavailable');
        }

        /** @var PhpRuntimeUseResponse $dto */
        return new PhpRuntimeOperation(
            payload: [
                'php' => $dto->php,
                'result' => $dto->result,
            ],
            meta: $dto->meta,
        );
    }

    private function effectiveVersion(): ?string
    {
        return $this->resolvedVersion ?? $this->stringArgument('version');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderHuman(array $result): void
    {
        $target = $result['target'] ?? 'php runtime';
        $version = $result['version'] ?? '(unknown)';

        if (($result['inherits'] ?? false) === true) {
            $this->line("Successfully restored workspace PHP inheritance to PHP {$version}.");

            return;
        }

        $this->line("Successfully updated {$target} to PHP {$version}.");
    }

    private function progressTitle(): string
    {
        if ($this->option('inherit') === true) {
            return 'Restoring workspace PHP inheritance';
        }

        $version = $this->effectiveVersion() ?? '(unknown)';

        if ($this->option('cli') === true) {
            return "Updating CLI PHP runtime to PHP {$version}";
        }

        return "Updating PHP runtime to PHP {$version}";
    }

    private function applyStepLabel(): string
    {
        return $this->option('cli') === true
            ? 'Apply and verify CLI change'
            : 'Apply and verify PHP change';
    }

    private function applyStepDoneLabel(): string
    {
        return $this->option('cli') === true
            ? 'Applied and verified CLI change'
            : 'Applied and verified PHP change';
    }

    private function progressDoneFooter(): string
    {
        if ($this->option('inherit') === true) {
            return 'Successfully restored workspace PHP inheritance';
        }

        $version = $this->effectiveVersion() ?? '(unknown)';

        if ($this->option('cli') === true) {
            return "Successfully updated CLI PHP runtime to PHP {$version}";
        }

        return "Successfully updated PHP runtime to PHP {$version}";
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
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

    private function failCommand(?PhpRuntimeFailure $failure): int
    {
        $failure ??= new PhpRuntimeFailure('validation_failed', 'Required input is missing or invalid.');

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($failure->message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
