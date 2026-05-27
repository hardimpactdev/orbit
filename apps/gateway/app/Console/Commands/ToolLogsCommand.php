<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Contracts\ToolLogGatewayStream;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\ToolLogsRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
use App\Http\Gateway\Responses\Tools\ToolLogsResponse;
use App\Models\LocalNodeDefault;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLogFollower;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:logs
    {tool? : Tool catalog name to read logs for}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--lines=100 : Number of historical lines}
    {--follow : Follow log output}
    {--json : Output JSON}')]
#[Description('Read managed tool logs')]
class ToolLogsCommand extends Command
{
    use HandlesPromptCancellation;

    public function handle(ToolLogReader $logs, ToolLogFollower $follower, ToolLogGatewayStream $gatewayStream, ToolCatalog $catalog): int
    {
        $input = $this->validatedInput($catalog);

        if (is_int($input)) {
            return $input;
        }

        $target = $this->resolveTargetOptions($input['node'], $input['app']);

        if ($target instanceof GatewayApiException) {
            return $this->failCommand(
                code: $target->errorCode() ?? 'gateway_unavailable',
                message: $target->getMessage() !== ''
                    ? $target->getMessage()
                    : 'Gateway connection is required to resolve the caller identity.',
                meta: $target->errorMeta(),
            );
        }

        if ($target instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $target->code,
                message: $target->message,
                meta: $this->commandFailureMeta($target),
            );
        }

        [$node, $app] = $target;

        $isGatewayCaller = $this->isGatewayCaller();

        if ($input['follow']) {
            $result = $isGatewayCaller
                ? $follower->follow(
                    tool: $input['tool'],
                    node: $node,
                    app: $app,
                    lines: $input['lines'],
                    onOutput: function (string $output): void {
                        $this->writeStreamOutput($output);
                    },
                )
                : $gatewayStream->follow(
                    tool: $input['tool'],
                    node: $node,
                    app: $app,
                    lines: $input['lines'],
                    onOutput: function (string $output): void {
                        $this->writeStreamOutput($output);
                    },
                );

            if ($result instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $result->errorCode() ?? 'gateway_unavailable',
                    message: $result->getMessage() !== ''
                        ? $result->getMessage()
                        : 'Gateway connection is required to read tool logs.',
                    meta: $result->errorMeta(),
                );
            }

            if ($result instanceof ToolRegistryFailure) {
                return $this->failCommand(
                    code: $result->code,
                    message: $result->message,
                    meta: $result->meta,
                );
            }

            return self::SUCCESS;
        }

        $result = $isGatewayCaller
            ? $logs->read($input['tool'], node: $node, app: $app, lines: $input['lines'])
            : $this->logsViaGateway($input['tool'], node: $node, app: $app, lines: $input['lines']);

        if ($result instanceof GatewayApiException) {
            return $this->failCommand(
                code: $result->errorCode() ?? 'gateway_unavailable',
                message: $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to read tool logs.',
                meta: $result->errorMeta(),
            );
        }

        if ($result instanceof ToolRegistryFailure) {
            return $this->failCommand(
                code: $result->code,
                message: $result->message,
                meta: $result->meta,
            );
        }

        return $this->successPayload($result);
    }

    /**
     * @return array{tool: string, app: string|null, node: string|null, lines: int, follow: bool}|int
     */
    private function validatedInput(ToolCatalog $catalog): array|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failValidation('tool', 'A tool is required.');
            }

            try {
                $names = $catalog->names();
                $tool = (string) $this->promptSearch(
                    label: 'Tool name',
                    options: fn (string $value): array => array_values(array_filter($names, fn (string $n): bool => $value === '' || str_contains($n, $value))),
                );
            } catch (PromptAborted) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: [],
                );
            }
        }

        if ($this->wantsJson() && $this->option('follow') === true) {
            return $this->failValidation(
                'json',
                '--json cannot be combined with --follow.',
            );
        }

        $lines = $this->lines();

        if ($lines < 1) {
            return $this->failValidation('lines', 'The --lines value must be a positive integer.');
        }

        return [
            'tool' => $tool,
            'app' => $this->stringOption('app'),
            'node' => $this->stringOption('node'),
            'lines' => $lines,
            'follow' => $this->option('follow') === true,
        ];
    }

    /**
     * @return array<string, mixed>|GatewayApiException
     */
    private function logsViaGateway(string $tool, ?string $node, ?string $app, int $lines): array|GatewayApiException
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ToolLogsRequest(tool: $tool, app: $app, node: $node, lines: $lines))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        } catch (Throwable) {
            return new GatewayApiException(
                message: 'Gateway connection is required to read tool logs.',
                errorCode: 'gateway_unavailable',
                errorMeta: [],
            );
        }

        /** @var ToolLogsResponse $dto */
        return $dto->logs;
    }

    /**
     * @return array{0: ?string, 1: ?string}|ToolRegistryFailure|GatewayApiException
     */
    private function resolveTargetOptions(?string $node, ?string $app): array|ToolRegistryFailure|GatewayApiException
    {
        if ($app !== null) {
            return [$node, $app];
        }

        if ($node !== null) {
            return [$node, null];
        }

        $defaultNode = LocalNodeDefault::query()->value('default_node_name');

        if (is_string($defaultNode) && trim($defaultNode) !== '') {
            return [trim($defaultNode), null];
        }

        if (! $this->isGatewayCaller()) {
            try {
                return [$this->gatewayKnownSelfNodeName(), null];
            } catch (GatewayApiException $e) {
                return $e;
            }
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or app target is required. Provide --node, --app, or configure node:default.',
        );
    }

    /**
     * @throws GatewayApiException
     */
    private function gatewayKnownSelfNodeName(): string
    {
        $request = new ShowGatewayIdentityRequest;

        try {
            $response = app(GatewayConnector::class)->send($request);
        } catch (GatewayApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayApiException(
                'Gateway connection is required to resolve the caller identity.',
                'gateway_unavailable',
                [],
                $e,
            );
        }

        if ($response->clientError() || $response->serverError()) {
            $exception = $request->getRequestException($response, null);

            if ($exception instanceof GatewayApiException) {
                throw $exception;
            }

            throw new GatewayApiException(
                "Gateway request failed with HTTP status {$response->status()}",
                'gateway_unavailable',
                ['endpoint' => '/api/me'],
            );
        }

        /** @var GatewayIdentityResponse $identity */
        $identity = $response->dto();
        $name = is_string($identity->self['name'] ?? null) ? trim($identity->self['name']) : '';

        if ($name === '') {
            throw new GatewayApiException(
                'Gateway identity response is missing node identity.',
                'gateway_unavailable',
                ['endpoint' => '/api/me'],
            );
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $logs
     */
    private function successPayload(array $logs): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'logs' => $logs,
                    ],
                    'meta' => (object) [],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $lines = is_array($logs['lines'] ?? null) ? $logs['lines'] : [];

        if ($lines === []) {
            $this->line('No log lines found.');

            return self::SUCCESS;
        }

        foreach ($lines as $line) {
            if (is_array($line)) {
                $this->line((string) ($line['message'] ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function isGatewayCaller(): bool
    {
        return (bool) config('orbit.is_gateway', false);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function writeStreamOutput(string $output): void
    {
        $this->output->write($output);

        if (defined('STDOUT')) {
            fflush(STDOUT);
        }
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
     * @return array<string, mixed>
     */
    private function commandFailureMeta(ToolRegistryFailure $failure): array
    {
        if (($failure->meta['field'] ?? null) === 'target') {
            return ['fields' => ['target']];
        }

        return $failure->meta;
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

    private function lines(): int
    {
        $value = $this->option('lines');

        return is_numeric($value) ? (int) $value : 0;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
