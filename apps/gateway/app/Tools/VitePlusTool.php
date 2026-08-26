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

    public function installScript(array $config = []): string
    {
        return $this->script('install', $config, <<<'BASH'
            MANAGED_GROUP="$(id -gn "${MANAGED_USER}")"
            test -n "${MANAGED_GROUP}"
            sudo install -d -o "${MANAGED_USER}" -g "${MANAGED_GROUP}" "${MANAGED_HOME}/.local" "${MANAGED_HOME}/.local/share"
            sudo -u "${MANAGED_USER}" -H bash -lc 'curl -fsSL https://vite.plus | bash'
            VP="$(sudo -u "${MANAGED_USER}" -H bash -lc 'command -v vp' || true)"
            for candidate in "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            test -x "${VP}"
            sudo -u "${MANAGED_USER}" -H "${VP}" env setup
            sudo -u "${MANAGED_USER}" -H "${VP}" env on
            sudo -u "${MANAGED_USER}" -H "${VP}" env install lts
            sudo -u "${MANAGED_USER}" -H "${VP}" env default lts
            __REFRESH_LINKS__
            sudo -u "${MANAGED_USER}" -H "${VP}" --version
            BASH);
    }

    public function updateScript(array $config = []): string
    {
        return $this->script('update', $config, <<<'BASH'
            sudo -u "${MANAGED_USER}" -H /usr/local/bin/vp upgrade
            sudo -u "${MANAGED_USER}" -H /usr/local/bin/vp env setup
            sudo -u "${MANAGED_USER}" -H /usr/local/bin/vp env on
            sudo -u "${MANAGED_USER}" -H /usr/local/bin/vp env install lts
            sudo -u "${MANAGED_USER}" -H /usr/local/bin/vp env default lts
            __REFRESH_LINKS__
            BASH);
    }

    public function removeScript(array $config = []): string
    {
        return $this->script('remove', $config, <<<'BASH'
            VP=""
            for candidate in /usr/local/bin/vp "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            if [ -n "${VP}" ]; then sudo -u "${MANAGED_USER}" -H "${VP}" implode --yes; fi
            sudo rm -f /usr/local/bin/vp /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx
            BASH);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/vp',
            'version_command' => 'vp_version="$(/usr/local/bin/vp --version)" && test -x /usr/local/bin/node && test -x /usr/local/bin/npm && test -x /usr/local/bin/npx && /usr/local/bin/node --version >/dev/null && /usr/local/bin/npm --version >/dev/null && /usr/local/bin/npx --version >/dev/null && printf "%s\\n" "$vp_version"',
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
            for candidate in "${MANAGED_HOME}/.local/share/vite-plus/bin/vp" "${MANAGED_HOME}/.vite-plus/bin/vp"; do
                if [ -x "${candidate}" ]; then VP="${candidate}"; break; fi
            done
            if [ -z "${VP}" ]; then
                VP="$(sudo -u "${MANAGED_USER}" -H bash -lc 'command -v vp' || true)"
                [ "${VP}" != "/usr/local/bin/vp" ] || VP=""
            fi
            test -x "${VP}"
            VP_BIN="$(dirname "${VP}")"
            sudo ln -sf "${VP}" /usr/local/bin/vp
            for binary in node npm npx; do
                test -x "${VP_BIN}/${binary}"
                sudo ln -sf "${VP_BIN}/${binary}" "/usr/local/bin/${binary}"
                test -x "/usr/local/bin/${binary}"
            done
            BASH;

        return (
            "#!/usr/bin/env bash\n# orbit {$action} viteplus\nset -e\nMANAGED_USER="
            .escapeshellarg($user)
            ."\nif [ \"\${MANAGED_USER}\" = root ]; then MANAGED_HOME=/root; elif [ \"\$(uname -s)\" = Darwin ]; then MANAGED_HOME=\"/Users/\${MANAGED_USER}\"; else MANAGED_HOME=\"/home/\${MANAGED_USER}\"; fi\n"
            .str_replace('__REFRESH_LINKS__', $refresh, $body)
        );
    }
}
