<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\RemoteShell\RemoteShellResult;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class ToolCapabilityProbeContract
{
    /** @var list<string> */
    private const array NULLABLE_STRING_FIELDS = [
        'path',
        'version',
        'state',
        'container_state',
        'container_spec_hash',
        'provider_error',
    ];

    /** @var list<string> */
    private const array NULLABLE_BOOLEAN_FIELDS = [
        'container_exists',
        'provider_reachable',
    ];

    public function renderOne(string $name, ToolCapabilityProbeInput $input): string
    {
        return $this->render([$name => $input], '# orbit-tool-probe:capability');
    }

    /**
     * @return array<string, mixed>
     */
    public function parseOne(string $name, RemoteShellResult $result): array
    {
        return $this->parseMany($result, [$name])[$name];
    }

    /**
     * @param  array<string, ToolCapabilityProbeInput>  $inputs
     */
    public function renderMany(array $inputs): string
    {
        return $this->render($inputs, '# orbit-tool-probe:capability-batch');
    }

    /**
     * @param  list<string>  $requestedNames
     * @return array<string, array<string, mixed>>
     */
    public function parseMany(RemoteShellResult $result, array $requestedNames): array
    {
        if ($requestedNames === []) {
            return [];
        }

        if (! $result->successful()) {
            return $this->failedObservations(
                $requestedNames,
                "Capability probe command failed with exit code {$result->exitCode}.",
            );
        }

        $stdout = rtrim($result->stdout, "\r\n");

        if (trim($stdout) === '') {
            return $this->failedObservations($requestedNames, 'Capability probe returned no observation.');
        }

        $lines = preg_split('/\R/', $stdout);

        if ($lines === false) {
            return $this->failedObservations($requestedNames, 'Capability probe reply could not be split into lines.');
        }

        $observations = [];
        $claimedNames = [];

        foreach ($lines as $index => $line) {
            $expectedName = $requestedNames[$index] ?? null;

            try {
                /** @var mixed $payload */
                $payload = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->attributeFailure(
                    $observations,
                    $claimedNames,
                    $expectedName,
                    'Capability probe returned malformed JSON.',
                );

                continue;
            }

            if (! is_array($payload)) {
                $this->attributeFailure(
                    $observations,
                    $claimedNames,
                    $expectedName,
                    'Capability probe reply is not an object.',
                );

                continue;
            }

            if (
                ! is_string($payload['name'] ?? null)
                || ! in_array($payload['name'], $requestedNames, strict: true)
            ) {
                $this->attributeFailure(
                    $observations,
                    $claimedNames,
                    $expectedName,
                    'Capability probe reply identified an unexpected tool.',
                );

                continue;
            }

            $name = $payload['name'];

            if (isset($claimedNames[$name])) {
                $observations[$name] = $this->failedObservation('Capability probe returned duplicate observations.');

                continue;
            }

            $claimedNames[$name] = true;
            $error = $this->validationError($payload);

            if ($error !== null) {
                $observations[$name] = $this->failedObservation($error);

                continue;
            }

            unset($payload['name']);
            $observations[$name] = [
                'capability_probe_ok' => true,
                ...$payload,
            ];
        }

        foreach ($requestedNames as $name) {
            $observations[$name] ??= $this->failedObservation('Capability probe returned no observation.');
        }

        return array_combine(
            $requestedNames,
            array_map(static fn (string $name): array => $observations[$name], $requestedNames),
        );
    }

    /**
     * @param  array<string, ToolCapabilityProbeInput>  $inputs
     */
    private function render(array $inputs, string $marker): string
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
            __MARKER__

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
            '__MARKER__' => $marker,
            '__CASES__' => implode("\n", $cases),
            '__NAMES__' => implode("\n", array_keys($inputs)),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function validationError(array $payload): ?string
    {
        if (! is_bool($payload['installed'] ?? null)) {
            return 'Capability probe reply has an invalid installed field.';
        }

        foreach (self::NULLABLE_STRING_FIELDS as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field]) && $payload[$field] !== null) {
                return "Capability probe reply has an invalid {$field} field.";
            }
        }

        foreach (self::NULLABLE_BOOLEAN_FIELDS as $field) {
            if (array_key_exists($field, $payload) && ! is_bool($payload[$field]) && $payload[$field] !== null) {
                return "Capability probe reply has an invalid {$field} field.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $observations
     * @param  array<string, true>  $claimedNames
     */
    private function attributeFailure(
        array &$observations,
        array &$claimedNames,
        ?string $expectedName,
        string $error,
    ): void {
        if ($expectedName === null || isset($claimedNames[$expectedName])) {
            return;
        }

        $claimedNames[$expectedName] = true;
        $observations[$expectedName] = $this->failedObservation($error);
    }

    /**
     * @param  list<string>  $requestedNames
     * @return array<string, array<string, mixed>>
     */
    private function failedObservations(array $requestedNames, string $error): array
    {
        return array_combine(
            $requestedNames,
            array_map(fn (): array => $this->failedObservation($error), $requestedNames),
        );
    }

    /**
     * @return array{capability_probe_ok: false, capability_probe_error: string}
     */
    private function failedObservation(string $error): array
    {
        return [
            'capability_probe_ok' => false,
            'capability_probe_error' => $error,
        ];
    }
}
