<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Apps\AppShowPlacementRows;

use function Laravel\Prompts\table;

final class AppShowCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use RendersShowDetails;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'project:show {project? : Project name or hostname to inspect} {--json}';

    #[\Override]
    protected $description = 'Show one project from the gateway registry.';

    public function handle(): int
    {
        $selector = $this->resolveAppSelector();

        if (is_int($selector)) {
            return $selector;
        }

        $project = rawurlencode($selector);

        try {
            $response = $this->gatewayGet("/api/projects/{$project}");
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $project = $this->projectFromGatewayResponse($response);

        if ($project === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required project data.');
        }

        $this->renderProject($project, $this->detailsFromGatewayResponse($response));

        return self::SUCCESS;
    }

    private function resolveAppSelector(): string|int
    {
        $selector = $this->stringArgument('project');

        if ($selector !== null) {
            return $selector;
        }

        if ($this->canPromptForRegistrySelection()) {
            return $this->promptForVisibleProject();
        }

        return $this->renderFailure('validation_failed', 'The project argument is required.', ['field' => 'project']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function projectFromGatewayResponse(array $response): ?array
    {
        $project = $this->registrySuccessData($response)['project'] ?? null;

        return $this->associativeArray($project);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function detailsFromGatewayResponse(array $response): array
    {
        $details = $this->registrySuccessData($response)['details'] ?? [];

        return $this->associativeArray($details) ?? [];
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $details
     */
    private function renderProject(array $project, array $details): void
    {
        $name = is_scalar($project['name'] ?? null) ? (string) $project['name'] : 'unknown';
        $placements = app(AppShowPlacementRows::class);

        $this->renderShowDetails("Project: {$name}", [
            'Repository' => $project['repository'] ?? null,
            'PHP' => $project['php_version'] ?? null,
            'Processes' => $this->nameLabels($details['processes'] ?? []),
            'Routes' => $this->routeLabels($details['routes'] ?? []),
            'Project deps' => $placements->dependencyLabel($project),
        ]);

        $rows = $placements->forApp($project, $details);

        if ($rows === []) {
            $this->line('No instances found.');

            return;
        }

        table(headers: ['NAME', 'DRIVER', 'NODE', 'URL', 'PROJECT DEPS'], rows: $rows);
    }

    /**
     * @return list<string>
     */
    private function nameLabels(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $labels = [];

        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $labels[] = $item;

                continue;
            }

            if (is_array($item) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                $instance = is_string($item['instance'] ?? null) ? $item['instance'] : null;
                $labels[] = $instance === null || $instance === '' ? $item['name'] : "{$item['name']} ({$instance})";
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function routeLabels(mixed $routes): array
    {
        if (! is_array($routes)) {
            return [];
        }

        $labels = [];

        foreach ($routes as $route) {
            if (is_array($route) && is_string($route['host'] ?? null) && $route['host'] !== '') {
                $labels[] = $route['host'];
            }
        }

        return $labels;
    }
}
