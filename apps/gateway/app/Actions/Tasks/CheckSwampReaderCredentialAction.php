<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\SwampReaderAssistance;
use App\Domain\Tasks\SwampReaderCredential;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CheckSwampReaderCredentialAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private SwampReaderEnvironment $environment,
        private TaskExtensionState $extension,
        private SettingRepository $settings,
        private CreateTaskGroupAction $create,
    ) {}

    public function execute(): ?int
    {
        return $this->lock->run(function (): ?int {
            $values = $this->environment->values($this->environment->contents());
            $token = $values['ORBIT_SWAMP_READ_TOKEN'] ?? '';
            if ($token === '') {
                return null;
            }
            $expiry = SwampReaderCredential::expiry($values['ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT'] ?? '');
            if (($values['ORBIT_SWAMP_READ_TOKEN_NAME'] ?? '') !== '' && $expiry !== null && $expiry->greaterThan(now()->addDays(7))) {
                return null;
            }
            if (! $this->extension->enabled()) {
                throw new RuntimeException('Swamp reader rotation needs assistance, but Tasks is disabled.');
            }
            $project = Project::query()->where('slug', 'orbit')->first();
            if (! $project instanceof Project) {
                throw new RuntimeException('Swamp reader rotation needs assistance, but the orbit Project is missing.');
            }
            $scope = new SettingScope(SettingScopeType::Gateway);
            $key = SwampReaderAssistance::key($token);
            $reason = SwampReaderAssistance::reason($expiry);

            return DB::transaction(function () use ($scope, $key, $reason, $project): int {
                $id = $this->settings->get($scope, $key);
                $group = $id === null ? null : Task::topLevel()->lockForUpdate()->find((int) $id);
                if (! $group instanceof Task || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                    $group = $this->create->execute(new CreateTaskGroupData(
                        projectId: $project->id,
                        title: 'Rotate the Gateway Swamp reader credential',
                        brief: $reason."\n\nThe Gateway operator mints a replacement under a new name with only workflow and data read grants. Pipe its reveal to gateway:swamp-reader:install, apply Gateway configuration, verify a fresh serving read, then revoke the old token. See docs/reference/gateway-environment.md#rotate-the-swamp-reader-credential. Never post credentials here.",
                        status: TaskGroupStatus::Backlog,
                        tasks: [],
                    ));
                    $this->settings->put($scope, $key, (string) $group->id);
                }
                TaskAssistance::apply($group, AssistanceKind::Direction, $reason, $reason);

                return $group->id;
            });
        });
    }
}
