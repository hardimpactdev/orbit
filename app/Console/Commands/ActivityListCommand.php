<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Activity\ListActivityRequest;
use App\Http\Gateway\Responses\Activity\ActivityListResponse;
use App\Models\Node;
use App\Services\Activity\ActivityHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('activity:list
    {--app= : Filter by app}
    {--node= : Filter by node}
    {--effect= : Filter by effect (read|write|destructive)}
    {--correlation= : Filter by correlation UUID}
    {--limit=25 : Max rows to return}
    {--json : Output as JSON}')]
#[Description('List gateway activity history')]
class ActivityListCommand extends Command
{
    private const array VALID_EFFECTS = ['read', 'write', 'destructive'];

    public function handle(ActivityHistory $history): int
    {
        $onGateway = (bool) config('orbit.is_gateway', false);

        $filters = $this->validatedFilters();

        if ($filters === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->fetchActivity($onGateway, $filters, $history);
        } catch (Throwable) {
            return $this->failGatewayError(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list activity.',
                meta: [],
            );
        }

        if ($result instanceof GatewayApiException) {
            return $this->failGatewayException($result);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(
                data: ['activities' => $result['activities']],
                meta: $result['meta'],
            );
        }

        $this->renderHuman($result['activities']);

        return self::SUCCESS;
    }

    /**
     * @return array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}|null
     */
    private function validatedFilters(): ?array
    {
        $app = $this->option('app');
        if ($app !== null && (! is_string($app) || $app === '')) {
            $this->failValidation('app', 'invalid');

            return null;
        }

        $node = $this->option('node');
        if ($node !== null && (! is_string($node) || $node === '')) {
            $this->failValidation('node', 'invalid');

            return null;
        }

        $effect = $this->option('effect');
        if ($effect !== null && (! is_string($effect) || ! in_array($effect, self::VALID_EFFECTS, true))) {
            $this->failValidation('effect', 'unsupported_value');

            return null;
        }

        $correlation = $this->option('correlation');
        if ($correlation !== null && (! is_string($correlation) || ! Str::isUuid($correlation))) {
            $this->failValidation('correlation', 'invalid');

            return null;
        }

        $limit = $this->option('limit');
        if (! is_scalar($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false) {
            $this->failValidation('limit', 'invalid');

            return null;
        }

        $normalizedLimit = (int) $limit;
        if ($normalizedLimit < 1 || $normalizedLimit > 200) {
            $this->failValidation('limit', 'out_of_range');

            return null;
        }

        return [
            'app' => is_string($app) ? $app : null,
            'node' => is_string($node) ? $node : null,
            'effect' => is_string($effect) ? $effect : null,
            'correlation' => is_string($correlation) ? $correlation : null,
            'limit' => $normalizedLimit,
        ];
    }

    /**
     * @param  array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}  $filters
     * @return array{activities: list<array<string, mixed>>, meta: array<string, mixed>}|GatewayApiException
     */
    private function fetchActivity(bool $onGateway, array $filters, ActivityHistory $history): array|GatewayApiException
    {
        if ($onGateway) {
            return $history->list($filters);
        }

        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListActivityRequest(
                    app: $filters['app'],
                    node: $filters['node'],
                    effect: $filters['effect'],
                    correlation: $filters['correlation'],
                    limit: $filters['limit'],
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $e;
        }

        /** @var ActivityListResponse $dto */
        return [
            'activities' => $dto->activities,
            'meta' => $dto->meta,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $activities
     */
    private function renderHuman(array $activities): void
    {
        if ($activities === []) {
            $this->line('No activity found.');

            return;
        }

        table(
            headers: ['TIME', 'ID', 'EFFECT', 'TYPE', 'SUBJECT', 'ACTOR', 'COMMAND'],
            rows: array_map(fn (array $activity): array => [
                $this->humanTime($activity['occurred_at'] ?? null),
                $activity['id'] ?? '',
                $activity['effect'] ?? '',
                $activity['type'] ?? '',
                $this->nameFromPayload($activity['subject'] ?? null),
                $this->nameFromPayload($activity['actor'] ?? null, 'node'),
                $activity['command'] ?? '',
            ], $activities),
        );
    }

    private function humanTime(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function nameFromPayload(mixed $payload, string $key = 'name'): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
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

    private function failValidation(string $field, string $reason): int
    {
        $message = match ($field) {
            'effect' => 'Invalid value for --effect. Use one of: read, write, destructive.',
            'limit' => 'Invalid value for --limit. Use a number from 1 through 200.',
            'correlation' => 'Invalid value for --correlation. Use a UUID.',
            default => 'Invalid activity filter.',
        };

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Invalid activity filter.',
                    'meta' => [
                        'field' => $field,
                        'reason' => $reason,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failGatewayException(GatewayApiException $exception): int
    {
        return $this->failGatewayError(
            code: $exception->errorCode() ?? 'gateway_unavailable',
            message: $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Gateway connection is required to list activity.',
            meta: $exception->errorMeta(),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failGatewayError(string $code, string $message, array $meta): int
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
