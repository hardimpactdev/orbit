<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Version\InstallMetadataStore;
use App\Services\Version\VersionOutputParser;
use JsonException;
use Symfony\Component\Process\Process;

final readonly class LocalFleetUpdateInstallCliAction
{
    public function __construct(
        private LocalFleetUpdateInstallCliEnvironment $environment,
        private InstallMetadataStore $installMetadata = new InstallMetadataStore,
        private VersionOutputParser $versionOutputParser = new VersionOutputParser,
    ) {}

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

        $process = new Process(['/usr/bin/env', 'bash', '-c', $this->installScript()]);
        $process->setTimeout(300);
        $process->setEnv($this->environment->forPayload($installPayload, $path));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LocalFleetUpdateInstallCliFailure(
                errorCode: 'fleet_update.cli_install_failed',
                message: 'CLI install failed.',
                meta: $this->processMeta($process),
            );
        }

        $stdout = trim($process->getOutput());
        $this->recordInstallMetadata($installPayload, $stdout, $process);

        return [
            'installed' => true,
            'bin_path' => $installPayload->binPath,
            'install_root' => $installPayload->installRoot,
            'agent_bin_path' => $installPayload->agentArtifact?->binPath,
            'agent_installed' => $installPayload->agentArtifact instanceof LocalFleetUpdateInstallAgentPayload,
            'role_images' => $installPayload->roleImages,
            'stdout' => $stdout,
        ];
    }

    private function recordInstallMetadata(
        LocalFleetUpdateInstallCliPayload $installPayload,
        string $stdout,
        Process $process,
    ): void {
        $version = $this->versionOutputParser->fromJsonOutput($stdout);

        if ($version === null) {
            throw new LocalFleetUpdateInstallCliFailure(
                errorCode: 'fleet_update.cli_version_unstructured',
                message: 'CLI install succeeded but version output was not structured JSON.',
                meta: $this->processMeta($process),
            );
        }

        $this->installMetadata->write(
            version: $version,
            binaryPath: $installPayload->binPath,
            installRoot: $installPayload->installRoot,
        );
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

            find_command() {
                name="$1"

                if command -v "$name" >/dev/null 2>&1; then
                    command -v "$name"
                    return
                fi

                for candidate in /usr/local/bin/"$name" /usr/bin/"$name" /bin/"$name" /usr/sbin/"$name" /sbin/"$name"; do
                    if [ -x "$candidate" ]; then
                        printf '%s\n' "$candidate"
                        return
                    fi
                done
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

            install_agent_config() {
                config_path="${ORBIT_AGENT_SERVICE_CONFIG_PATH:-}"
                config_base64="${ORBIT_AGENT_SERVICE_CONFIG_BASE64:-}"
                ca_path="${ORBIT_AGENT_SERVICE_CA_PATH:-}"
                ca_base64="${ORBIT_AGENT_SERVICE_CA_BASE64:-}"

                if [ -z "$config_path" ] || [ -z "$config_base64" ] || [ -z "$ca_path" ] || [ -z "$ca_base64" ]; then
                    return
                fi

                echo write_agent_config
                printf '%s' "$config_base64" | base64 --decode > "$tmp/orbit-agent.toml"
                printf '%s' "$ca_base64" | base64 --decode > "$tmp/orbit-root.crt"

                config_parent="$(dirname "$config_path")"
                ca_parent="$(dirname "$ca_path")"
                config_staged="$config_parent/.orbit-agent-config.$$.tmp"
                ca_staged="$ca_parent/.orbit-agent-ca.$$.tmp"

                cleanup_agent_config_staging() {
                    if [ -n "$config_staged" ]; then
                        run_privileged rm -f "$config_staged" >/dev/null 2>&1 || true
                    fi

                    if [ -n "$ca_staged" ]; then
                        run_privileged rm -f "$ca_staged" >/dev/null 2>&1 || true
                    fi
                }

                run_privileged install -d -m 0755 "$config_parent"
                run_privileged install -d -m 0755 "$ca_parent"

                if ! run_privileged install -m 0644 "$tmp/orbit-agent.toml" "$config_staged"; then
                    cleanup_agent_config_staging
                    return 1
                fi

                if ! run_privileged install -m 0644 "$tmp/orbit-root.crt" "$ca_staged"; then
                    cleanup_agent_config_staging
                    return 1
                fi

                if ! run_privileged mv -f "$ca_staged" "$ca_path"; then
                    cleanup_agent_config_staging
                    return 1
                fi
                ca_staged=""

                if ! run_privileged mv -f "$config_staged" "$config_path"; then
                    cleanup_agent_config_staging
                    return 1
                fi
                config_staged=""
            }

            link_binary() {
                target="$1"
                link="$2"
                parent="$(dirname "$link")"

                run_privileged install -d -m 0755 "$parent"
                run_privileged ln -sfn "$target" "$link"
            }

            perform_download_artifact() {
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

            download_artifact() {
                url="$1"
                target="$2"
                max_attempts="${ORBIT_ARTIFACT_DOWNLOAD_ATTEMPTS:-3}"
                retry_delay="${ORBIT_ARTIFACT_DOWNLOAD_RETRY_DELAY:-1}"
                attempt=1

                while [ "$attempt" -le "$max_attempts" ]; do
                    if perform_download_artifact "$url" "$target"; then
                        return
                    else
                        status="$?"
                    fi

                    if [ "$attempt" -ge "$max_attempts" ]; then
                        return "$status"
                    fi

                    attempt=$((attempt + 1))
                    echo "download_retry attempt=$attempt"
                    sleep "$retry_delay"
                done
            }

            restart_agent_service_if_present() {
                agent_bin_path="${ORBIT_AGENT_BIN_PATH:-$HOME/.local/bin/orbit-agent}"
                systemctl_bin="$(find_command systemctl || true)"

                if [ -n "$systemctl_bin" ]; then
                    if "$systemctl_bin" status orbit-agent >/dev/null 2>&1; then
                        status_rc=0
                    else
                        status_rc="$?"
                    fi

                    if "$systemctl_bin" is-enabled orbit-agent >/dev/null 2>&1; then
                        enabled_rc=0
                    else
                        enabled_rc="$?"
                    fi

                    echo "probe_agent_unit systemctl=$systemctl_bin status=$status_rc enabled=$enabled_rc"
                else
                    status_rc=127
                    enabled_rc=127
                    echo "probe_agent_unit systemctl=missing status=$status_rc enabled=$enabled_rc"
                fi

                if [ "$status_rc" -eq 0 ] || [ "$enabled_rc" -eq 0 ]; then
                    if agent_systemd_service_payload_present; then
                        converge_agent_systemd_service "$systemctl_bin"
                        echo converge_agent_unit
                    fi

                    systemd_run_bin="$(find_command systemd-run || true)"

                    if [ -n "$systemd_run_bin" ]; then
                        unit="orbit-agent-self-restart-$(date +%s)-$$"

                        if run_privileged "$systemd_run_bin" \
                            --unit="$unit" \
                            --description="Restart Orbit Agent after self-update" \
                            --on-active=5s \
                            --collect \
                            "$systemctl_bin" restart orbit-agent; then
                            echo schedule_agent_restart
                            return
                        fi
                    fi

                    echo restart_agent
                    run_privileged "$systemctl_bin" restart orbit-agent
                    return
                fi

                launchctl_bin="${ORBIT_AGENT_LAUNCHCTL_BIN:-}"

                if [ -z "$launchctl_bin" ]; then
                    launchctl_bin="$(find_command launchctl || true)"
                fi

                if [ -n "$launchctl_bin" ]; then
                    label="${ORBIT_AGENT_LAUNCHD_LABEL:-dev.orbit.agent}"
                    service="gui/$(id -u)/$label"

                    if "$launchctl_bin" print "$service" >/dev/null 2>&1; then
                        if "$launchctl_bin" kickstart -k "$service" >/dev/null 2>&1; then
                            echo restart_agent_launchd
                            return
                        fi

                        echo restart_agent_launchd_failed >&2
                        return 1
                    fi
                fi

                if restart_unmanaged_agent_process "$agent_bin_path"; then
                    return
                else
                    unmanaged_restart_rc="$?"
                fi

                if [ "$unmanaged_restart_rc" -eq 2 ]; then
                    return 1
                fi

                echo 'agent_service_missing_bootstrap_required: bootstrap must create the Orbit Agent service before update' >&2
                return 1
            }

            agent_systemd_service_payload_present() {
                unit_name="${ORBIT_AGENT_SERVICE_UNIT_NAME:-}"
                config_path="${ORBIT_AGENT_SERVICE_CONFIG_PATH:-}"
                http_bind="${ORBIT_AGENT_SERVICE_HTTP_BIND:-}"

                [ -n "$unit_name" ] && [ -n "$config_path" ] && [ -n "$http_bind" ]
            }

            converge_agent_systemd_service() {
                systemctl_bin="$1"
                unit_name="${ORBIT_AGENT_SERVICE_UNIT_NAME:-}"
                config_path="${ORBIT_AGENT_SERVICE_CONFIG_PATH:-}"
                agent_unit_base64="${ORBIT_AGENT_SYSTEMD_UNIT_BASE64:-}"
                runtime_boot_script_base64="${ORBIT_RUNTIME_BOOT_SCRIPT_BASE64:-}"
                runtime_boot_unit_base64="${ORBIT_RUNTIME_BOOT_UNIT_BASE64:-}"
                runtime_boot_script_path="${ORBIT_RUNTIME_BOOT_SCRIPT_PATH:-}"
                runtime_boot_unit_name="${ORBIT_RUNTIME_BOOT_UNIT_NAME:-}"
                runtime_boot_unit_path="${ORBIT_RUNTIME_BOOT_UNIT_PATH:-}"

                case "$unit_name" in
                    *.service) service="$unit_name" ;;
                    *) service="$unit_name.service" ;;
                esac

                if [ ! -f "$config_path" ]; then
                    echo skip_agent_converge_no_config
                    return 1
                fi

                unit_path="$tmp/$service"
                printf '%s' "$agent_unit_base64" | base64 --decode > "$unit_path"
                printf '%s' "$runtime_boot_script_base64" | base64 --decode > "$tmp/orbit-runtime-boot-converge"
                printf '%s' "$runtime_boot_unit_base64" | base64 --decode > "$tmp/orbit-runtime-boot-converge.service"

                run_privileged install -d -m 0755 "$(dirname "$runtime_boot_script_path")"
                run_privileged install -m 0755 "$tmp/orbit-runtime-boot-converge" "$runtime_boot_script_path"
                run_privileged install -m 0644 "$tmp/orbit-runtime-boot-converge.service" "$runtime_boot_unit_path"
                run_privileged install -m 0644 "$unit_path" "/etc/systemd/system/$service"
                run_privileged "$systemctl_bin" daemon-reload
                run_privileged "$systemctl_bin" enable "$runtime_boot_unit_name"
                run_privileged "$systemctl_bin" enable "$service"
            }

            restart_unmanaged_agent_process() {
                agent_bin_path="$1"
                pgrep_bin="$(find_command pgrep || true)"
                ps_bin="$(find_command ps || true)"

                if [ -z "$pgrep_bin" ] || [ -z "$ps_bin" ]; then
                    return 1
                fi

                agent_pids="$(agent_process_pids "$agent_bin_path" "$pgrep_bin" "$ps_bin")"

                if [ -z "$agent_pids" ]; then
                    return 1
                fi

                first_pid="$(printf '%s\n' "$agent_pids" | head -n 1)"
                agent_config="${ORBIT_AGENT_CONFIG:-}"
                agent_http_bind="${ORBIT_AGENT_HTTP_BIND:-}"

                if [ -n "$first_pid" ] && [ -r "/proc/$first_pid/environ" ]; then
                    if [ -z "$agent_config" ]; then
                        agent_config="$(tr '\0' '\n' < "/proc/$first_pid/environ" | sed -n 's/^ORBIT_AGENT_CONFIG=//p' | head -n 1)"
                    fi

                    if [ -z "$agent_http_bind" ]; then
                        agent_http_bind="$(tr '\0' '\n' < "/proc/$first_pid/environ" | sed -n 's/^ORBIT_AGENT_HTTP_BIND=//p' | head -n 1)"
                    fi
                fi

                if [ -z "$agent_config" ]; then
                    agent_config="$HOME/.config/orbit/agent.toml"
                fi

                if [ ! -f "$agent_config" ]; then
                    echo skip_agent_restart_no_config >&2
                    return 2
                fi

                echo restart_agent_unmanaged
                printf '%s\n' "$agent_pids" | while IFS= read -r agent_pid; do
                    [ -n "$agent_pid" ] || continue
                    run_privileged kill -TERM "$agent_pid" >/dev/null 2>&1 || true
                done
                sleep 1
                printf '%s\n' "$agent_pids" | while IFS= read -r agent_pid; do
                    [ -n "$agent_pid" ] || continue
                    run_privileged kill -KILL "$agent_pid" >/dev/null 2>&1 || true
                done
                start_unmanaged_agent "$agent_bin_path" "$agent_config" "$agent_http_bind"
            }

            agent_process_pids() {
                agent_bin_path="$1"
                pgrep_bin="$2"
                ps_bin="$3"

                "$pgrep_bin" -f "$agent_bin_path" 2>/dev/null | while IFS= read -r candidate_pid; do
                    [ -n "$candidate_pid" ] || continue

                    if [ "$candidate_pid" = "$$" ]; then
                        continue
                    fi

                    command_line="$("$ps_bin" -p "$candidate_pid" -o command= 2>/dev/null || true)"

                    case "$command_line" in
                        "$agent_bin_path"|"$agent_bin_path "*) echo "$candidate_pid" ;;
                    esac
                done
            }

            start_unmanaged_agent() {
                agent_bin_path="$1"
                agent_config="$2"
                agent_http_bind="$3"
                log_path="${ORBIT_AGENT_LOG_PATH:-$HOME/.config/orbit/agent.log}"
                log_dir="$(dirname "$log_path")"

                mkdir -p "$log_dir"

                echo start_agent_unmanaged

                if [ -n "$agent_http_bind" ]; then
                    nohup env ORBIT_AGENT_CONFIG="$agent_config" ORBIT_AGENT_HTTP_BIND="$agent_http_bind" "$agent_bin_path" > "$log_path" 2>&1 &
                    return
                fi

                nohup env ORBIT_AGENT_CONFIG="$agent_config" "$agent_bin_path" > "$log_path" 2>&1 &
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

            if [ -z "$shared_binary_path" ]; then
                case "$bin_path" in
                    /usr/local/bin/*)
                        link_name="$(basename "$bin_path")"
                        shared_binary_path="/usr/local/lib/orbit/${link_name}-binary-$sha_prefix"
                        ;;
                esac
            fi

            if [ -n "$shared_binary_path" ]; then
                install_binary "$tmp/orbit" "$shared_binary_path"
                link_target="$shared_binary_path"

                if [ "$(basename "$shared_binary_path")" = "orbit-binary-$sha_prefix" ]; then
                    link_binary "$shared_binary_path" "$(dirname "$shared_binary_path")/orbit-binary"
                fi
            fi

            link_binary "$link_target" "$bin_path"

            echo verify_cli
            check_sha256 "$ORBIT_CLI_SHA256" "$install_root/bin/orbit-binary-$sha_prefix"
            check_sha256 "$ORBIT_CLI_SHA256" "$link_target"
            resolved_binary="$(readlink -f "$bin_path" 2>/dev/null || printf %s "$bin_path")"
            check_sha256 "$ORBIT_CLI_SHA256" "$resolved_binary"
            "$bin_path" --version --local --json

            if [ -n "${ORBIT_AGENT_ARTIFACT_URL:-}" ]; then
                agent_bin_path="${ORBIT_AGENT_BIN_PATH:-$HOME/.local/bin/orbit-agent}"

                echo download_agent
                download_artifact "$ORBIT_AGENT_ARTIFACT_URL" "$tmp/orbit-agent"
                check_sha256 "$ORBIT_AGENT_SHA256" "$tmp/orbit-agent"

                echo install_agent
                install_binary "$tmp/orbit-agent" "$agent_bin_path"
                install_agent_config

                echo verify_agent
                resolved_agent_binary="$(readlink -f "$agent_bin_path" 2>/dev/null || printf %s "$agent_bin_path")"
                check_sha256 "$ORBIT_AGENT_SHA256" "$resolved_agent_binary"
            fi

            role_images_lines="${ORBIT_ROLE_IMAGES_LINES:-}"
            if [ -n "$role_images_lines" ]; then
                if ! command -v docker >/dev/null 2>&1; then
                    echo skip_required_images_no_docker
                else
                    role_image_artifacts_lines="${ORBIT_ROLE_IMAGE_ARTIFACTS_LINES:-}"
                    if [ -n "$role_image_artifacts_lines" ]; then
                        echo load_required_image_artifacts
                        printf '%s\n' "$role_image_artifacts_lines" | while IFS=' ' read -r image_encoded url_encoded image_sha256; do
                            if [ -z "$image_encoded" ] || [ -z "$url_encoded" ] || [ -z "$image_sha256" ]; then
                                continue
                            fi

                            image="$(printf %s "$image_encoded" | base64 --decode)"
                            image_url="$(printf %s "$url_encoded" | base64 --decode)"
                            image_archive="$tmp/role-image-$image_sha256.tar"

                            echo "download_required_image_artifact $image"
                            download_artifact "$image_url" "$image_archive"
                            check_sha256 "$image_sha256" "$image_archive"
                            docker load --input "$image_archive"
                            loaded_image="${image%@*}"
                            docker image inspect "$loaded_image" >/dev/null
                        done
                    fi

                    echo pull_required_images
                    printf '%s\n' "$role_images_lines" | while IFS= read -r image_encoded; do
                        if [ -z "$image_encoded" ]; then
                            continue
                        fi

                        image="$(printf %s "$image_encoded" | base64 --decode)"

                        if docker image inspect "$image" >/dev/null 2>&1; then
                            echo "required_image_present $image"
                            continue
                        fi

                        if ! docker pull "$image"; then
                            echo "skip_required_image_pull_failed $image"
                            continue
                        fi

                        if ! docker image inspect "$image" >/dev/null; then
                            echo "skip_required_image_inspect_failed $image"
                        fi
                    done

                    role_image_aliases_lines="${ORBIT_ROLE_IMAGE_ALIASES_LINES:-}"
                    if [ -n "$role_image_aliases_lines" ]; then
                        echo alias_required_images
                        printf '%s\n' "$role_image_aliases_lines" | while IFS=' ' read -r source_encoded target_encoded; do
                            if [ -z "$source_encoded" ] || [ -z "$target_encoded" ]; then
                                continue
                            fi

                            source_image="$(printf %s "$source_encoded" | base64 --decode)"
                            target_image="$(printf %s "$target_encoded" | base64 --decode)"
                            local_source_image="${source_image%@*}"
                            source_id="$(docker image inspect --format '{{.Id}}' "$local_source_image")"
                            test -n "$source_id"
                            docker image tag "$source_id" "$target_image"
                            target_id="$(docker image inspect --format '{{.Id}}' "$target_image")"
                            test "$target_id" = "$source_id"
                            echo "aliased_required_image $target_image $source_id"
                        done
                    fi
                fi
            fi

            if [ -n "${ORBIT_AGENT_ARTIFACT_URL:-}" ]; then
                restart_agent_service_if_present
            fi

            echo verify
            "$bin_path" --version --local --json
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
