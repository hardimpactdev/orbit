<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

/** Shared resolver inspection and split-DNS preflight, without resolver changes. */
final class PeerDnsProgram
{
    public static function source(): string
    {
        return <<<'BASH'
            # Read resolved's configured servers, including global resolved.conf DNS=.
            # The private scope and its current/preceding server cannot supply public DNS.
            peer_dns_has_uplink() {
                local all_dns private_server=$1 previous_server=${2:-}
                all_dns=$(timeout --kill-after=2s 5s resolvectl dns) || return 1
                printf '%s\n' "$all_dns" | php -r '
                    [$private, $previous] = array_slice($argv, 1);
                    foreach (explode("\n", stream_get_contents(STDIN)) as $line) {
                        if (!preg_match("/^(?:Global|Link [0-9]+ \\(([^)]+)\\)): ?(.*)$/", $line, $match) || ($match[1] ?? "") === "orbit") { continue; }
                        foreach (preg_split("/\\s+/", trim($match[2])) as $server) {
                            $server = explode("#", $server, 2)[0];
                            $server = explode("%", $server, 2)[0];
                            if (filter_var($server, FILTER_VALIDATE_IP) !== false && $server !== $private && $server !== $previous && !str_starts_with($server, "127.") && !in_array($server, ["::1", "0.0.0.0", "::"], true)) { exit(0); }
                        }
                    }
                    exit(1);
                ' -- "$private_server" "$previous_server"
            }

            if [ "${1:-}" = inspect ]; then
                set -o pipefail
                export LC_ALL=C
                exec 9>> /run/lock/orbit-wireguard-peer.lock
                flock -s -w 5 9 || exit 41
                state=
                hooks=
                if [ -f /etc/wireguard/orbit.dns-link ]; then
                    state=$(cat -- /etc/wireguard/orbit.dns-link)
                fi
                if [ -f /etc/wireguard/orbit.conf ]; then
                    hooks=$(sed -n '/^PostUp = resolvectl/p' /etc/wireguard/orbit.conf)
                fi
                dns=$(resolvectl dns orbit)
                domains=$(resolvectl domain orbit)
                default_route=$(resolvectl default-route orbit)
                for value in "$state" "$hooks" "$dns" "$domains" "$default_route"; do
                    printf '%s' "$value" | base64 --wrap=0
                    printf '\n'
                done
                exit 0
            fi
            BASH;
    }
}
