<?php

declare(strict_types=1);

namespace App\Tools;

final class BunTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    public function slug(): string
    {
        return 'bun';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'remove', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return strtr(
            <<<'BASH'
                #!/usr/bin/env bash
                # orbit install bun
                set -e

                MANAGED_USER=__MANAGED_USER__

                managed_home() {
                    case "$(uname -s)" in
                        Darwin) printf '/Users/%s\n' "${MANAGED_USER}" ;;
                        *) printf '/home/%s\n' "${MANAGED_USER}" ;;
                    esac
                }

                MANAGED_HOME="$(managed_home)"
                BUN_HOME="${MANAGED_HOME}/.bun"
                BUN_BINARY="${BUN_HOME}/bin/bun"

                if [ "$(uname -s)" = "Linux" ] && ! command -v unzip >/dev/null 2>&1; then
                    export DEBIAN_FRONTEND=noninteractive
                    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
                    sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq unzip
                fi

                sudo -u "${MANAGED_USER}" -H env BUN_INSTALL="${BUN_HOME}" bash -lc 'curl -fsSL https://bun.com/install | bash'
                sudo ln -sf "${BUN_BINARY}" /usr/local/bin/bun
                sudo -u "${MANAGED_USER}" -H "${BUN_BINARY}" --version
                BASH,
            [
                '__MANAGED_USER__' => $this->managedUser($config),
            ],
        );
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return strtr(
            <<<'BASH'
                #!/usr/bin/env bash
                # orbit update bun
                set -e

                MANAGED_USER=__MANAGED_USER__

                managed_home() {
                    case "$(uname -s)" in
                        Darwin) printf '/Users/%s\n' "${MANAGED_USER}" ;;
                        *) printf '/home/%s\n' "${MANAGED_USER}" ;;
                    esac
                }

                BUN_BINARY="$(managed_home)/.bun/bin/bun"
                sudo -u "${MANAGED_USER}" -H "${BUN_BINARY}" upgrade
                sudo ln -sf "${BUN_BINARY}" /usr/local/bin/bun
                sudo -u "${MANAGED_USER}" -H "${BUN_BINARY}" --version
                BASH,
            [
                '__MANAGED_USER__' => $this->managedUser($config),
            ],
        );
    }

    #[\Override]
    public function removeScript(array $config = []): string
    {
        return strtr(
            <<<'BASH'
                #!/usr/bin/env bash
                # orbit remove bun
                set -e

                MANAGED_USER=__MANAGED_USER__

                case "$(uname -s)" in
                    Darwin) MANAGED_HOME="/Users/${MANAGED_USER}" ;;
                    *) MANAGED_HOME="/home/${MANAGED_USER}" ;;
                esac

                sudo rm -f /usr/local/bin/bun
                sudo rm -rf "${MANAGED_HOME}/.bun"
                BASH,
            [
                '__MANAGED_USER__' => $this->managedUser($config),
            ],
        );
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/bun',
            'version_command' => '/usr/local/bin/bun --version',
            'update_command' => $this->updateScript(),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function managedUser(array $config): string
    {
        $user = $config['managed_user'] ?? 'orbit';

        return escapeshellarg(is_string($user) && trim($user) !== '' ? trim($user) : 'orbit');
    }
}
