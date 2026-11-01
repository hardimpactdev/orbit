<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Project;
use App\Models\ProjectEnvironmentTemplate;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps a Project's environment template in step with the `.env.example` on its default branch.
 *
 * A head already checked costs nothing. A new head costs one GitHub read: the blob SHA of `.env.example`.
 * Only a changed blob downloads the file. Instances keep their stored environment; the template
 * applies at the next create.
 */
final readonly class ProjectEnvironmentTemplateRefresh
{
    public function __construct(private GitHubAppStore $store, private GitHubApi $github) {}

    public function observe(Project $project, string $head): void
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        if ($project->source_access !== ProjectSourceAccess::GitHubApp || ! $repository instanceof GitHubRepository) {
            return;
        }
        $path = self::path($project);
        $template = $project->environmentTemplate()->first();
        if ($template?->checked_head === $head && $template->source_path === $path) {
            return;
        }
        $credentials = $this->store->credentials() ?? throw new GitHubApiException('The Gateway GitHub App is not registered.');
        $installation = $this->github->repositoryInstallation($credentials, $repository);
        if ($installation === null) {
            // The App cannot read this repository, as with gh_cli access; there is no template to keep.
            return;
        }
        $token = $this->github->repositoryReadToken($credentials, $installation, $repository);
        $blob = $this->github->fileBlob($token, $repository, $head, $path);
        if ($template !== null && $template->source_path === $path && $template->source_blob === $blob) {
            $this->record($project, $head, $path, $blob, null);

            return;
        }
        try {
            $example = self::parse($blob === null ? '' : $this->github->blobContents($token, $repository, $blob));
        } catch (GitHubApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            // Keep the template and skip this head; a later head with a valid file updates it.
            $this->record($project, $head, $path, $template?->source_blob, null);

            return;
        }
        $this->record($project, $head, $path, $blob, $example);
    }

    /**
     * Record a `.env.example` the Gateway already read at a head, whatever the Project's source access; the Project
     * source facts capture uses it when a Project is added or its source changes. An unreadable file keeps the template.
     */
    public function recordRead(Project $project, string $head, ?string $blob, string $contents): void
    {
        try {
            $example = self::parse($contents);
        } catch (Throwable $exception) {
            report($exception);
            $example = null;
        }
        $this->record($project, $head, self::path($project), $blob, $example);
    }

    /** The repository-relative `.env.example` of the Project's application directory. */
    public static function path(Project $project): string
    {
        $directory = ltrim(ApplicationDirectory::resolve('', $project->root), '/');

        return $directory === '' ? '.env.example' : $directory.'/.env.example';
    }

    /** @param array<string, string>|null $example null keeps the entries and records only the checked head */
    private function record(Project $project, string $head, string $path, ?string $blob, ?array $example): void
    {
        DB::transaction(function () use ($project, $head, $path, $blob, $example): void {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $current = ProjectEnvironmentTemplate::query()->where('project_id', $project->id)->first();
            if ($current?->checked_head === $head && $current->source_path === $path) {
                return;
            }
            if ($example === null) {
                $current?->update(['checked_head' => $head]);

                return;
            }
            ProjectEnvironmentTemplate::query()->updateOrCreate(['project_id' => $project->id], [
                'entries' => self::merge($current === null ? [] : $current->entries, $example),
                'source_path' => $path, 'source_commit' => $head, 'source_blob' => $blob, 'checked_head' => $head,
            ]);
        });
    }

    /**
     * New keys take their example value. A key whose value still equals its old example follows the
     * new example; an edited value stays. A key that `.env.example` dropped stays, marked removed.
     *
     * @param  list<array{key: string, value: string, example: string|null, removed: bool}>  $entries
     * @param  array<string, string>  $example
     * @return list<array{key: string, value: string, example: string|null, removed: bool}>
     */
    private static function merge(array $entries, array $example): array
    {
        $current = array_column($entries, null, 'key');
        $merged = [];
        foreach ($example as $key => $value) {
            $entry = $current[$key] ?? null;
            $edited = $entry !== null && $entry['value'] !== $entry['example'];
            $merged[] = ['key' => $key, 'value' => $edited ? $entry['value'] : $value, 'example' => $value, 'removed' => false];
        }
        foreach ($entries as $entry) {
            if (! array_key_exists($entry['key'], $example)) {
                $merged[] = [...$entry, 'removed' => true];
            }
        }

        return $merged;
    }

    /** @return array<string, string> unexpanded values in file order */
    private static function parse(string $contents): array
    {
        $values = [];
        foreach (new Parser()->parse($contents) as $entry) {
            $value = $entry->getValue();
            $values[$entry->getName()] = $value->isDefined() ? $value->get()->getChars() : '';
        }

        return $values;
    }
}
