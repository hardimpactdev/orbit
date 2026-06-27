<?php

declare(strict_types=1);

namespace App\Commands\Activity;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Laravel\Prompts\table;

final class ActivityListCommand extends GatewayCommand
{
    private const array VALID_EFFECTS = ['read', 'write', 'destructive'];

    #[\Override]
    protected $signature = 'activity:list
        {--app= : Filter by app}
        {--node= : Filter by node}
        {--effect= : Filter by effect (read|write|destructive)}
        {--correlation= : Filter by correlation UUID}
        {--include-internal : Include internal backend transport activity}
        {--limit=25 : Max rows to return}
        {--json}';

    #[\Override]
    protected $description = 'List gateway activity history.';

    public function handle(): int
    {
        $filters = $this->validatedFilters();

        if ($filters === null) {
            return self::FAILURE;
        }

        try {
            $response = $this->gatewayGet('/api/activity', array_filter(
                $filters,
                fn (mixed $value): bool => $value !== null,
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        if (ActivityGatewayResponse::hasNoActivities($response)) {
            $this->line('No activity found.');

            return self::SUCCESS;
        }

        table(
            headers: ['TIME', 'ID', 'EFFECT', 'TYPE', 'SUBJECT', 'ACTOR', 'COMMAND'],
            rows: array_map(fn (array $activity): array => [
                $this->occurredAt($activity),
                $this->activityString($activity, 'id'),
                $this->activityString($activity, 'effect'),
                $this->activityString($activity, 'type'),
                $this->subjectLabel($activity),
                $this->actorLabel($activity),
                $this->activityString($activity, 'command'),
            ], ActivityGatewayResponse::activitiesFrom($response)),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function occurredAt(array $activity): string
    {
        $occurredAt = $activity['occurred_at'] ?? null;

        if (! is_string($occurredAt) || $occurredAt === '') {
            return '—';
        }

        try {
            return Carbon::parse($occurredAt)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $occurredAt;
        }
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function subjectLabel(array $activity): string
    {
        $subject = $activity['subject'] ?? null;

        if (! is_array($subject)) {
            return '—';
        }

        $name = $subject['name'] ?? null;

        return is_scalar($name) && (string) $name !== '' ? (string) $name : '—';
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function actorLabel(array $activity): string
    {
        $actor = $activity['actor'] ?? null;

        if (! is_array($actor)) {
            return '—';
        }

        $node = $actor['node'] ?? null;

        return is_scalar($node) && (string) $node !== '' ? (string) $node : '—';
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityString(array $activity, string $key): string
    {
        $value = $activity[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @return array{app: string|null, node: string|null, effect: string|null, correlation: string|null, include_internal: bool|null, limit: int}|null
     */
    private function validatedFilters(): ?array
    {
        $app = $this->stringFilter('app');

        if ($app === false) {
            return $this->invalidFilter('app', 'invalid');
        }

        $node = $this->stringFilter('node');

        if ($node === false) {
            return $this->invalidFilter('node', 'invalid');
        }

        $effect = $this->stringFilter('effect');

        if ($effect === false) {
            return $this->invalidFilter('effect', 'unsupported_value');
        }

        if (is_string($effect) && ! in_array($effect, self::VALID_EFFECTS, true)) {
            return $this->invalidFilter('effect', 'unsupported_value');
        }

        $correlation = $this->stringFilter('correlation');

        if ($correlation === false) {
            return $this->invalidFilter('correlation', 'invalid');
        }

        if (is_string($correlation) && ! Str::isUuid($correlation)) {
            return $this->invalidFilter('correlation', 'invalid');
        }

        $limit = $this->option('limit');

        if (! is_scalar($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false) {
            return $this->invalidFilter('limit', 'invalid');
        }

        $normalizedLimit = (int) $limit;

        if ($normalizedLimit < 1 || $normalizedLimit > 200) {
            return $this->invalidFilter('limit', 'out_of_range');
        }

        return [
            'app' => $app,
            'node' => $node,
            'effect' => $effect,
            'correlation' => $correlation,
            'include_internal' => $this->option('include-internal') === true ? true : null,
            'limit' => $normalizedLimit,
        ];
    }

    private function stringFilter(string $option): string|false|null
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        return $value;
    }

    private function invalidFilter(string $field, string $reason): null
    {
        $this->renderFailure('validation_failed', 'Invalid activity filter.', [
            'field' => $field,
            'reason' => $reason,
        ]);

        return null;
    }
}
