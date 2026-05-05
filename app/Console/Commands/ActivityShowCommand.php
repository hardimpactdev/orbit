<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Activity\ShowActivityRequest;
use App\Http\Gateway\Responses\Activity\ActivityShowResponse;
use App\Models\Node;
use App\Services\Activity\ActivityHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('activity:show
    {id? : Activity id}
    {--json : Output as JSON}')]
#[Description('Show one gateway activity history entry')]
class ActivityShowCommand extends Command
{
    public function handle(ActivityHistory $history): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'field' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                ],
            );
        }

        $id = $this->validatedId();

        if ($id === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->fetchActivity($callerRole, $id, $history);
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to show activity.',
                meta: [],
            );
        }

        if ($result instanceof GatewayApiException) {
            return $this->failGatewayException($result);
        }

        if ($result === null) {
            return $this->failCommand(
                code: 'activity_not_found',
                message: "Activity {$id} was not found or is not visible.",
                meta: ['id' => $id],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(
                data: [
                    'activity' => $result['activity'],
                    'related' => $result['related'],
                ],
                meta: $result['meta'],
            );
        }

        $this->renderHuman($result['activity'], $result['related']);

        return self::SUCCESS;
    }

    private function validatedId(): ?int
    {
        $value = $this->argument('id');

        if ($value === null || $value === '') {
            $this->failCommand(
                code: 'validation_failed',
                message: 'Activity id is required.',
                meta: [
                    'field' => 'id',
                    'reason' => 'missing',
                ],
            );

            return null;
        }

        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->failCommand(
                code: 'validation_failed',
                message: 'Activity id must be a positive integer.',
                meta: [
                    'field' => 'id',
                    'reason' => 'invalid',
                ],
            );

            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{activity: array<string, mixed>, related: list<array<string, mixed>>, meta: array<string, mixed>}|GatewayApiException|null
     */
    private function fetchActivity(string $callerRole, int $id, ActivityHistory $history): array|GatewayApiException|null
    {
        if ($callerRole === 'gateway') {
            return $history->show($id);
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ShowActivityRequest($id))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        }

        /** @var ActivityShowResponse $dto */
        return [
            'activity' => $dto->activity,
            'related' => $dto->related,
            'meta' => $dto->meta,
        ];
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

    /**
     * @param  array<string, mixed>  $activity
     * @param  list<array<string, mixed>>  $related
     */
    private function renderHuman(array $activity, array $related): void
    {
        $this->line("Activity {$activity['id']}");
        $this->line('Time: '.$this->humanTime($activity['occurred_at'] ?? null));
        $this->line('Type: '.($activity['type'] ?? ''));
        $this->line('Effect: '.($activity['effect'] ?? ''));
        $this->line('Subject: '.$this->humanSubject($activity['subject'] ?? null));
        $this->line('Actor: '.$this->humanActor($activity['actor'] ?? null));
        $this->line('Command: '.($activity['command'] ?? ''));
        $this->line('Correlation: '.($activity['correlation_id'] ?? ''));
        $this->line('Summary: '.($activity['summary'] ?? ''));
        $this->line('');
        $this->line('Details:');

        $details = $activity['details'] ?? [];

        if (is_array($details) && $details !== []) {
            foreach ($details as $key => $value) {
                $this->line("  {$key}: ".$this->stringValue($value));
            }
        } else {
            $this->line('  none');
        }

        $this->line('');

        if ($related === []) {
            $this->line('Related: none');

            return;
        }

        $this->line('Related:');

        foreach ($related as $entry) {
            $this->line(sprintf(
                '  %s  %s  %s  %s',
                $this->stringValue($entry['id'] ?? ''),
                $this->humanTime($entry['occurred_at'] ?? null),
                $this->stringValue($entry['type'] ?? ''),
                $this->stringValue($entry['effect'] ?? ''),
            ));
        }
    }

    private function humanTime(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function humanSubject(mixed $subject): string
    {
        if (! is_array($subject)) {
            return '';
        }

        return trim($this->stringValue($subject['type'] ?? '').' '.$this->stringValue($subject['name'] ?? ''));
    }

    private function humanActor(mixed $actor): string
    {
        if (! is_array($actor)) {
            return '';
        }

        $node = $this->stringValue($actor['node'] ?? '');
        $role = $this->stringValue($actor['role'] ?? '');

        return $role === '' ? $node : "{$node} ({$role})";
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
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

    private function failGatewayException(GatewayApiException $exception): int
    {
        return $this->failCommand(
            code: $exception->errorCode() ?? 'gateway_unavailable',
            message: $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Gateway connection is required to show activity.',
            meta: $exception->errorMeta(),
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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
