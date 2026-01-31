<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ProjectCreateTool extends Tool
{
    protected string $name = 'orbit_project_create';

    protected string $description = 'Create a new project with optional GitHub template';

    public function __construct(protected ProjectCliService $projectService) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Project name/slug'),
            'template' => $schema->string()->description('GitHub template repository (user/repo format)'),
            'visibility' => $schema->string()->enum(['private', 'public'])->description('Repository visibility'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::error('No local environment configured');
        }

        $name = $request->get('name');
        $template = $request->get('template');
        $visibility = $request->get('visibility', 'private');

        if (! $name) {
            return Response::error('Project name is required');
        }

        $options = [
            'name' => $name,
            'visibility' => $visibility,
        ];

        if ($template) {
            $options['template'] = $template;
            $options['is_template'] = true;
        }

        $result = $this->projectService->createProject($environment, $options);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to create project');
        }

        return Response::structured([
            'success' => true,
            'project_slug' => $result['data']['slug'] ?? $name,
            'status' => $result['data']['status'] ?? 'provisioning',
            'message' => $result['data']['message'] ?? 'Project created successfully',
        ]);
    }
}
