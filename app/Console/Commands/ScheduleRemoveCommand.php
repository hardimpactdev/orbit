<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\RemoveSchedule;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\RemoveScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRemoveResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:remove
    {name : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a recurring schedule')]
class ScheduleRemoveCommand extends Command
{
    public function handle(SchedulePayload $payload, RemoveSchedule $removeSchedule, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'The local Orbit caller role could not be resolved.', [
                'caller_role' => 'unknown',
            ]);
        }

        $consent = $this->confirmRemoval((string) $this->argument('name'));

        if (is_int($consent)) {
            return $consent;
        }

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardRemove();
            }

            if (! $this->wantsJson()) {
                $this->renderProgressTree();
            }

            $schedule = $payload->find((string) $this->argument('name'), $this->stringOption('app'), $this->stringOption('node'));
            $result = $removeSchedule->handle($schedule);
        } catch (GatewayApiException $e) {
            return $this->failCommand($e->errorCode() ?? 'gateway_unavailable', $e->getMessage(), $e->errorMeta(), $e->errorData());
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to remove schedules.', []);
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    private function forwardRemove(): int
    {
        /** @var ScheduleRemoveResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveScheduleRequest(
                name: (string) $this->argument('name'),
                app: $this->stringOption('app'),
                node: $this->stringOption('node'),
            ))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand('destructive_consent_required', 'Use --force to remove this schedule.', [
                'field' => 'force',
            ]);
        }

        if ($this->confirm("Remove schedule '{$name}'?", false)) {
            return null;
        }

        return $this->failCommand('destructive_consent_required', 'No schedule was removed.', [
            'field' => 'force',
        ]);
    }

    private function renderProgressTree(): void
    {
        $this->line('┌ Removing Schedule');
        $this->line('○ Resolve schedule');
        $this->line('○ Apply and verify removal');
        $this->line('○ Notify Orbit Scheduler');
        $this->line('└ Working...');
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

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $this->line("Schedule '".(string) ($schedule['name'] ?? '')."' removed.");
        $this->line('Scheduler pickup: '.(string) ($meta['scheduler_pickup'] ?? 'unknown'));

        return self::SUCCESS;
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
                'meta' => empty($meta) ? (object) [] : $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

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

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
