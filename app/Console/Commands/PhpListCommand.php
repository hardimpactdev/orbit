<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Php\PhpRuntimeFailure;
use App\Data\Php\PhpRuntimeOperation;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Php\ShowPhpRuntimeRequest;
use App\Http\Gateway\Responses\Php\PhpRuntimeResponse;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('php:list
    {--app= : App selector}
    {--workspace= : Workspace selector}
    {--node= : Node selector}
    {--live : Inspect available PHP images live}
    {--json : Output as JSON}')]
#[Description('List PHP runtime support, available image facts, and selected runtime intent')]
class PhpListCommand extends Command
{
    public function handle(PhpRuntimeManager $php): int
    {
        $onGateway = (bool) config('orbit.is_gateway', false);

        $result = $onGateway
            ? $php->view(
                app: $this->stringOption('app'),
                workspace: $this->stringOption('workspace'),
                node: $this->stringOption('node'),
                live: (bool) $this->option('live'),
            )
            : $this->fetchGatewayRuntime();

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(new PhpRuntimeFailure(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== '' ? $result->getMessage() : 'Gateway connection is required to list PHP runtime state.',
                meta: $result->errorMeta(),
            ));
        }

        if ($result->failed()) {
            return $this->failCommand($result->failure);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['php' => $result->payload], $result->meta);
        }

        $this->renderHuman($result->payload);

        return self::SUCCESS;
    }

    private function fetchGatewayRuntime(): PhpRuntimeOperation|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ShowPhpRuntimeRequest(
                    app: $this->stringOption('app'),
                    workspace: $this->stringOption('workspace'),
                    node: $this->stringOption('node'),
                    live: (bool) $this->option('live'),
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException('Gateway connection is required to list PHP runtime state.', 'gateway_unavailable');
        }

        /** @var PhpRuntimeResponse $dto */
        return new PhpRuntimeOperation(
            payload: $dto->php,
            meta: $dto->meta,
        );
    }

    /**
     * @param  array<string, mixed>  $php
     */
    private function renderHuman(array $php): void
    {
        table(['Field', 'Value'], [
            ['Node', $php['node'] ?? '(unknown)'],
            ['Supported', implode(', ', $php['supported'] ?? [])],
            ['Available images', implode(', ', $php['available_images'] ?? [])],
            ['CLI', $php['cli'] ?? '(unset)'],
        ]);

        if (is_array($php['app'] ?? null)) {
            $this->line("App {$php['app']['name']}: PHP {$php['app']['php_version']}");
        }

        if (is_array($php['workspace'] ?? null)) {
            $inheritance = ($php['workspace']['inherits'] ?? false) ? 'inherits' : 'override';
            $this->line("Workspace {$php['workspace']['name']}: PHP {$php['workspace']['php_version']} ({$inheritance})");
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
                'meta' => $meta === [] ? (object) [] : $meta,
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
