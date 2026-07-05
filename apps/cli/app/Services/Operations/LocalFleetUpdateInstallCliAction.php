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
        $process->setEnv([
            'PATH' => is_string($path) ? $path : '',
            'ORBIT_CLI_ARTIFACT_URL' => $installPayload->artifactUrl,
            'ORBIT_CLI_SHA256' => strtolower($installPayload->sha256),
            'ORBIT_INSTALL_PATH' => $installPayload->installRoot,
            'ORBIT_BIN_PATH' => $installPayload->binPath,
            'ORBIT_SHARED_BINARY_PATH' => $installPayload->sharedBinaryPath ?? '',
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
                file="$1"

                if command -v sha256sum >/dev/null 2>&1; then
                    printf '%s  %s\n' "$ORBIT_CLI_SHA256" "$file" | sha256sum -c -
                    return
                fi

                if command -v shasum >/dev/null 2>&1; then
                    actual="$(shasum -a 256 "$file" | awk '{ print $1 }')"
                    test "$actual" = "$ORBIT_CLI_SHA256"
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

            echo download_cli
            case "$ORBIT_CLI_ARTIFACT_URL" in
                file:///*)
                    cp "${ORBIT_CLI_ARTIFACT_URL#file://}" "$tmp/orbit"
                    ;;
                *)
                    curl -fksSL "$ORBIT_CLI_ARTIFACT_URL" -o "$tmp/orbit"
                    ;;
            esac
            check_sha256 "$tmp/orbit"

            install_root="${ORBIT_INSTALL_PATH:-/home/orbit/orbit}"
            bin_path="${ORBIT_BIN_PATH:-/usr/local/bin/orbit}"
            shared_binary_path="${ORBIT_SHARED_BINARY_PATH:-}"

            echo install_cli
            install_binary "$tmp/orbit" "$install_root/bin/orbit-binary"
            link_target="$install_root/bin/orbit-binary"

            case "$bin_path" in
                /usr/local/bin/*)
                    if [ -z "$shared_binary_path" ]; then
                        link_name="$(basename "$bin_path")"
                        shared_binary_path="/usr/local/lib/orbit/${link_name}-binary"
                    fi

                    install_binary "$tmp/orbit" "$shared_binary_path"
                    link_target="$shared_binary_path"
                    ;;
            esac

            link_binary "$link_target" "$bin_path"

            echo reconcile_launcher
            resolved="$(command -v orbit 2>/dev/null || true)"
            case "$resolved" in
                /*)
                    resolved_target="$(readlink -f "$resolved" 2>/dev/null || printf %s "$resolved")"
                    link_target_resolved="$(readlink -f "$link_target" 2>/dev/null || printf %s "$link_target")"

                    if [ "$resolved" != "$bin_path" ] && [ "$resolved_target" != "$link_target_resolved" ]; then
                        link_binary "$link_target" "$resolved" || true
                    fi
                    ;;
            esac

            echo verify_cli
            check_sha256 "$install_root/bin/orbit-binary"
            check_sha256 "$link_target"
            resolved_binary="$(readlink -f "$bin_path" 2>/dev/null || printf %s "$bin_path")"
            check_sha256 "$resolved_binary"
            "$bin_path" --version --local

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
