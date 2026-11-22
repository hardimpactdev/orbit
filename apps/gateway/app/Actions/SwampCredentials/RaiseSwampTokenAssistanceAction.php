<?php

declare(strict_types=1);

namespace App\Actions\SwampCredentials;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\SwampReaderAssistance;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asks the operator for help when an installed Gateway Swamp token expires within three days, which means automatic renewal failed.
 * The Swamp credential monitor cannot see the Gateway env, so this is the alert for the generation the Gateway actually holds. One
 * task group per token; a successful installation clears it.
 */
final readonly class RaiseSwampTokenAssistanceAction
{
    public function __construct(
        private TaskExtensionState $extension,
        private SettingRepository $settings,
        private CreateTaskGroupAction $create,
    ) {}

    public function execute(SwampGatewayToken $kind, #[\SensitiveParameter] string $token, ?CarbonImmutable $expiry): int
    {
        if (! $this->extension->enabled()) {
            throw new RuntimeException("The Gateway Swamp {$kind->value} token needs assistance, but Tasks is disabled.");
        }
        $project = Project::query()->where('slug', 'orbit')->first();
        if (! $project instanceof Project) {
            throw new RuntimeException("The Gateway Swamp {$kind->value} token needs assistance, but the orbit Project is missing.");
        }
        $scope = new SettingScope(SettingScopeType::Gateway);
        $key = SwampReaderAssistance::key($token);
        $reason = SwampReaderAssistance::reason($expiry, $kind);

        return DB::transaction(function () use ($scope, $key, $reason, $project, $kind): int {
            $id = $this->settings->get($scope, $key);
            $group = $id === null ? null : Task::topLevel()->lockForUpdate()->find((int) $id);
            if (! $group instanceof Task || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                $group = $this->create->execute(new CreateTaskGroupData(
                    projectId: $project->id,
                    title: "Renew the Gateway Swamp {$kind->value} token",
                    brief: $reason."\n\nAutomatic renewal by gateway:swamp-credentials did not install a new generation. Read its output on the Gateway, fix the cause and run it again. See docs/reference/gateway-environment.md#renew-the-swamp-read-and-run-tokens. Never post credentials here.",
                    status: TaskGroupStatus::Backlog,
                    tasks: [],
                ));
                $this->settings->put($scope, $key, (string) $group->id);
            }
            TaskAssistance::apply($group, AssistanceKind::Direction, $reason, $reason);

            return $group->id;
        });
    }
}
