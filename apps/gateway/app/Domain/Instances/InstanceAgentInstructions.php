<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Projects\ProjectAgentInstructionSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use Illuminate\Container\Attributes\Tag;

/**
 * The agent folder of a clone ([Agent instructions](/reference/development-containers#agent-instructions)): the note of the Project's
 * type, then the Project's own `AGENTS.md`, and the Project's skills. A clone's create writes it once, so a copy keeps the version it
 * started with. Claude Code and Codex read it through four fixed links.
 */
final readonly class InstanceAgentInstructions
{
    /** In the agent's home, so no other container sees it: the Node's agent folder is shared by every container on the Node. */
    public const string Folder = '/home/agent/.config/orbit/agent';

    /**
     * The Project's files in bytes and in number. The intent crosses as one command argument, which Linux holds to 128 KiB: JSON
     * escapes a character of text to at most three times its UTF-8 bytes, so 32 KiB becomes at most 96 KiB beside the rest of the intent.
     */
    public const int MaxBytes = 32768;

    public const int MaxFiles = 32;

    public const string ProjectHeading = '# This Project';

    private const string Segment = '~^(?!\.{1,2}$)[A-Za-z0-9._-]{1,128}$~D';

    /** @param iterable<ProjectAgentInstructionSource> $sources */
    public function __construct(#[Tag(ProjectAgentInstructionSource::class)] private iterable $sources = []) {}

    /**
     * The four links, the same for every clone.
     *
     * @return list<array{path: string, target: string}>
     */
    public static function links(): array
    {
        return [
            ['path' => '/home/agent/.claude/CLAUDE.md', 'target' => self::Folder.'/AGENTS.md'],
            ['path' => '/home/agent/.codex/AGENTS.md', 'target' => self::Folder.'/AGENTS.md'],
            ['path' => '/home/agent/.claude/skills', 'target' => self::Folder.'/.skills'],
            ['path' => '/home/agent/.agents/skills', 'target' => self::Folder.'/.skills'],
        ];
    }

    /**
     * The Project's own files, checked: `AGENTS.md`, and files under `.skills/<skill>/` with plain names; text, within the limits. Other
     * paths in the folder are not agent instructions and stay out. Runs outside a database transaction.
     *
     * @return array<string, string>
     */
    public function read(Project $project): array
    {
        $found = [];
        foreach ($this->sources as $source) {
            $found = [...$found, ...$source->files($project)];
        }
        $found = array_filter($found, static fn (string $path): bool => $path === 'AGENTS.md' || str_starts_with($path, '.skills/'), ARRAY_FILTER_USE_KEY);
        ksort($found, SORT_STRING);
        foreach (array_keys($found) as $path) {
            if ($path !== 'AGENTS.md' && ! self::skillPath($path)) {
                throw new ResourceOperationException('projects.agent_instructions_invalid',
                    "The agent instruction file [agent/{$path}] is not in a skill folder, or its name holds more than letters, digits, '.', '_' and '-'.", 422);
            }
        }
        $bytes = array_sum(array_column($found, 'bytes'));
        if (count($found) > self::MaxFiles || $bytes > self::MaxBytes) {
            throw new ResourceOperationException('projects.agent_instructions_too_large',
                'The agent/ folder holds '.count($found)." files of {$bytes} bytes; a copy takes at most ".self::MaxFiles.' files of '.self::MaxBytes.' bytes.', 422,
                details: ['files' => count($found), 'bytes' => $bytes, 'max_files' => self::MaxFiles, 'max_bytes' => self::MaxBytes]);
        }
        $files = [];
        foreach ($found as $path => $file) {
            $content = ($file['read'])();
            // A control character other than a tab or a line end is no text, and its JSON escape takes six bytes.
            if (! mb_check_encoding($content, 'UTF-8') || preg_match('~[^\P{Cc}\t\n\r]~u', $content) === 1) {
                throw new ResourceOperationException('projects.agent_instructions_invalid',
                    "The agent instruction file [agent/{$path}] is not UTF-8 text without control characters.", 422);
            }
            $files[$path] = $content;
        }

        return $files;
    }

    /**
     * The clone intent's `agent_instructions`: the folder and its whole content. `AGENTS.md` is the note of the Project's type, then
     * the Project's own text under its heading.
     *
     * @param  array<string, string>  $files  From read()
     * @return array{folder: string, files: list<array{path: string, content: string}>}
     */
    public function intent(Project $project, array $files): array
    {
        $note = InstanceNotes::all()[InstanceNotes::for($project->type)];
        $own = $files['AGENTS.md'] ?? null;
        unset($files['AGENTS.md']);
        $agents = $own === null ? $note : rtrim($note, "\n")."\n\n".self::ProjectHeading."\n\n".rtrim($own, "\n")."\n";

        return ['folder' => self::Folder, 'files' => [
            ['path' => 'AGENTS.md', 'content' => $agents],
            ...array_map(static fn (string $path, string $content): array => ['path' => $path, 'content' => $content], array_keys($files), $files),
        ]];
    }

    /**
     * What `project:show` reports: `project` when the folder holds an `AGENTS.md` or a skill, else `type_note`. Records only.
     *
     * @return array{source: string, agents_md_version: int|null, skills: list<string>}
     */
    public function summary(Project $project): array
    {
        foreach ($this->sources as $source) {
            $summary = $source->summary($project);
            if ($summary !== null) {
                return ['source' => 'project', ...$summary];
            }
        }

        return ['source' => 'type_note', 'agents_md_version' => null, 'skills' => []];
    }

    /** `.skills/<skill>/<file>`, or deeper, each name after `.skills` plain. */
    private static function skillPath(string $path): bool
    {
        $segments = explode('/', $path);

        return count($segments) >= 3 && $segments[0] === '.skills'
            && array_filter(array_slice($segments, 1), static fn (string $segment): bool => preg_match(self::Segment, $segment) !== 1) === [];
    }
}
