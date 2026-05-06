<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\AddSchedule;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\AddScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleAddResponse;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:add
    {name? : Schedule name}
    {--command= : Command to execute}
    {--script= : Managed script path to execute}
    {--interval= : Portable interval expression}
    {--app= : Target app scope}
    {--node= : Target node scope}
    {--timezone=UTC : IANA timezone}
    {--json : Output JSON}')]
#[Description('Add a recurring schedule')]
class ScheduleAddCommand extends Command
{
    public function handle(AddSchedule $addSchedule, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'The local Orbit caller role could not be resolved.', [
                'caller_role' => 'unknown',
            ]);
        }

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardAdd($input);
            }

            $target = $this->resolveTarget($input['app'], $input['node']);

            if (is_int($target)) {
                return $target;
            }

            $result = $addSchedule->handle(
                target: $target,
                name: $input['name'],
                interval: $input['interval'],
                timezone: $input['timezone'],
                executionType: $input['execution_type'],
                executionValue: $input['execution_value'],
            );
        } catch (GatewayApiException $e) {
            return $this->failCommand($e->errorCode() ?? 'gateway_unavailable', $e->getMessage(), $e->errorMeta(), $e->errorData());
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to add schedules.', []);
        }

        return $this->successPayload($result['data']);
    }

    /**
     * @param  array{name: string, app: string|null, node: string|null, interval: string, timezone: string, command: string|null, script: string|null, execution_type: string, execution_value: string}  $input
     */
    private function forwardAdd(array $input): int
    {
        /** @var ScheduleAddResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new AddScheduleRequest(
                name: $input['name'],
                app: $input['app'],
                node: $input['node'],
                interval: $input['interval'],
                timezone: $input['timezone'],
                command: $input['command'],
                script: $input['script'],
            ))
            ->dto();

        return $this->successPayload($dto->data);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null, interval: string, timezone: string, command: string|null, script: string|null, execution_type: string, execution_value: string}|int
     */
    private function validatedInput(): array|int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');
        $interval = $this->stringOption('interval');
        $timezone = $this->stringOption('timezone') ?? 'UTC';
        $command = $this->stringOption('command');
        $script = $this->stringOption('script');

        if ($name === null) {
            return $this->failValidation('name', 'The schedule name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->failValidation('name', 'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['value' => $name]);
        }

        if (($app === null) === ($node === null)) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']]);
        }

        if (($command === null) === ($script === null)) {
            return $this->failValidation('execution_source', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']]);
        }

        if ($interval === null) {
            return $this->failCommand('schedule.interval_invalid', 'The schedule interval is required.', ['field' => 'interval']);
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->failValidation('timezone', 'The schedule timezone must be a valid IANA timezone.', ['value' => $timezone]);
        }

        return [
            'name' => $name,
            'app' => $app,
            'node' => $node,
            'interval' => $interval,
            'timezone' => $timezone,
            'command' => $command,
            'script' => $script,
            'execution_type' => $command === null ? 'script' : 'command',
            'execution_value' => $command ?? (string) $script,
        ];
    }

    private function resolveTarget(?string $app, ?string $node): App|Node|int
    {
        if ($app !== null) {
            $target = App::query()->with('node.schedulerState')->where('name', $app)->first();

            return $target instanceof App
                ? $target
                : $this->failValidation('app', "App '{$app}' not found.", ['value' => $app]);
        }

        $target = Node::query()->with('schedulerState')->where('name', $node)->whereIn('role', ['gateway', 'app'])->first();

        return $target instanceof Node
            ? $target
            : $this->failValidation('node', "Node '{$node}' not found.", ['value' => $node]);
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->failCommand('validation_failed', $message, [
            'field' => $field,
            ...$extraMeta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successPayload(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode(['success' => ['data' => $data]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $this->line('┌ Adding Schedule');
        $this->line('○ Validate schedule');
        $this->line('○ Create schedule intent');
        $this->line('○ Confirm scheduler pickup');
        $this->line('└ Schedule added');
        $this->line("Schedule '".(string) ($schedule['name'] ?? '')."' added.");

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
