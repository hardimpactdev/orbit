<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\ParsesTaskWorkspaceRouted;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class CreateProjectCommand extends GatewayCommand
{
    use ParsesTaskWorkspaceRouted;

    #[\Override]
    protected $signature = 'project:create
        {slug : Unique project slug}
        {type : Project type (monorepo, laravel-app, symfony-app, laravel-package, node-package, or generic)}
        {repository? : Git repository URL; none for a generic Project}
        {--name= : Optional display name}
        {--node= : For a generic Project, the Node it is about: Node ID or registered name}
        {--source-access= : How Orbit reads a private github.com repository: github_app (default) or gh_cli}
        {--default-branch= : Stored default branch; resolve the remote default when omitted}
        {--root= : Repository-relative root; defaults to . for package types and public otherwise}
        {--task-check= : Task check command. Omitted stores none}
        {--task-workspace-routed= : Whether new task workspaces get a Route (true or false)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $slug = $this->stringArgument('slug', 'Project slug', 'project.slug_required');

        if ($slug === null) {
            return self::FAILURE;
        }

        if (strlen($slug) > 63 || preg_match('/[\x00-\x1F\x7F]/', $slug) === 1) {
            return $this->renderGatewayFailure(
                'project.slug_invalid',
                'Project slug is invalid.',
            );
        }

        $taskWorkspaceRouted = $this->taskWorkspaceRouted();

        if (! $taskWorkspaceRouted['valid']) {
            return $this->renderGatewayFailure(
                'project.task_workspace_routed_invalid',
                'Task workspace routed must be true or false.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $type = $this->stringArgument('type', 'Project type', 'project.type_required');

        if ($type === null) {
            return self::FAILURE;
        }

        if (! in_array($type, ['monorepo', 'laravel-app', 'symfony-app', 'laravel-package', 'node-package', 'generic'], true)) {
            return $this->renderGatewayFailure(
                'project.type_invalid',
                'Project type must be monorepo, laravel-app, symfony-app, laravel-package, node-package, or generic.',
            );
        }

        if ($type === 'generic') {
            return $this->createGeneric($connector, $slug, $type);
        }

        $repositoryUrl = $this->stringArgument('repository', 'Repository URL', 'project.repository_required');

        if ($repositoryUrl === null) {
            return self::FAILURE;
        }

        if (! $this->hasSafeRepositoryInput($repositoryUrl)) {
            return $this->renderGatewayFailure(
                'project.repository_invalid',
                'Repository URL is invalid.',
            );
        }

        if ($this->stringOption('node') !== null) {
            return $this->renderGatewayFailure('project.node_unsupported', 'Only a generic Project is about a Node.');
        }

        $sourceAccess = $this->stringOption('source-access');

        if ($sourceAccess !== null && ! in_array($sourceAccess, ['github_app', 'gh_cli'], true)) {
            return $this->renderGatewayFailure(
                'project.source_access_invalid',
                'Source access must be github_app or gh_cli.',
            );
        }

        $root = $this->stringOption('root') ?? $this->defaultRoot($type);
        $taskCheck = $this->stringOption('task-check');

        if ($taskCheck !== null && (trim($taskCheck) === '' || strlen($taskCheck) > 4096)) {
            return $this->renderGatewayFailure('project.task_check_invalid', 'Task check command is invalid.');
        }

        $project = $this->sendWithProgress(
            $connector,
            new CreateProjectRequest(
                slug: $slug,
                repositoryUrl: $repositoryUrl,
                root: $root,
                type: $type,
                name: $this->stringOption('name'),
                defaultBranch: $this->stringOption('default-branch'),
                taskCheck: $taskCheck,
                taskCheckProvided: $taskCheck !== null,
                sourceAccess: $sourceAccess,
                taskWorkspaceRouted: $taskWorkspaceRouted['value'],
            ),
            ProjectResponse::class,
            ['Create Project', 'Creating Project', 'Created Project'],
        );

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        return $this->created($project);
    }

    /** A generic Project takes no repository, root, branch, source access or task setting (docs/reference/projects.md#generic-projects). */
    private function createGeneric(GatewayConnector $connector, string $slug, string $type): int
    {
        if ($this->argument('repository') !== null) {
            return $this->renderGatewayFailure('project.repository_unsupported', 'A generic Project has no repository.');
        }

        foreach (['source-access', 'default-branch', 'root', 'task-check', 'task-workspace-routed'] as $option) {
            if ($this->stringOption($option) !== null) {
                return $this->renderGatewayFailure('project.option_unsupported', "A generic Project takes no --{$option}.");
            }
        }

        $nodeId = $this->stringOption('node') === null ? null : $this->resolveNodeId($connector, $this->option('node'));

        if ($this->stringOption('node') !== null && $nodeId === null) {
            return self::FAILURE;
        }

        $project = $this->sendWithProgress(
            $connector,
            new CreateProjectRequest(slug: $slug, repositoryUrl: null, root: null, type: $type, name: $this->stringOption('name'), nodeId: $nodeId),
            ProjectResponse::class,
            ['Create Project', 'Creating Project', 'Created Project'],
        );

        return $project instanceof ProjectResponse ? $this->created($project) : self::FAILURE;
    }

    private function created(ProjectResponse $project): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$project->slug}] created.");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

        return self::SUCCESS;
    }

    private function defaultRoot(string $type): string
    {
        return in_array($type, ['laravel-package', 'node-package'], true) ? '.' : 'public';
    }

    private function hasSafeRepositoryInput(string $repositoryUrl): bool
    {
        if (
            strlen($repositoryUrl) > 2048
            || preg_match('/\A\S+\z/uD', $repositoryUrl) !== 1
            || str_contains($repositoryUrl, '?')
            || str_contains($repositoryUrl, '#')
            || preg_match('/[\x00-\x20\x7F]/', $repositoryUrl) === 1
            || preg_match('/[\p{C}\p{Z}]/u', $repositoryUrl) === 1
        ) {
            return false;
        }

        if (preg_match('/(?:token|password|secret|key|credential)\s*=/i', $repositoryUrl) === 1) {
            return false;
        }

        $parts = parse_url($repositoryUrl);

        if (! is_array($parts)) {
            return false;
        }

        if (array_key_exists('pass', $parts)) {
            return false;
        }

        $user = $parts['user'] ?? null;

        return $user === null || ($parts['scheme'] ?? null) === 'ssh' && $user === 'git';
    }
}
