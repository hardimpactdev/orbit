<?php

declare(strict_types=1);

namespace App\Services\Operations;

use JsonException;
use Symfony\Component\Process\Process;

final readonly class LocalFleetUpdateInstallCliAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(array $payload): array
    {
        $installPayload = LocalFleetUpdateInstallCliPayload::fromArray($payload);
        $path = $_SERVER['PATH'] ?? getenv('PATH');

        $process = new Process(['/usr/bin/env', 'bash', '-lc', $this->installScript()]);
        $process->setTimeout(300);
        $agentArtifact = $installPayload->agentArtifact;
        $process->setEnv([
            'PATH' => is_string($path) ? $path : '',
            'ORBIT_CLI_ARTIFACT_URL' => $installPayload->artifactUrl,
            'ORBIT_CLI_SHA256' => strtolower($installPayload->sha256),
            'ORBIT_INSTALL_PATH' => $installPayload->installRoot,
            'ORBIT_BIN_PATH' => $installPayload->binPath,
            'ORBIT_SHARED_BINARY_PATH' => $installPayload->sharedBinaryPath ?? '',
            'ORBIT_AGENT_ARTIFACT_URL' => $agentArtifact->artifactUrl ?? '',
            'ORBIT_AGENT_SHA256' => strtolower($agentArtifact->sha256 ?? ''),
            'ORBIT_AGENT_BIN_PATH' => $agentArtifact->binPath ?? '',
            'ORBIT_ROLE_IMAGES_JSON' => json_encode($installPayload->roleImages, JSON_THROW_ON_ERROR),
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LocalFleetUpdateInstallCliFailure(
                errorCode: 'fleet_update.cli_install_failed',
                message: 'CLI install failed.',
                meta: $this->processMeta($process),
            );
        }

        return [
            'installed' => true,
            'bin_path' => $installPayload->binPath,
            'install_root' => $installPayload->installRoot,
            'agent_bin_path' => $installPayload->agentArtifact?->binPath,
            'agent_installed' => $installPayload->agentArtifact instanceof LocalFleetUpdateInstallAgentPayload,
            'role_images' => $installPayload->roleImages,
            'stdout' => trim($process->getOutput()),
        ];
    }

    private function installScript(): string
    {
        return <<<'BASH'
            set -euo pipefail

            tmp="$(mktemp -d)"
            trap 'rm -rf "$tmp"' EXIT

            run_privileged() {
                if "$@"; then
                    return
                fi

                sudo -n "$@"
            }

            check_sha256() {
                expected="$1"
                file="$2"

                if command -v sha256sum >/dev/null 2>&1; then
                    printf '%s  %s\n' "$expected" "$file" | sha256sum -c -
                    return
                fi

                if command -v shasum >/dev/null 2>&1; then
                    actual="$(shasum -a 256 "$file" | awk '{ print $1 }')"
                    test "$actual" = "$expected"
                    return
                fi

                echo "No SHA-256 checksum tool found." >&2
                return 127
            }

            install_binary() {
                source="$1"
                target="$2"
                parent="$(dirname "$target")"
                staged="$parent/.orbit-install-$(basename "$target").$$"

                run_privileged install -d -m 0755 "$parent"
                run_privileged install -m 0755 "$source" "$staged"
                run_privileged mv -f "$staged" "$target"
            }

            link_binary() {
                target="$1"
                link="$2"
                parent="$(dirname "$link")"

                run_privileged install -d -m 0755 "$parent"
                run_privileged ln -sfn "$target" "$link"
            }

            download_artifact() {
                url="$1"
                target="$2"

                case "$url" in
                    file:///*)
                        cp "${url#file://}" "$target"
                        ;;
                    *)
                        curl -fksSL "$url" -o "$target"
                        ;;
                esac
            }

            restart_agent_service_if_present() {
                if ! command -v systemctl >/dev/null 2>&1; then
                    echo skip_agent_restart_no_systemctl
                    return
                fi

                if systemctl status orbit-agent >/dev/null 2>&1 || systemctl is-enabled orbit-agent >/dev/null 2>&1; then
                    echo restart_agent
                    run_privileged systemctl restart orbit-agent
                    return
                fi

                echo skip_agent_restart_no_unit
            }

            echo download_cli
            download_artifact "$ORBIT_CLI_ARTIFACT_URL" "$tmp/orbit"
            check_sha256 "$ORBIT_CLI_SHA256" "$tmp/orbit"

            install_root="${ORBIT_INSTALL_PATH:-/home/orbit/orbit}"
            bin_path="${ORBIT_BIN_PATH:-/usr/local/bin/orbit}"
            shared_binary_path="${ORBIT_SHARED_BINARY_PATH:-}"
            sha_prefix="${ORBIT_CLI_SHA256:0:12}"

            echo install_cli
            install_binary "$tmp/orbit" "$install_root/bin/orbit-binary-$sha_prefix"
            link_target="$install_root/bin/orbit-binary-$sha_prefix"

            case "$bin_path" in
                /usr/local/bin/*)
                    if [ -z "$shared_binary_path" ]; then
                        link_name="$(basename "$bin_path")"
                        shared_binary_path="/usr/local/lib/orbit/${link_name}-binary-$sha_prefix"
                    fi

                    install_binary "$tmp/orbit" "$shared_binary_path"
                    link_target="$shared_binary_path"
                    ;;
            esac

            link_binary "$link_target" "$bin_path"

            echo verify_cli
            check_sha256 "$ORBIT_CLI_SHA256" "$install_root/bin/orbit-binary-$sha_prefix"
            check_sha256 "$ORBIT_CLI_SHA256" "$link_target"
            resolved_binary="$(readlink -f "$bin_path" 2>/dev/null || printf %s "$bin_path")"
            check_sha256 "$ORBIT_CLI_SHA256" "$resolved_binary"
            "$bin_path" --version --local

            if [ -n "${ORBIT_AGENT_ARTIFACT_URL:-}" ]; then
                agent_bin_path="${ORBIT_AGENT_BIN_PATH:-/usr/local/bin/orbit-agent}"

                echo download_agent
                download_artifact "$ORBIT_AGENT_ARTIFACT_URL" "$tmp/orbit-agent"
                check_sha256 "$ORBIT_AGENT_SHA256" "$tmp/orbit-agent"

                echo install_agent
                install_binary "$tmp/orbit-agent" "$agent_bin_path"

                echo verify_agent
                resolved_agent_binary="$(readlink -f "$agent_bin_path" 2>/dev/null || printf %s "$agent_bin_path")"
                check_sha256 "$ORBIT_AGENT_SHA256" "$resolved_agent_binary"
                restart_agent_service_if_present
            fi

            role_images_json="${ORBIT_ROLE_IMAGES_JSON:-[]}"
            if [ "$role_images_json" != "[]" ]; then
                if ! command -v docker >/dev/null 2>&1; then
                    echo skip_required_images_no_docker
                else
                    echo pull_required_images
                    php -r '$images = json_decode(getenv("ORBIT_ROLE_IMAGES_JSON"), true, 512, JSON_THROW_ON_ERROR); foreach ($images as $image) { echo $image, "\n"; }' | while IFS= read -r image; do
                        if ! docker pull "$image"; then
                            echo "skip_required_image_pull_failed $image"
                            continue
                        fi

                        if ! docker image inspect "$image" >/dev/null; then
                            echo "skip_required_image_inspect_failed $image"
                        fi
                    done
                fi
            fi

            echo verify
            "$bin_path" --version --local
            BASH;
    }

    /**
     * @return array<string, mixed>
     */
    private function processMeta(Process $process): array
    {
        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }
}
