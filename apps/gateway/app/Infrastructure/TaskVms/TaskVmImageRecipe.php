<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;

/** Common guest identity checks and cleanup for recipe-built task VM images. */
final readonly class TaskVmImageRecipe
{
    /** Names the builder and smoke VMs or servers, followed by the build's time stamp. */
    public const string WorkPrefix = 'tvm-image-';

    private const string OwnerCheck = 'test "$(id -u -- "$2"):$(id -g -- "$2")" = "$1"';

    /** Keep first-boot services and apt timers from interfering with tasks. */
    public const string Masks = 'systemctl mask systemd-firstboot.service apt-daily.timer apt-daily-upgrade.timer motd-news.timer unattended-upgrades.service';

    public function __construct(private GuestAgentCommandRunner $agents) {}

    /** @return list<string> */
    public static function ownerCheck(): array
    {
        return ['sh', '-euc', self::OwnerCheck, 'sh', TaskVmCloudInit::Owner, TaskVmCloudInit::User];
    }

    /**
     * @param  list<string>  $checks
     * @return list<string>
     */
    public static function imageCheck(array $checks): array
    {
        return TaskVmScripts::bash('image-check.sh', [TaskVmCloudInit::User, TaskVmCloudInit::Owner, ...$checks]);
    }

    /** Installs the pin explicitly, then removes only an unenrolled builder's identity. */
    public function clean(AgentGuest $guest): void
    {
        $this->agents->installGuest($guest);
        try {
            $result = $this->agents->runGuest($guest, 'image.clean');
        } catch (AgentCommandRefused $error) {
            if (! in_array($error->errorCode, ['task_vm.image_clean_failed', 'task_vm.image_identity_present'], true)) {
                throw $error;
            }
            throw new TaskVmException('task_vm.image_build_failed', "Image cleanup failed on [{$guest->name}]: ".$error->getMessage(), 502);
        }
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.image_build_failed', "Image cleanup did not confirm on [{$guest->name}].", 502);
        }
    }
}
