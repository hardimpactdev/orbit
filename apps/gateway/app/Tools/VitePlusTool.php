<?php

declare(strict_types=1);

namespace App\Tools;

final class VitePlusTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    public function slug(): string
    {
        return 'viteplus';
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
        return $this->script('install', $config, <<<'BASH'
            MANAGED_GROUP="$(id -gn "${MANAGED_USER}")"
            test -n "${MANAGED_GROUP}"
            sudo install -d -o "${MANAGED_USER}" -g "${MANAGED_GROUP}" "${MANAGED_HOME}/.local" "${MANAGED_HOME}/.local/share"
            sudo install -d -m 0755 /opt/orbit/vite-plus
            sudo chown -R "${MANAGED_USER}:${MANAGED_GROUP}" /opt/orbit/vite-plus
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus bash -lc 'curl -fsSL https://vite.plus | bash'
            VP="$(sudo -u "${MANAGED_USER}" -H bash -lc 'command -v vp' || true)"
            for candidate in /opt/orbit/vite-plus/bin/vp "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            test -x "${VP}"
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" env setup
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" env on
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" env install lts
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" env default lts
            sudo chmod -R a+rX /opt/orbit/vite-plus
            __REFRESH_LINKS__
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" --version
            BASH);
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->script('update', $config, <<<'BASH'
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus /opt/orbit/vite-plus/bin/vp upgrade
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus /opt/orbit/vite-plus/bin/vp env setup
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus /opt/orbit/vite-plus/bin/vp env on
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus /opt/orbit/vite-plus/bin/vp env install lts
            sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus /opt/orbit/vite-plus/bin/vp env default lts
            sudo chmod -R a+rX /opt/orbit/vite-plus
            __REFRESH_LINKS__
            BASH);
    }

    #[\Override]
    public function removeScript(array $config = []): string
    {
        return $this->script('remove', $config, <<<'BASH'
            VP=""
            for candidate in /opt/orbit/vite-plus/bin/vp "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            for binary in vp node npm npx; do
                link="/usr/local/bin/${binary}"
                if is_orbit_viteplus_link "${link}"; then sudo rm -f "${link}"; fi
            done
            if [ "${VP}" = /opt/orbit/vite-plus/bin/vp ]; then
                sudo -u "${MANAGED_USER}" -H env VP_HOME=/opt/orbit/vite-plus "${VP}" implode --yes || test ! -e "${VP}"
                sudo rm -rf /opt/orbit/vite-plus
            elif [ -n "${VP}" ]; then
                sudo -u "${MANAGED_USER}" -H "${VP}" implode --yes
            fi
            BASH);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $managedLinksProbe = <<<'BASH'
            for binary in vp node npm npx; do
                link="/usr/local/bin/${binary}"
                target="/opt/orbit/vite-plus/bin/${binary}"
                test -L "${link}" || exit 1
                test "$(readlink "${link}")" = "${target}" || exit 1
                test -x "${target}" || exit 1
                test -x "${link}" || exit 1
            done
            BASH;
        $versionCommand = $managedLinksProbe."\n".<<<'BASH'
            vp_version="$(/usr/local/bin/vp --version)" || exit 1
            /usr/local/bin/node --version >/dev/null || exit 1
            /usr/local/bin/npm --version >/dev/null || exit 1
            /usr/local/bin/npx --version >/dev/null || exit 1
            printf "%s\n" "${vp_version}"
            BASH;

        return [
            'binary' => '/usr/local/bin/vp',
            'probe' => $managedLinksProbe,
            'version_command' => $versionCommand,
            'update_command' => $this->updateScript(),
        ];
    }

    private function script(string $action, array $config, string $body): string
    {
        $user = is_string($config['managed_user'] ?? null) && trim($config['managed_user']) !== ''
            ? trim($config['managed_user'])
            : 'orbit';

        $refresh = <<<'BASH'
            VP=""
            for candidate in /opt/orbit/vite-plus/bin/vp "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            if [ -z "${VP}" ]; then
                VP="$(sudo -u "${MANAGED_USER}" -H bash -lc 'command -v vp' || true)"
                [ "${VP}" != "/usr/local/bin/vp" ] || VP=""
            fi
            test -x "${VP}"
            VP_BIN="$(dirname "${VP}")"
            for binary in vp node npm npx; do
                link="/usr/local/bin/${binary}"
                target="${VP_BIN}/${binary}"
                if ! test -x "${target}"; then
                    printf 'Vite+ source is missing or not executable: %s\n' "${target}" >&2
                    exit 1
                fi
                if { [ -e "${link}" ] || [ -L "${link}" ]; } && ! is_orbit_viteplus_link "${link}"; then
                    printf 'Vite+ link conflict: %s exists and is not an Orbit-managed Vite+ symlink.\n' "${link}" >&2
                    exit 1
                fi
            done
            for binary in vp node npm npx; do
                link="/usr/local/bin/${binary}"
                target="${VP_BIN}/${binary}"
                sudo ln -sfn "${target}" "${link}"
                test -L "${link}"
                test -x "${link}"
            done
            BASH;

        $linkGuard = <<<'BASH'
            is_orbit_viteplus_link() {
                [ -L "$1" ] || return 1
                case "$(readlink "$1" 2>/dev/null || true)" in
                    /opt/orbit/vite-plus/*|"${MANAGED_HOME}"/.local/share/vite-plus/*|"${MANAGED_HOME}"/.vite-plus/*) return 0 ;;
                    *) return 1 ;;
                esac
            }
            BASH;

        return (
            "#!/usr/bin/env bash\n# orbit {$action} viteplus\nset -e\nMANAGED_USER="
            .escapeshellarg($user)
            ."\nif [ \"\${MANAGED_USER}\" = root ]; then MANAGED_HOME=/root; elif [ \"\$(uname -s)\" = Darwin ]; then MANAGED_HOME=\"/Users/\${MANAGED_USER}\"; else MANAGED_HOME=\"/home/\${MANAGED_USER}\"; fi\n"
            .$linkGuard
            ."\n"
            .str_replace('__REFRESH_LINKS__', $refresh, $body)
        );
    }
}
