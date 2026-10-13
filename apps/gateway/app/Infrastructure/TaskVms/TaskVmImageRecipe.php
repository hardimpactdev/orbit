<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeEnrollmentPrograms;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * What every task VM base image holds, whatever builds it: the Incus base image ({@see IncusTaskVmImageBuilder})
 * and the UpCloud template ({@see UpCloudTaskVmImageBuilder}) run the same programs in the same order. The enrollment
 * programs of an `app-dev` Node, rendered by the same code as enrollment, the pinned agent binary, the pinned Pi
 * executable, the image manifest, and `base-image-clean.sh`, which removes the identity last. The scripts are in
 * `resources/task-vms` ({@see TaskVmScripts}).
 */
final readonly class TaskVmImageRecipe
{
    /** Names the builder and smoke VMs or servers of every provider, followed by the build's time stamp. */
    public const string WorkPrefix = 'tvm-image-';

    private const string OwnerCheck = 'test "$(id -u -- "$2"):$(id -g -- "$2")" = "$1"';

    /** `systemd-firstboot` runs on every first boot, and the apt timers would hold the dpkg lock while a task runs. */
    public const string Masks = 'systemctl mask systemd-firstboot.service apt-daily.timer apt-daily-upgrade.timer motd-news.timer unattended-upgrades.service';

    public const string CleanConfirmation = '{"ok":true}';

    public function __construct(
        private NodeEnrollmentPrograms $enrollment,
    ) {}

    /**
     * The programs that enrollment runs on an `app-dev` Node, in its order, rendered by the same code. The
     * image manifest records each one, so enrollment skips the ones it renders the same. Then the `app-dev` PHP
     * packages of every PHP version an Instance can select, which the Instance's route installs on a Node: on a
     * task VM it finds them in place, and its source step skips the refresh. Last the stable Rust toolchain for
     * the managed user (`install-rust.sh`), which enrollment never runs. Each program runs as the managed user, and
     * those that change the system start with `sudo`.
     *
     * @return array<string, RemoteCommand>
     */
    public function programs(): array
    {
        $programs = $this->enrollment->render([RoleName::AppDev]);
        $programs[TaskVmBakedPrograms::RustToolchain] = new RemoteCommand(TaskVmScripts::bash('install-rust.sh'));

        return $programs;
    }

    /**
     * The root command that installs the pinned Node agent binary for `$architecture`, the output of `uname -m` in the
     * builder (`install-agent.sh`).
     *
     * @return list<string>
     */
    public function installAgent(string $architecture, string $subject): array
    {
        $architecture = trim($architecture);
        if (! MachineArchitecture::isValid($architecture) || ! in_array($architecture, NodeAgentFootprint::Architectures, true)) {
            throw new TaskVmException('task_vm.image_build_failed', "The machine architecture of {$subject} is not a Node agent architecture.", 502);
        }

        return TaskVmScripts::bash('install-agent.sh', [NodeAgentFootprint::downloadUrl($architecture), NodeAgentFootprint::checksum($architecture)]);
    }

    /**
     * The command that checks that the managed user of a builder has the pinned uid and gid, before any program runs.
     *
     * @return list<string>
     */
    public static function ownerCheck(): array
    {
        return ['sh', '-euc', self::OwnerCheck, 'sh', TaskVmCloudInit::Owner, TaskVmCloudInit::User];
    }

    /**
     * Checks a VM booted from a new image (`image-check.sh`): the managed user's pinned uid and gid, the manifest, and
     * the Gateway's key, plus the provider's `$checks`, such as `--lean-boot`.
     *
     * @param  list<string>  $checks
     * @return list<string>
     */
    public static function imageCheck(array $checks): array
    {
        return TaskVmScripts::bash('image-check.sh', [TaskVmCloudInit::User, TaskVmCloudInit::Owner, TaskVmBakedPrograms::ManifestPath, ...$checks]);
    }

    /** `base-image-clean.sh`, which runs as root with `bash -s` and prints {@see self::CleanConfirmation}. */
    public function cleanScript(): string
    {
        return TaskVmScripts::read('base-image-clean.sh');
    }
}
