<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ToolLogGatewayStream;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ToolLogsRequest;
use App\Http\Gateway\Responses\Tools\ToolLogsResponse;
use App\Models\Node;
use App\Services\Tools\ToolLogFollower;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tool:logs
    {tool : Tool catalog name to read logs for}
    {--app= : Resolve target by app selector}
    {--node= : Resolve target by node}
    {--lines=100 : Number of historical lines}
    {--follow : Follow log output}
    {--json : Output JSON}')]
#[Description('Read managed tool logs')]
class ToolLogsCommand extends Command
{
    public function handle(ToolLogReader $logs, ToolLogFollower $follower, ToolLogGatewayStream $gatewayStream): int
    {
        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        $isGatewayCaller = $this->isGatewayCaller();

        if ($input['follow']) {
            $result = $isGatewayCaller
                ? $follower->follow(
                    tool: $input['tool'],
                    node: $input['node'],
                    app: $input['app'],
                    lines: $input['lines'],
                    onOutput: function (string $output): void {
                        $this->writeStreamOutput($output);
                    },
                )
                : $gatewayStream->follow(
                    tool: $input['tool'],
                    node: $input['node'],
                    app: $input['app'],
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
            ? $logs->read($input['tool'], node: $input['node'], app: $input['app'], lines: $input['lines'])
            : $this->logsViaGateway($input['tool'], node: $input['node'], app: $input['app'], lines: $input['lines']);

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
    private function validatedInput(): array|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            return $this->failValidation('tool', 'A tool is required.');
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
        return $this->callerRole() === 'gateway';
    }

    private function writeStreamOutput(string $output): void
    {
        $this->output->write($output);

        if (defined('STDOUT')) {
            fflush(STDOUT);
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
