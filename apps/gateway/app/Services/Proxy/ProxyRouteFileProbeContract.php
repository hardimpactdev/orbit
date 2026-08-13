<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\RemoteShell\RemoteShellResult;

final readonly class ProxyRouteFileProbeContract
{
    public function __construct(
        private ProxyCertificateValidity $certificateValidity = new ProxyCertificateValidity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(RemoteShellResult $result): array
    {
        if (! $result->successful()) {
            return $this->failedObservation(
                "Proxy route probe command failed with exit code {$result->exitCode}.",
                'command_failed',
            );
        }

        $parts = explode("\t", rtrim(string: $result->stdout, characters: "\r\n"), limit: 11);

        if (count($parts) !== 11) {
            return $this->failedObservation('Proxy route probe returned an invalid reply.');
        }

        [
            $status,
            $exists,
            $hash,
            $cert,
            $key,
            $certExists,
            $keyExists,
            $runtimeReachable,
            $runtimeError,
            $certProbeAttempted,
            $certPem,
        ] = $parts;

        if ($status === 'runtime_unavailable') {
            return $this->failedObservation(
                'orbit-caddy is not running, so proxy route reality could not be inspected.',
                $status,
            );
        }

        if (
            $status !== 'observed'
            || ! $this->isBooleanField($exists)
            || ! $this->isBooleanField($certExists)
            || ! $this->isBooleanField($keyExists)
            || ! $this->isBooleanField($certProbeAttempted)
            || ! in_array($runtimeReachable, ['', '0', '1'], strict: true)
        ) {
            return $this->failedObservation('Proxy route probe returned an invalid reply.');
        }

        $decodedRuntimeError = $runtimeError === '' ? null : base64_decode($runtimeError, strict: true);

        if ($decodedRuntimeError === false) {
            return $this->failedObservation('Proxy route probe returned an invalid runtime diagnostic.');
        }

        return [
            'route_probe_ok' => true,
            'route_probe_status' => $status,
            'route_exists' => $exists === '1',
            'route_hash' => $hash,
            'cert_path' => $cert,
            'key_path' => $key,
            'cert_exists' => $certExists === '1',
            'key_exists' => $keyExists === '1',
            'cert_validity_observed' => $certProbeAttempted === '1',
            'cert_validity_days' => $this->certificateValidity->days($certPem),
            'runtime_upstream_reachable' => $runtimeReachable === '' ? null : $runtimeReachable === '1',
            'runtime_probe_error' => $decodedRuntimeError,
        ];
    }

    public function render(string $domain, string $routeSuffix, ?string $runtimeUpstream): string
    {
        $script = <<<'BASH'
            set -euo pipefail
            domain="$ORBIT_PROXY_DOMAIN"
            suffix="${ORBIT_PROXY_SUFFIX:-}"
            upstream="${ORBIT_PROXY_RUNTIME_UPSTREAM:-}"
            path="/etc/caddy/sites/${domain}${suffix}.caddy"

            if [ "$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' orbit-caddy 2>/dev/null || true)" != "running" ]; then
                printf 'runtime_unavailable\t\t\t\t\t\t\t\t\t\t\n'
                exit 0
            fi

            docker exec orbit-caddy sh -c '
                path="$1"
                upstream="$2"
                exists=0
                hash=""
                cert=""
                key=""
                cert_exists=0
                key_exists=0
                runtime_reachable=""
                runtime_error=""
                cert_probe_attempted=0
                cert_pem=""

                if [ -f "$path" ]; then
                    exists=1
                    hash=$(sha256sum "$path" | cut -d " " -f 1)
                    tls_line=$(grep -m 1 -E "^[[:space:]]*tls[[:space:]]+" "$path" || true)
                    if [ -n "$tls_line" ]; then
                        set -- $tls_line
                        if [ "${1:-}" = "tls" ] && [ "${2:-}" != "internal" ]; then
                            cert="${2:-}"
                            key="${3:-}"
                        fi
                    fi
                    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
                    [ -n "$key" ] && [ -f "$key" ] && key_exists=1

                    if [ "$cert_exists" = "1" ]; then
                        cert_probe_attempted=1
                        cert_pem=$(base64 < "$cert" | tr -d "\n")
                    fi

                    if [ -n "$upstream" ]; then
                        if command -v curl >/dev/null 2>&1; then
                            case "$upstream" in
                                https://*)
                                    rest="${upstream#https://}"
                                    authority="${rest%%/*}"
                                    path="/"
                                    if [ "$rest" != "$authority" ]; then
                                        path="/${rest#*/}"
                                    fi
                                    host="${authority%%:*}"
                                    port="443"
                                    if [ "$authority" != "$host" ]; then
                                        port="${authority##*:}"
                                    fi

                                    probe_output=$(curl -k -sS --connect-timeout 3 --max-time 8 -o /dev/null -w "HTTP/%{http_version} %{http_code}" --connect-to "$domain:$port:$host:$port" "https://$domain:$port$path" 2>&1 || true)
                                    ;;
                                *)
                                    probe_output=$(curl -sS --connect-timeout 3 --max-time 8 -o /dev/null -w "HTTP/%{http_version} %{http_code}" "$upstream" 2>&1 || true)
                                    ;;
                            esac
                        else
                            probe_output=$(wget -S -O /dev/null -T 3 "$upstream" 2>&1 || true)
                        fi

                        case "$probe_output" in
                            *HTTP/*) runtime_reachable=1 ;;
                            *) runtime_reachable=0 ;;
                        esac

                        runtime_error=$(printf "%s" "$probe_output" | tail -n 1 | base64 | tr -d "\n")
                    fi
                fi

                printf "observed\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n" "$exists" "$hash" "$cert" "$key" "$cert_exists" "$key_exists" "$runtime_reachable" "$runtime_error" "$cert_probe_attempted" "$cert_pem"
            ' sh "$path" "$upstream"
            BASH;

        return sprintf(
            "export ORBIT_PROXY_DOMAIN=%s ORBIT_PROXY_SUFFIX=%s ORBIT_PROXY_RUNTIME_UPSTREAM=%s\n%s",
            escapeshellarg($domain),
            escapeshellarg($routeSuffix),
            escapeshellarg($runtimeUpstream ?? ''),
            $script,
        );
    }

    private function isBooleanField(string $value): bool
    {
        return in_array($value, ['0', '1'], strict: true);
    }

    /**
     * @return array{route_probe_ok: false, route_probe_status: string, route_probe_error: string}
     */
    private function failedObservation(string $error, string $status = 'invalid_reply'): array
    {
        return [
            'route_probe_ok' => false,
            'route_probe_status' => $status,
            'route_probe_error' => $error,
        ];
    }
}
