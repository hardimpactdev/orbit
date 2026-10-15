<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;

/**
 * The cloud-init user-data of task VMs: the `orbit` user with passwordless sudo and the Gateway's
 * SSH key. A task VM boots from the host's base image, which already holds everything else, so its
 * user-data installs nothing. The base image builder gets the same user, created with the pinned uid and
 * gid {@see self::Owner}. The shared recipe installer handles packages after cloud-init.
 *
 * It sets no SSH option such as `ssh_pwauth`: on that image cloud-init applies it before
 * `openssh-server` exists, writes a one-line `sshd_config` with `UsePAM no`, and sshd then
 * refuses the passwordless `orbit` user as locked.
 */
final readonly class TaskVmCloudInit
{
    /** The user that every task VM, builder, and smoke VM has. Orbit runs everything in the guest as this user or as root. */
    public const string User = 'orbit';

    /** The home directory of {@see self::User}. */
    public const string Home = '/home/'.self::User;

    /**
     * The stable uid and gid of `orbit`, including on VMs with an existing workspace volume.
     */
    public const int Uid = 1000;

    public const string Owner = '1000:1000';

    private const string PublicKeyPattern = '/\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/]+={0,3}(?: [!-~][ -~]*)?\z/D';

    /**
     * Playwright's `chromium` dependency list for `ubuntu26.04-x64` (`nativeDeps.ts`), which
     * covers both `chromium` and `chromium-headless-shell`. Its separate `tools` list (xvfb and
     * fonts) is for headed runs and is left out.
     *
     * @var list<string>
     */
    public const array ChromiumLibraries = [
        'libasound2t64', 'libatk-bridge2.0-0t64', 'libatk1.0-0t64', 'libatspi2.0-0t64', 'libcairo2',
        'libcups2t64', 'libdbus-1-3', 'libdrm2', 'libgbm1', 'libglib2.0-0t64', 'libnspr4', 'libnss3',
        'libpango-1.0-0', 'libx11-6', 'libxcb1', 'libxcomposite1', 'libxdamage1', 'libxext6',
        'libxfixes3', 'libxkbcommon0', 'libxrandr2',
    ];

    /**
     * Tools that Project checks use beyond the `app-dev` role: `rsync` and `dig`, which Orbit's own test suites
     * call, and a C compiler with the C library headers, which Rust crates such as `ring` build with.
     *
     * @var list<string>
     */
    public const array WorkspaceTools = ['rsync', 'bind9-dnsutils', 'gcc', 'libc6-dev'];

    /**
     * The user-data of a task VM, which boots from the base image. `orbit` is in `incus-admin` from the first boot, before
     * Pi starts, so the agents it starts can run a nested topology once Incus is installed. The group is a system group,
     * as the Incus package creates it, so the package keeps it. `orbit` already has passwordless sudo, so the group
     * grants nothing more.
     */
    public function render(string $gatewayPublicKey): string
    {
        return self::document([
            // The base image already has `orbit`, and cloud-init adds groups only to a user it creates.
            'bootcmd' => [['sh', '-c', 'getent group incus-admin >/dev/null || groupadd --system incus-admin; if id orbit >/dev/null 2>&1; then usermod -aG incus-admin orbit; fi']],
            'users' => [[...$this->user($gatewayPublicKey), 'groups' => 'incus-admin']],
        ]);
    }

    /** The user-data of the base image builder, which boots from the stock image. */
    public function renderImageBuilder(string $gatewayPublicKey): string
    {
        // bootcmd runs before cloud-init creates users, so the group exists with its pinned gid. A taken uid or gid
        // makes cloud-init fail, and with it the build.
        return self::document([
            'bootcmd' => [['sh', '-c', 'getent group '.self::User.' >/dev/null || groupadd --gid '.self::Uid.' '.self::User]],
            'users' => [[...$this->user($gatewayPublicKey), 'uid' => self::Uid, 'primary_group' => self::User, 'no_user_group' => true]],
        ]);
    }

    /**
     * Adds the ed25519 host key that the Gateway generated for a VM without a trusted host, an UpCloud server, to
     * user-data that {@see self::document()} rendered. Cloud-init deletes the image's keys and writes this one, so the
     * Gateway knows the key before the first SSH connection. Root login stays off.
     *
     * @param  array{private: string, public: string}  $hostKey
     */
    public static function withHostKey(string $userData, array $hostKey): string
    {
        $config = str_starts_with($userData, "#cloud-config\n") ? json_decode(substr($userData, 14), true) : null;
        if (! is_array($config)) {
            throw new TaskVmException('task_vm.invalid_user_data', 'The task VM user-data is not the cloud-config document Orbit renders.', 500);
        }

        return self::document([
            ...$config,
            'disable_root' => true,
            'ssh_deletekeys' => true,
            'ssh_genkeytypes' => [],
            'ssh_keys' => ['ed25519_private' => $hostKey['private'], 'ed25519_public' => $hostKey['public']],
        ]);
    }

    /** @return array<string, mixed> */
    private function user(string $gatewayPublicKey): array
    {
        if (preg_match(self::PublicKeyPattern, $gatewayPublicKey) !== 1) {
            throw new TaskVmException('task_vm.invalid_gateway_key', 'The Gateway SSH public key is not a single OpenSSH public key line.', 500);
        }

        return [
            'name' => self::User,
            'lock_passwd' => true,
            'shell' => '/bin/bash',
            'sudo' => 'ALL=(ALL) NOPASSWD:ALL',
            'ssh_authorized_keys' => [$gatewayPublicKey],
        ];
    }

    /** @param  array<string, mixed>  $config */
    private static function document(array $config): string
    {
        return "#cloud-config\n".json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }
}
