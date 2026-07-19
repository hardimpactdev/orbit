<?php

declare(strict_types=1);

namespace App\Commands\Activity;

use App\Commands\Concerns\RendersShowDetails;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Illuminate\Support\Carbon;
use Throwable;

final class ActivityShowCommand extends GatewayCommand
{
    use RendersShowDetails;

    #[\Override]
    protected $signature = 'activity:show {id? : Activity ID} {--json}';

    #[\Override]
    protected $description = 'Show one gateway activity history entry.';

    public function handle(): int
    {
        $id = $this->validatedId();

        if ($id === null) {
            return self::FAILURE;
        }

        try {
            $response = $this->gatewayGet("/api/activity/{$id}");
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $activity = ActivityGatewayResponse::activityFrom($response);

        if ($activity === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required activity data.');
        }

        $this->renderActivity($activity, ActivityGatewayResponse::relatedFrom($response));

        return self::SUCCESS;
    }

    private function validatedId(): ?int
    {
        $value = $this->argument('id');

        if ($value === null || $value === '') {
            $this->renderFailure('validation_failed', 'Activity id is required.', [
                'field' => 'id',
                'reason' => 'missing',
            ]);

            return null;
        }

        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->renderFailure('validation_failed', 'Activity id must be a positive integer.', [
                'field' => 'id',
                'reason' => 'invalid',
            ]);

            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  list<array<string, mixed>>  $related
     */
    private function renderActivity(array $activity, array $related): void
    {
        $this->renderShowDetails('Activity: '.(string) ($activity['id'] ?? ''), [
            'Time' => $this->humanTime($activity['occurred_at'] ?? null),
            'Type' => $activity['type'] ?? null,
            'Effect' => $activity['effect'] ?? null,
            'Subject' => $this->humanSubject($activity['subject'] ?? null),
            'Actor' => $this->humanActor($activity['actor'] ?? null),
            'Command' => $activity['command'] ?? null,
            'Correlation' => $activity['correlation_id'] ?? null,
            'Description' => $activity['description'] ?? null,
            'Properties' => $this->propertiesLabel($activity['properties'] ?? []),
            'Channel' => $activity['channel'] ?? null,
            'Related' => $this->relatedLabel($related),
        ]);
    }

    private function humanTime(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $value;
        }
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

        return $this->stringValue($actor['node'] ?? '');
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

    private function propertiesLabel(mixed $properties): string
    {
        if (! is_array($properties) || $properties === []) {
            return '—';
        }

        $labels = [];

        foreach ($properties as $key => $value) {
            $labels[] = "{$key}: ".$this->stringValue($value);
        }

        return implode(', ', $labels);
    }

    /**
     * @param  list<array<string, mixed>>  $related
     */
    private function relatedLabel(array $related): string
    {
        if ($related === []) {
            return '—';
        }

        return implode(', ', array_map(
            fn (array $entry): string => sprintf(
                '%s %s %s %s',
                $this->stringValue($entry['id'] ?? ''),
                $this->humanTime($entry['occurred_at'] ?? null),
                $this->stringValue($entry['type'] ?? ''),
                $this->stringValue($entry['effect'] ?? ''),
            ),
            $related,
        ));
    }
}
