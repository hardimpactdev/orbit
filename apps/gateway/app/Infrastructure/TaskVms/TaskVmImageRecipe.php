<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;

/**
 * What every task VM base image holds, whatever builds it: the Incus base image ({@see IncusTaskVmImageBuilder})
 * and the UpCloud template ({@see UpCloudTaskVmImageBuilder}) run the same programs in the same order. The enrollment
 * programs of an `app-dev` Node, rendered by the same code as enrollment, the pinned agent binary, the pinned Pi
 * executable, the image manifest, and `base-image-clean.sh`, which removes the identity last.
 */
final readonly class TaskVmImageRecipe
{
    public const string OwnerCheck = 'test "$(id -u orbit):$(id -g orbit)" = "$1"';

    public const string InstallAgent = <<<'BASH'
        candidate=/usr/local/bin/orbit-agent.orbit-candidate
        curl --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$candidate" -- "$1"
        printf '%s  %s\n' "$2" "$candidate" | sha256sum --check --status
        chown root:root -- "$candidate"
        chmod 0755 -- "$candidate"
        mv -fT -- "$candidate" /usr/local/bin/orbit-agent
        BASH;

    /** `systemd-firstboot` runs on every first boot, and the apt timers would hold the dpkg lock while a task runs. */
    public const string Masks = 'systemctl mask systemd-firstboot.service apt-daily.timer apt-daily-upgrade.timer motd-news.timer unattended-upgrades.service';

    /**
     * Installs the stable Rust toolchain for `orbit` with rustup's minimal profile, plus clippy and rustfmt, as CI
     * checks the Node agent. rustup adds `~/.cargo/bin` only to the login shell's `PATH`, so each of its tools also
     * gets a link in `/usr/local/bin`, which every shell finds: an agent's commands and a check's non-login shells
     * too. Each link runs `orbit`'s toolchain. Each image build takes the stable release of its day.
     */
    public const string InstallRust = <<<'BASH'
        installer=$(mktemp)
        trap 'rm -f -- "$installer"' EXIT
        curl --proto '=https' --tlsv1.2 --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$installer" -- https://sh.rustup.rs
        sh "$installer" -y -q --profile minimal --component clippy,rustfmt
        "$HOME/.cargo/bin/cargo" --version
        for tool in "$HOME"/.cargo/bin/*; do
            sudo ln -sfn -- "$tool" "/usr/local/bin/${tool##*/}"
        done
        BASH;

    public const string CleanConfirmation = '{"ok":true}';

    private const string CleanScript = 'task-vms/base-image-clean.sh';

    public function __construct(
        private NodeBootstrapCommandFactory $bootstrap,
        private NodeRolePrerequisiteCommandFactory $roles,
        private VpToolManager $vp,
        private ComposerToolManager $composer,
        private RemotePhpPackageManager $php,
        private InstancePhpVersionCatalog $phpVersions,
    ) {}

    /**
     * The programs that enrollment runs on an `app-dev` Node, in its order, rendered by the same code. The
     * image manifest records each one, so enrollment skips the ones it renders the same. Then the `app-dev` PHP
     * packages of every PHP version an Instance can select, which the Instance's route installs on a Node: on a
     * task VM it finds them in place, and its source step skips the refresh. Last the stable Rust toolchain for
     * `orbit`, which enrollment never runs. Each program runs as `orbit`, and those that change the system start
     * with `sudo`.
     *
     * @return array<string, RemoteCommand>
     */
    public function programs(): array
    {
        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $programs = [TaskVmBakedPrograms::Bootstrap => $this->bootstrap->makeWithPasswordlessSudo($node, 'orbit')];
        $caddy = $this->roles->caddyPackage($node, RoleName::AppDev);
        if ($caddy instanceof RemoteCommand) {
            $programs[TaskVmBakedPrograms::CaddyPackage] = $caddy;
        }
        $programs[TaskVmBakedPrograms::AppDevPrerequisites] = $this->roles->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        $programs[TaskVmBakedPrograms::VpManager] = $this->vp->materializeCommand($node);
        $programs[TaskVmBakedPrograms::ComposerManager] = $this->composer->materializeCommand($node);
        foreach ($this->php->appDevPrograms($this->phpVersions->versions()) as $name => $program) {
            $programs[TaskVmBakedPrograms::AppDevPhp.'-'.str_replace('.', '-', (string) $name)] = $program;
        }
        $programs[TaskVmBakedPrograms::RustToolchain] = new RemoteCommand(['bash', '-ceu', self::InstallRust]);

        return $programs;
    }

    /**
     * The root command that installs the pinned Node agent binary for `$architecture`, the output of `uname -m` in the
     * builder. The agent itself is configured at enrollment.
     *
     * @return list<string>
     */
    public function installAgent(string $architecture, string $subject): array
    {
        $architecture = trim($architecture);
        if (! MachineArchitecture::isValid($architecture) || ! in_array($architecture, NodeAgentFootprint::Architectures, true)) {
            throw new TaskVmException('task_vm.image_build_failed', "The machine architecture of {$subject} is not a Node agent architecture.", 502);
        }

        return ['bash', '-ceu', self::InstallAgent, '--', NodeAgentFootprint::downloadUrl($architecture), NodeAgentFootprint::checksum($architecture)];
    }

    /** `base-image-clean.sh`, which runs as root with `bash -s` and prints {@see self::CleanConfirmation}. */
    public function cleanScript(): string
    {
        $script = file_get_contents(resource_path(self::CleanScript));

        return is_string($script) ? $script : throw new TaskVmException('task_vm.setup_unavailable', 'The base image clean script is missing.');
    }
}
