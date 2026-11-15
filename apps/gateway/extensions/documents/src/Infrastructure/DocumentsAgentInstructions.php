<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Projects\ProjectAgentInstructionSource;
use App\Models\Project;
use LogicException;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;

/**
 * The Project's reserved `agent/` folder at the root of its Documents ([Project Documents](/reference/project-documents#agent-instructions)).
 * An archived entry and everything below it is absent. While the extension is disabled, no Project has instructions.
 */
final readonly class DocumentsAgentInstructions implements ProjectAgentInstructionSource
{
    public const string Folder = 'agent';

    public function __construct(private ExtensionStore $extensions, private DocumentBodies $bodies) {}

    public function files(Project $project): array
    {
        $entries = array_filter($this->walk($project), static fn (ProjectDocumentEntry $entry): bool => $entry->kind === 'file' && $entry->current_version_id !== null);
        $versions = ProjectDocumentVersion::query()->whereIn('id', array_map(static fn (ProjectDocumentEntry $entry): ?int => $entry->current_version_id, $entries))
            ->get()->keyBy('id');
        $files = [];
        foreach ($entries as $path => $entry) {
            $version = $versions[$entry->current_version_id] ?? throw new LogicException("Document [{$entry->id}] has no current version.");
            $files[$path] = ['bytes' => $version->size_bytes,
                'read' => fn (): string => $this->bodies->get($version->storage_key, $version->size_bytes, $version->sha256)];
        }

        return $files;
    }

    public function summary(Project $project): ?array
    {
        $agents = null;
        $skills = [];
        foreach ($this->walk($project) as $path => $entry) {
            if ($entry->kind !== 'file' || $entry->current_version_id === null) {
                continue;
            }
            if ($path === 'AGENTS.md') {
                $agents = ProjectDocumentVersion::query()->whereKey($entry->current_version_id)->value('number');
            }
            $segments = explode('/', $path);
            if (count($segments) >= 3 && $segments[0] === '.skills') {
                $skills[$segments[1]] = $segments[1];
            }
        }
        $skills = array_values($skills);
        sort($skills, SORT_STRING);

        return $agents === null && $skills === [] ? null : ['agents_md_version' => is_numeric($agents) ? (int) $agents : null, 'skills' => $skills];
    }

    /**
     * Every active entry below `agent/`, by its path relative to it.
     *
     * @return array<string, ProjectDocumentEntry>
     */
    private function walk(Project $project): array
    {
        if (! $this->extensions->enabled('documents')) {
            return [];
        }
        $root = ProjectDocumentEntry::query()->where('project_id', $project->id)->whereNull('parent_id')
            ->where('kind', 'folder')->where('name', self::Folder)->whereNull('archived_at')->first();
        if ($root === null) {
            return [];
        }
        $entries = [];
        $parents = [$root->id => ''];
        while ($parents !== []) {
            $children = ProjectDocumentEntry::query()->where('project_id', $project->id)->whereIn('parent_id', array_keys($parents))
                ->whereNull('archived_at')->orderBy('name')->get();
            $next = [];
            foreach ($children as $child) {
                $path = $parents[(int) $child->parent_id].$child->name;
                $entries[$path] = $child;
                if ($child->kind === 'folder') {
                    $next[$child->id] = $path.'/';
                }
            }
            $parents = $next;
        }

        return $entries;
    }
}
