<?php

declare(strict_types=1);

namespace App\Commands\Activity;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Illuminate\Support\Str;

final class ActivityListCommand extends GatewayCommand
{
    private const array VALID_EFFECTS = ['read', 'write', 'destructive'];

    #[\Override]
    protected $signature = 'activity:list
        {--app= : Filter by app}
        {--node= : Filter by node}
        {--effect= : Filter by effect (read|write|destructive)}
        {--correlation= : Filter by correlation UUID}
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
            $response = $this->gatewayGet('/api/activity', array_filter($filters, fn (mixed $value): bool => $value !== null));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if (! $this->wantsJson() && $this->hasNoActivities($response)) {
            $this->line('No activity found.');

            return self::SUCCESS;
        }

        return $this->renderSuccess($response);
    }

    /**
     * @return array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}|null
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

        if ($effect === false || ($effect !== null && ! in_array($effect, self::VALID_EFFECTS, true))) {
            return $this->invalidFilter('effect', 'unsupported_value');
        }

        $correlation = $this->stringFilter('correlation');

        if ($correlation === false || ($correlation !== null && ! Str::isUuid($correlation))) {
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

    /**
     * @param  array<string, mixed>  $response
     */
    private function hasNoActivities(array $response): bool
    {
        $activities = $response['success']['data']['activities'] ?? null;

        return is_array($activities) && $activities === [];
    }
}
