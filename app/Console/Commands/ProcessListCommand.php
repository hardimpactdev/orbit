<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\ListProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessListResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Processes\ProcessListPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('process:list
    {--app= : Parent app slug}
    {--workspace= : Workspace name}
    {--json : Output JSON}')]
#[Description('List configured app processes')]
class ProcessListCommand extends Command
{
    public function handle(ProcessListPayload $payload, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        try {
            $data = $this->fetchProcesses($payload, $callerRole);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to list processes.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list processes.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        $this->renderHuman($data);

        return self::SUCCESS;
    }

    /**
     * @return array{context: array<string, mixed>, processes: list<array<string, mixed>>}
     */
    private function fetchProcesses(ProcessListPayload $payload, string $callerRole): array
    {
        $app = $this->stringOption('app');
        $workspace = $this->stringOption('workspace');

        if ($callerRole === 'gateway') {
            return $payload->forContext($app, $workspace);
        }

        /** @var ProcessListResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListProcessesRequest(app: $app, workspace: $workspace))
            ->dto();

        return [
            'context' => $dto->context,
            'processes' => $dto->processes,
        ];
    }

    /**
     * @param  array{context: array<string, mixed>, processes: list<array<string, mixed>>}  $data
     */
    private function renderHuman(array $data): void
    {
        $processes = $data['processes'];

        if ($processes === []) {
            $this->line('No processes found.');

            return;
        }

        $context = $data['context'];
        $app = is_string($context['app'] ?? null) ? $context['app'] : '(unknown)';
        $workspace = is_string($context['workspace'] ?? null) ? $context['workspace'] : null;
        $this->line($workspace === null ? "Processes for {$app}" : "Processes for {$app} / {$workspace}");
        $this->newLine();
        table(['Name', 'Command', 'Restart Policy', 'Crash Notification', 'Last Event'], array_map(
            fn (array $process): array => [
                $process['name'] ?? '',
                $process['command'] ?? '',
                $process['restart_policy'] ?? '',
                $process['crash_notification'] ?? '',
                $this->eventLabel($process['last_event'] ?? null),
            ],
            $processes,
        ));
    }

    private function eventLabel(mixed $event): string
    {
        if (! is_array($event)) {
            return 'none';
        }

        $type = $event['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : 'none';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
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
