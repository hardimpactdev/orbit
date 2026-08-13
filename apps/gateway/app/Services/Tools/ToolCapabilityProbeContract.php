<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\RemoteShell\RemoteShellResult;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ToolCapabilityProbeContract
{
    public function renderOne(ToolCapabilityProbeInput $input): string
    {
        $script = <<<'SH'
            set -eu
            # orbit-tool-probe:capability

            binary=__BINARY__
            binary_as_user=__BINARY_AS_USER__
            version_command=__VERSION_COMMAND__
            service=__SERVICE__
            provider_command=__PROVIDER_COMMAND__
            container=__CONTAINER__
            extra_probe=__EXTRA_PROBE__

            path=''
            version=''
            state='unknown'
            provider_reachable=''
            provider_error=''
            config_exists=''
            config_hash=''
            secret_exists=''
            secret_hash=''
            container_exists=''
            container_state=''
            container_spec_hash=''

            case "$binary" in
                */*)
                    if [ -n "$binary_as_user" ]; then
                        if sudo -u "$binary_as_user" -H test -x "$binary" 2>/dev/null; then
                            path=$binary
                        fi
                    elif [ -x "$binary" ]; then
                        path=$binary
                    fi
                    ;;
                *)
                    path=$(command -v "$binary" 2>/dev/null || true)
                    ;;
            esac

            if [ -z "$path" ]; then
                exit 1
            fi

            if [ -n "$extra_probe" ]; then
                if ! sh -c "$extra_probe" >/dev/null 2>&1; then
                    exit 1
                fi
            fi

            if [ -n "$version_command" ]; then
                version=$(sh -c "$version_command" 2>/dev/null | sed -n '1p' || true)
            fi

            if [ -n "$provider_command" ]; then
                if provider_output=$(sh -c "$provider_command" 2>&1 >/dev/null); then
                    provider_reachable='1'
                else
                    provider_reachable='0'
                    provider_error=$(printf '%s' "$provider_output" | sed -n '1p')
                fi
            fi

            if [ -n "$service" ]; then
                if systemctl is-active --quiet "$service" 2>/dev/null; then
                    state='running'
                else
                    state='stopped'
                fi
            fi

            if [ -n "$container" ]; then
                if docker container inspect "$container" >/dev/null 2>&1; then
                    container_exists='1'
                    container_state=$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || printf 'stopped')

                    state=$container_state
                    container_spec_hash=$(docker container inspect --format '{{index .Config.Labels "orbit.caddy.spec_hash"}}' "$container" 2>/dev/null || true)

                    if [ "$container_spec_hash" = "<no value>" ]; then
                        container_spec_hash=''
                    fi
                else
                    container_exists='0'
                    container_state='missing'
                fi
            fi

            printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$path" "$version" "$state" "$config_exists" "$config_hash" "$secret_exists" "$secret_hash" "$container_exists" "$container_state" "$container_spec_hash" "$provider_reachable" "$provider_error"
            SH;

        return strtr($script, [
            '__BINARY__' => escapeshellarg($input->binary),
            '__BINARY_AS_USER__' => escapeshellarg($input->binaryAsUser),
            '__VERSION_COMMAND__' => escapeshellarg($input->versionCommand),
            '__SERVICE__' => escapeshellarg($input->service),
            '__PROVIDER_COMMAND__' => escapeshellarg($input->providerCommand),
            '__CONTAINER__' => escapeshellarg($input->container),
            '__EXTRA_PROBE__' => escapeshellarg($input->extraProbe),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseOne(RemoteShellResult $result): array
    {
        $parts = explode(separator: "\t", string: trim($result->stdout), limit: 12);
        $containerState = ($parts[8] ?? '') !== '' ? $parts[8] : null;

        return [
            'installed' => $result->successful(),
            'path' => $parts[0] !== '' ? $parts[0] : null,
            'version' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
            'state' => $containerState ?? (($parts[2] ?? '') !== '' ? $parts[2] : null),
            'config_exists' => ($parts[3] ?? '') !== '' ? $parts[3] === '1' : null,
            'config_hash' => ($parts[4] ?? '') !== '' ? $parts[4] : null,
            'secret_exists' => ($parts[5] ?? '') !== '' ? $parts[5] === '1' : null,
            'secret_hash' => ($parts[6] ?? '') !== '' ? $parts[6] : null,
            'container_exists' => ($parts[7] ?? '') !== '' ? $parts[7] === '1' : null,
            'container_state' => $containerState,
            'container_spec_hash' => ($parts[9] ?? '') !== '' ? $parts[9] : null,
            'provider_reachable' => ($parts[10] ?? '') !== '' ? $parts[10] === '1' : null,
            'provider_error' => ($parts[11] ?? '') !== '' ? $parts[11] : null,
        ];
    }

    /**
     * @param  array<string, ToolCapabilityProbeInput>  $inputs
     */
    public function renderMany(array $inputs): string
    {
        $cases = [];

        foreach ($inputs as $name => $input) {
            $cases[] = implode("\n", [
                '        '.escapeshellarg($name).')',
                '            binary='.escapeshellarg($input->binary),
                '            binary_as_user='.escapeshellarg($input->binaryAsUser),
                '            version_command='.escapeshellarg($input->versionCommand),
                '            service='.escapeshellarg($input->service),
                '            provider_command='.escapeshellarg($input->providerCommand),
                '            container='.escapeshellarg($input->container),
                '            extra_probe='.escapeshellarg($input->extraProbe),
                '            ;;',
            ]);
        }

        $script = <<<'SH'
            set -eu
            # orbit-tool-probe:capability-batch

            json_escape() {
                printf '%s' "$1" | awk 'BEGIN { ORS = "" } { gsub(/\\/, "\\\\"); gsub(/"/, "\\\""); gsub(/\t/, "\\t"); gsub(/\r/, "\\r"); gsub(/\n/, "\\n"); print }'
            }

            json_string_or_null() {
                if [ -n "$1" ]; then
                    printf '"%s"' "$(json_escape "$1")"
                else
                    printf 'null'
                fi
            }

            while IFS= read -r name; do
                if [ -z "$name" ]; then
                    continue
                fi

                binary=''
                binary_as_user=''
                version_command=''
                service=''
                provider_command=''
                container=''
                extra_probe=''

                case "$name" in
            __CASES__
                    *)
                        continue
                        ;;
                esac

                path=''
                version=''
                state='unknown'
                provider_reachable='null'
                provider_error=''
                container_exists='null'
                container_state=''
                container_spec_hash=''

                case "$binary" in
                    */*)
                        if [ -n "$binary_as_user" ]; then
                            if sudo -u "$binary_as_user" -H test -x "$binary" 2>/dev/null; then
                                path=$binary
                            fi
                        elif [ -x "$binary" ]; then
                            path=$binary
                        fi
                        ;;
                    *)
                        path=$(command -v "$binary" 2>/dev/null || true)
                        ;;
                esac

                if [ -n "$path" ] && [ -n "$extra_probe" ]; then
                    if ! sh -c "$extra_probe" >/dev/null 2>&1; then
                        path=''
                    fi
                fi

                if [ -n "$path" ] && [ -n "$version_command" ]; then
                    version=$(sh -c "$version_command" 2>/dev/null | sed -n '1p' || true)
                fi

                if [ -n "$path" ] && [ -n "$provider_command" ]; then
                    if provider_output=$(sh -c "$provider_command" 2>&1 >/dev/null); then
                        provider_reachable='true'
                    else
                        provider_reachable='false'
                        provider_error=$(printf '%s' "$provider_output" | sed -n '1p')
                    fi
                fi

                if [ -n "$path" ] && [ -n "$service" ]; then
                    if systemctl is-active --quiet "$service" 2>/dev/null; then
                        state='running'
                    else
                        state='stopped'
                    fi
                fi

                if [ -n "$path" ] && [ -n "$container" ]; then
                    if docker container inspect "$container" >/dev/null 2>&1; then
                        container_exists='true'
                        container_state=$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || printf 'stopped')

                        state=$container_state
                        container_spec_hash=$(docker container inspect --format '{{index .Config.Labels "orbit.caddy.spec_hash"}}' "$container" 2>/dev/null || true)

                        if [ "$container_spec_hash" = "<no value>" ]; then
                            container_spec_hash=''
                        fi
                    else
                        container_exists='false'
                        container_state='missing'
                    fi
                fi

                if [ -n "$path" ]; then
                    installed='true'
                else
                    installed='false'
                fi

                printf '{"name":"%s","installed":%s,"path":%s,"version":%s,"state":%s,"container_exists":%s,"container_state":%s,"container_spec_hash":%s,"provider_reachable":%s,"provider_error":%s}\n' \
                    "$(json_escape "$name")" \
                    "$installed" \
                    "$(json_string_or_null "$path")" \
                    "$(json_string_or_null "$version")" \
                    "$(json_string_or_null "$state")" \
                    "$container_exists" \
                    "$(json_string_or_null "$container_state")" \
                    "$(json_string_or_null "$container_spec_hash")" \
                    "$provider_reachable" \
                    "$(json_string_or_null "$provider_error")"
            done <<'ORBIT_TOOLS'
            __NAMES__
            ORBIT_TOOLS
            SH;

        return strtr($script, [
            '__CASES__' => implode("\n", $cases),
            '__NAMES__' => implode("\n", array_keys($inputs)),
        ]);
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    public function parseMany(RemoteShellResult $result): array
    {
        $observations = [];

        $lines = preg_split('/\R/', trim($result->stdout));

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            /** @mago-expect analyzer:mixed-assignment */
            $payload = json_decode($line, associative: true);

            if (! is_array($payload) || ! is_string($payload['name'] ?? null)) {
                continue;
            }

            $name = $payload['name'];
            unset($payload['name']);

            $observations[$name] = $payload;
        }

        return $observations;
    }
}
