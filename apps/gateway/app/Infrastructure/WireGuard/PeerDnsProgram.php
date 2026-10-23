<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

/**
 * The split-DNS preflight and uplink migration state of peer convergence, which runs over the bootstrap SSH path
 * before the tunnel exists. The agent's `wireguard.repair-peer-dns` keeps the same uplink snapshot format.
 */
final class PeerDnsProgram
{
    /** @param list<string> $domains */
    public static function source(array $domains = []): string
    {
        $source = <<<'BASH'
            export LC_ALL=C
            # Read resolved's configured servers, including global resolved.conf DNS=.
            # The private scope and its current/preceding server cannot supply public DNS.
            peer_dns_has_uplink() {
                local all_dns private_server=$1 previous_server=${2:-}
                all_dns=$(timeout --kill-after=2s 5s resolvectl dns) || return 1
                printf '%s\n' "$all_dns" | awk -v private="$private_server" -v previous="$previous_server" '
                    function usable(server, octets, count, i) {
                        sub(/[%#].*$/, "", server)
                        if (server == private || server == previous || server == "::1" || server == "::" || server == "0.0.0.0") return 0
                        if (server ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) {
                            count = split(server, octets, ".")
                            for (i = 1; i <= count; i++) if (octets[i] > 255) return 0
                            return octets[1] != 127
                        }
                        return server ~ /:/ && server ~ /^[0-9a-fA-F:.]+$/
                    }
                    /^Link [0-9]+ \(orbit\):/ { next }
                    /^(Global|Link [0-9]+ \([^)]+\)): ?/ {
                        sub(/^[^:]+: */, "")
                        for (i = 1; i <= NF; i++) if (usable($i)) found = 1
                    }
                    END { exit !found }
                '
            }

            # One encoded line fits either durable transaction format without shell evaluation.
            peer_dns_capture_uplink() {
                local link=$1 setting value snapshot
                [[ "$link" =~ ^[A-Za-z0-9_.:+-]+$ ]] && [ "$link" != orbit ] || return 1
                snapshot=$(printf '%s\n' "$link"
                    for setting in dns domain default-route llmnr mdns dnssec dnsovertls nta; do
                        value=$(resolvectl "$setting" "$link") || exit 1
                        value=${value#*:}
                        value=${value#"${value%%[![:space:]]*}"}
                        printf 'v:'
                        printf '%s\n' "$value" | base64 --wrap=0
                        printf '\n'
                    done) || return 1
                uplink_snapshot=$(printf '%s\n' "$snapshot" | base64 --wrap=0)
            }
            peer_dns_restore_uplink() {
                local mode=$1 snapshot link setting value index
                local -a fields values
                [ -n "${uplink_snapshot:-}" ] || return 0
                snapshot=$(printf '%s' "$uplink_snapshot" | base64 --decode) || return 1
                mapfile -t fields <<< "$snapshot"
                [ "${#fields[@]}" -eq 9 ] || return 1
                link=${fields[0]}
                [[ "$link" =~ ^[A-Za-z0-9_.:+-]+$ ]] && [ "$link" != orbit ] || return 1
                index=1
                for setting in dns domain default-route llmnr mdns dnssec dnsovertls nta; do
                    value=$(printf '%s' "${fields[index]#v:}" | base64 --decode) || return 1
                    index=$((index + 1))
                    if [ "$mode" = protocols ]; then
                        case "$setting" in dns|domain|default-route) continue ;; esac
                    fi
                    read -r -a values <<< "${value//$'\n'/ }"
                    if [ "${#values[@]}" -eq 0 ]; then values=(''); fi
                    resolvectl "$setting" "$link" "${values[@]}" || return 1
                done
            }
            BASH;

        return $domains === [] ? $source : $source.PHP_EOL.'dns_domains=('.implode(' ', array_map(escapeshellarg(...), $domains)).')'.PHP_EOL;
    }
}
