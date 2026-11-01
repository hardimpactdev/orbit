<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * Captures the Project facts a development create needs before its run: the PHP version and whether the
 * application is Laravel, from the application directory on the default branch. The same read records the Project's
 * environment template ({@see ProjectEnvironmentTemplateRefresh}), which then follows new heads.
 */
final readonly class CaptureProjectSourceFactsAction
{
    public function __construct(private ProjectSourceReader $reader, private ComposerSourceClassifier $composer) {}

    /** @return array{facts:array{php_version:?string, is_laravel:bool, source_profile_error:?string, source_facts_captured_at:CarbonImmutable}, head:string, template:array{mode:string, object:string, content:string}|null} */
    public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, ?string $root, ProjectType $type): array
    {
        $application = ltrim(ApplicationDirectory::resolve('', $root), '/');
        $path = static fn (string $file): string => $application === '' ? $file : $application.'/'.$file;
        $entry = $type->frameworkEntryPoint();
        $read = $this->reader->read($repositoryUrl, $access, $branch, [$path('.env.example'), $path('composer.json'), $path($entry)]);
        $files = $read['files'];
        $composer = $files[$path('composer.json')];
        $profile = $error = null;
        if ($type !== ProjectType::Monorepo && $composer !== null) {
            $entryFile = $files[$path($entry)];
            $entryKind = $entryFile === null ? 'absent' : (in_array($entryFile['mode'], ['100644', '100755'], true) ? 'regular' : 'unsafe');
            try {
                $profile = $this->composer->classify($composer['content'], $type, $entryKind);
            } catch (RuntimeConvergenceException $exception) {
                // A Project may exist without a development Instance; create admission refuses with this code.
                $error = $exception->errorCode;
            }
        }

        return ['facts' => ['php_version' => $profile?->phpVersion, 'is_laravel' => $profile !== null && $profile->laravel,
            'source_profile_error' => $error, 'source_facts_captured_at' => CarbonImmutable::now()],
            'head' => $read['head'], 'template' => $files[$path('.env.example')]];
    }

    /** @param array{head:string, template:array{mode:string, object:string, content:string}|null} $read */
    public function recordTemplate(Project $project, array $read): void
    {
        $template = $read['template'];
        $regular = $template !== null && in_array($template['mode'], ['100644', '100755'], true);
        app(ProjectEnvironmentTemplateRefresh::class)->recordRead($project, $read['head'], $regular ? $template['object'] : null, $regular ? $template['content'] : '');
    }

    public function execute(Project $project): Project
    {
        return $this->record($project, $this->read($project->repository_url, $project->source_access, (string) $project->default_branch, $project->root, $project->type));
    }

    /** @param array{facts:array<string,mixed>, head:string, template:array{mode:string, object:string, content:string}|null} $read */
    public function record(Project $project, array $read): Project
    {
        $project->update($read['facts']);
        $this->recordTemplate($project, $read);

        return $project;
    }
}
