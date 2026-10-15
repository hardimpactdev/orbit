<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

/** Common guest identity checks and cleanup for recipe-built task VM images. */
final readonly class TaskVmImageRecipe
{
    /** Names the builder and smoke VMs or servers, followed by the build's time stamp. */
    public const string WorkPrefix = 'tvm-image-';

    private const string OwnerCheck = 'test "$(id -u -- "$2"):$(id -g -- "$2")" = "$1"';

    /** Keep first-boot services and apt timers from interfering with tasks. */
    public const string Masks = 'systemctl mask systemd-firstboot.service apt-daily.timer apt-daily-upgrade.timer motd-news.timer unattended-upgrades.service';

    public const string CleanConfirmation = '{"ok":true}';

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

    /** The root cleanup program removes machine identity before publication. */
    public function cleanScript(): string
    {
        return TaskVmScripts::read('base-image-clean.sh');
    }
}
