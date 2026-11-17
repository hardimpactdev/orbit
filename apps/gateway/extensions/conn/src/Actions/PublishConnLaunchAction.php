<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\ConnAttachedThreads;
use Orbit\Extensions\Conn\Domain\ConnLaunchChild;
use Orbit\Extensions\Conn\Domain\ConnLaunchState;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;

final readonly class PublishConnLaunchAction
{
    public function __construct(
        private ConnLaunchState $state,
        private ConnThreadIndex $index,
        private ApplyConnProfileSectionsAction $sections,
    ) {}

    public function execute(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $launch = $this->state->locked($id);
            $accepted = $launch->state['launch_thread'] ?? null;
            if (! is_string($accepted) || $launch->status->terminal() || $launch->cancel_requested_at !== null) {
                return;
            }
            $thread = ValidatedData::object(json_decode($accepted, true, flags: JSON_THROW_ON_ERROR));
            $environment = ConnEnvironment::query()->where('environment_id', ValidatedData::string($thread['environment_id']))
                ->where('instance_id', ConnLaunchChild::instanceId($launch))->where('node_id', $launch->node_id)
                ->lockForUpdate()->firstOrFail();
            $profile = ConnProfile::query()->lockForUpdate()->findOrFail($launch->target_id);
            $entry = ['environmentId' => $environment->environment_id, 'threadId' => ValidatedData::string($thread['thread_id'])];
            $projectRef = $environment->environment_id.':'.ValidatedData::string($thread['project_id']);
            $writes = self::writes($profile, $entry, $projectRef, ValidatedData::nullableString($launch->input['workspace_id'] ?? null));
            $this->index->record($environment, $entry['threadId'], 'Orbit launch', seedTitle: true);
            if ($writes !== []) {
                $this->sections->execute($profile, null, $writes, launchEnvironment: $environment);
            }
            $this->state->published($id);
        });
    }

    /** @param array{environmentId: string,threadId: string} $entry
     * @return array<string,mixed>
     */
    private static function writes(ConnProfile $profile, array $entry, string $projectRef, ?string $workspaceId): array
    {
        $settings = $profile->settings;
        $attached = $settings[ConnProfileSections::AttachedThreads] ?? null;
        $entries = ConnAttachedThreads::entries($attached);
        $writes = [];
        if (! in_array($entry, $entries, true)) {
            $writes[ConnProfileSections::AttachedThreads] = [...(is_array($attached) ? $attached : []), 'threads' => [...$entries, $entry]];
        }
        $workspaces = $settings[ConnProfileSections::Workspaces] ?? null;
        if ($workspaceId === null || ! is_array($workspaces)) {
            return $writes;
        }
        foreach ($workspaces as $key => $workspace) {
            if (! is_array($workspace) || ($workspace['id'] ?? null) !== $workspaceId) {
                continue;
            }
            $refs = $workspace['projectRefs'] ?? [];
            if (is_array($refs) && ! in_array($projectRef, $refs, true)) {
                $workspaces[$key] = [...$workspace, 'projectRefs' => [...$refs, $projectRef]];
                $writes[ConnProfileSections::Workspaces] = $workspaces;
            }
            break;
        }

        return $writes;
    }
}
