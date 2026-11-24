<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Data\Projects\CreateProjectData;
use App\Data\Projects\ProjectData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class CreateProjectAction
{
    public function __construct(
        private RepositoryDefaultBranchResolver $branches,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    /** @return array{project: Project, created: bool} */
    public function execute(CreateProjectData $data): array
    {
        if (! $data->type->hasRepository()) {
            return $this->createGeneric($data);
        }

        $repositoryUrl = GitRepositoryOrigin::validate((string) $data->repositoryUrl);
        $repositoryIdentity = GitRepositoryIdentity::derive($repositoryUrl);
        $defaultBranch = $data->defaultBranch === null ? null : GitBranchName::validate($data->defaultBranch);
        $root = ProjectRoot::validate((string) $data->root, $data->type);
        $project = Project::query()->where('slug', $data->slug)->first();

        if ($project instanceof Project) {
            $this->assertIdentityMatches($project, $data, $repositoryUrl, $defaultBranch, $root);

            return ['project' => $project, 'created' => false];
        }

        $this->assertRepositoryIdentityAvailable($repositoryIdentity);

        $requestedDefaultBranch = $defaultBranch;

        if ($defaultBranch === null) {
            $defaultBranch = GitBranchName::validate($this->branches->resolve($repositoryUrl, $data->sourceAccess));
        } else {
            $this->branches->verify($repositoryUrl, $defaultBranch, $data->sourceAccess);
        }

        $source = app(CaptureProjectSourceFactsAction::class)->read($repositoryUrl, $data->sourceAccess, $defaultBranch, $root, $data->type);

        for ($attempt = 0; ; $attempt++) {
            $candidate = new Project([...$source['facts'],
                'code' => $data->code,
                'slug' => $data->slug,
                'name' => $data->name,
                'type' => $data->type,
                'repository_url' => $repositoryUrl,
                'source_access' => $data->sourceAccess,
                'default_branch' => $defaultBranch,
                'root' => $root,
                'task_check' => $data->resolvedTaskCheck(),
                'task_workspace_routed' => $data->resolvedTaskWorkspaceRouted(),
            ]);
            try {
                $candidate->save();
                $project = $candidate;
                break;
            } catch (UniqueConstraintViolationException $exception) {
                $project = Project::query()->where('slug', $data->slug)->first();

                if ($project instanceof Project) {
                    $this->assertIdentityMatches(
                        $project,
                        $data,
                        $repositoryUrl,
                        $requestedDefaultBranch,
                        $root,
                    );

                    return ['project' => $project, 'created' => false];
                }

                if (Project::query()->where('repository_identity', $repositoryIdentity)->exists()) {
                    throw $this->repositoryIdentityConflict($exception);
                }

                if ($data->code !== null && Project::query()->where('code', $data->code)->exists()) {
                    throw new ResourceOperationException('project.code_conflict', 'This Project code is already in use.', 409, previous: $exception);
                }
                if ($data->code === null && $attempt < 4 && Project::query()->where('code', $candidate->code)->exists()) {
                    continue;
                }

                throw $exception;
            }
        }

        $project = $project->refresh();
        app(CaptureProjectSourceFactsAction::class)->recordTemplate($project, $source);

        return $this->created($project);
    }

    /**
     * A generic Project has no repository, so creating one reads no remote, captures no source facts and prepares no
     * checkout or Instance. It may name the one Node it is about (projects.md#generic-projects).
     *
     * @return array{project: Project, created: bool}
     */
    private function createGeneric(CreateProjectData $data): array
    {
        $existing = Project::query()->where('slug', $data->slug)->first();

        if ($existing instanceof Project) {
            $this->assertGenericIdentityMatches($existing, $data);

            return ['project' => $existing, 'created' => false];
        }

        for ($attempt = 0; ; $attempt++) {
            $candidate = new Project([
                'code' => $data->code,
                'slug' => $data->slug,
                'name' => $data->name,
                'type' => $data->type,
                'node_id' => $data->nodeId,
            ]);
            try {
                $candidate->save();

                return $this->created($candidate->refresh());
            } catch (UniqueConstraintViolationException $exception) {
                $existing = Project::query()->where('slug', $data->slug)->first();

                if ($existing instanceof Project) {
                    $this->assertGenericIdentityMatches($existing, $data);

                    return ['project' => $existing, 'created' => false];
                }

                if ($data->nodeId !== null && Project::query()->where('node_id', $data->nodeId)->exists()) {
                    throw new ResourceOperationException('project.node_conflict', 'Another Project is already about this Node.', 409, previous: $exception);
                }

                if ($data->code !== null && Project::query()->where('code', $data->code)->exists()) {
                    throw new ResourceOperationException('project.code_conflict', 'This Project code is already in use.', 409, previous: $exception);
                }
                if ($data->code === null && $attempt < 4 && Project::query()->where('code', $candidate->code)->exists()) {
                    continue;
                }

                throw $exception;
            }
        }
    }

    private function assertGenericIdentityMatches(Project $project, CreateProjectData $data): void
    {
        if (
            ($data->code === null || $project->code === $data->code)
            && $project->name === $data->name
            && $project->type === $data->type
            && $project->node_id === $data->nodeId
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'project.identity_conflict',
            message: "Project [{$project->slug}] already exists with different creation identity.",
            status: 409,
        );
    }

    /** @return array{project: Project, created: true} */
    private function created(Project $project): array
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::ProjectCreated,
            $project->id,
            ProjectData::fromModel($project)->toArray(),
        );

        return ['project' => $project, 'created' => true];
    }

    private function assertRepositoryIdentityAvailable(string $repositoryIdentity): void
    {
        if (Project::query()->where('repository_identity', $repositoryIdentity)->exists()) {
            throw $this->repositoryIdentityConflict();
        }
    }

    private function repositoryIdentityConflict(
        ?UniqueConstraintViolationException $previous = null,
    ): ResourceOperationException {
        return new ResourceOperationException(
            errorCode: 'project.repository_identity_conflict',
            message: 'The repository is already owned by another Project.',
            status: 409,
            previous: $previous,
        );
    }

    private function assertIdentityMatches(
        Project $project,
        CreateProjectData $data,
        string $repositoryUrl,
        ?string $defaultBranch,
        string $root,
    ): void {
        if (
            ($data->code === null || $project->code === $data->code)
            && $project->name === $data->name
            && $project->type === $data->type
            && $project->repository_url === $repositoryUrl
            && $project->source_access === $data->sourceAccess
            && ($defaultBranch === null
            || $project->default_branch === $defaultBranch)
            && $project->root === $root
            && (! $data->taskCheckProvided || $project->task_check === $data->taskCheck)
            && (! $data->taskWorkspaceRoutedProvided || $project->task_workspace_routed === $data->taskWorkspaceRouted)
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'project.identity_conflict',
            message: "Project [{$project->slug}] already exists with different creation identity.",
            status: 409,
        );
    }
}
