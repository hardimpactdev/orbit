<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class NativeWireGuardPeerDnsRepairer implements WireGuardPeerDnsRepairer
{
    public function __construct(
        private VpnConfigurationRepository $configuration,
        private SshExecutor $ssh,
        private SshKeyProvider $sshKeys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function repair(Node $node): void
    {
        $policy = app(PeerDnsPolicy::class);
        if (! $policy->manages($node)) {
            throw new NodeProvisioningException('wireguard-peer-dns', 'vpn.peer_dns_unmanaged', "Node [{$node->name}] does not use the managed peer DNS policy.");
        }
        $vpn = $this->configuration->forPeer($node);
        $domains = $policy->domains($node);
        $dnsMode = 'wireguard';
        $result = $this->ssh->execute(
            new SshConnection(
                host: (string) $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->sshKeys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
            ),
            $this->command($dnsMode, $vpn->dnsServer, (string) $node->wireguard_public_key, $domains),
        );

        if ($result->succeeded()) {
            return;
        }

        [$step, $errorCode, $message] = match ($result->exitCode) {
            41 => [
                'wireguard-peer-lock',
                'vpn.peer_dns_busy',
                "Another WireGuard peer operation is active on node [{$node->name}].",
            ],
            42 => [
                'wireguard-peer-transaction',
                'vpn.peer_recovery_pending',
                "WireGuard peer recovery is still pending for node [{$node->name}].",
            ],
            47 => [
                'wireguard-peer-dns-uplink',
                'vpn.peer_dns_uplink_missing',
                "No usable non-Orbit DNS server is configured on node [{$node->name}].",
            ],
            43 => [
                'wireguard-peer-state',
                'vpn.peer_dns_state_unsupported',
                "The existing WireGuard DNS state is not repairable on node [{$node->name}].",
            ],
            44 => [
                'wireguard-peer-dns-candidate',
                'vpn.peer_dns_candidate_invalid',
                "Could not validate the repaired DNS configuration on node [{$node->name}].",
            ],
            45 => [
                'wireguard-peer-dns-apply',
                'vpn.peer_dns_apply_failed',
                "Could not apply repaired DNS configuration on node [{$node->name}].",
            ],
            46 => [
                'wireguard-peer-dns-recovery',
                'vpn.peer_dns_recovery_failed',
                "Could not restore the preceding DNS configuration on node [{$node->name}].",
            ],
            default => [
                'wireguard-peer-dns-repair',
                'vpn.peer_dns_repair_failed',
                "Could not repair DNS configuration on node [{$node->name}].",
            ],
        };

        throw new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message,
            result: $result,
        );
    }

    /** @param non-empty-list<string> $domains */
    private function command(string $dnsMode, string $dnsServer, string $publicKey, array $domains): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $dnsMode,
                $dnsServer,
                $publicKey,
            ],
            input: PeerDnsProgram::source($domains).PHP_EOL.str_replace(
                '__TRANSACTION_OWNER__',
                'dns-repair-v2',
                <<<'BASH_WRAP'
                dns_mode=$1
                dns_server=$2
                expected_public_key=$3

                case "$dns_mode" in
                    wireguard) ;;
                    *) exit 43 ;;
                esac
                if [[ ! "$dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                    || [[ ! "$expected_public_key" =~ ^[A-Za-z0-9+/]{43}=$ ]] \
                    || [ "${#dns_domains[@]}" -lt 1 ] \
                    || [ "${#dns_domains[@]}" -gt 512 ]; then
                    exit 43
                fi
                for dns_domain in "${dns_domains[@]}"; do
                    if [[ ! "$dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                        exit 43
                    fi
                done
                if [[ " ${dns_domains[*]} " = *' . '* ]] && [ "${#dns_domains[@]}" -ne 1 ]; then
                    exit 43
                fi

                exec 9>/run/lock/orbit-wireguard-peer.lock
                if ! flock -w 30 9; then
                    exit 41
                fi

                live=/etc/wireguard/orbit.conf
                candidate=/etc/wireguard/orbit-candidate.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                restore_candidate=/etc/wireguard/.orbit.conf.restore
                dns_state=/etc/wireguard/orbit.dns-link
                dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                dns_state_backup=/etc/wireguard/.orbit.dns-link.rollback
                dns_restore_candidate=/etc/wireguard/.orbit.dns-link.restore
                transaction=/etc/wireguard/.orbit.peer-transaction
                transaction_candidate=/etc/wireguard/.orbit.peer-transaction.candidate
                transaction_owner=__TRANSACTION_OWNER__

                read_dns_state() {
                    local path=$1
                    if [ ! -s "$path" ]; then
                        return 1
                    fi

                    mapfile -t parsed_dns_state < "$path"
                    parsed_dns_link=${parsed_dns_state[0]:-}
                    parsed_dns_server=${parsed_dns_state[1]:-}
                    parsed_dns_domains=("${parsed_dns_state[@]:2}")
                    if [[ ! "$parsed_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                        || [[ ! "$parsed_dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                        || [ "${#parsed_dns_domains[@]}" -lt 1 ] \
                        || [ "${#parsed_dns_domains[@]}" -gt 1024 ]; then
                        return 1
                    fi
                    for parsed_dns_domain in "${parsed_dns_domains[@]}"; do
                        if [[ ! "$parsed_dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                            return 1
                        fi
                    done
                }

                cleanup_transaction() {
                    rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                        "$restore_candidate" "$dns_restore_candidate" || return 1
                    rm -f -- "$backup" "$dns_state_backup" || return 1
                    rm -f -- "$transaction" || return 1
                    if [ -e "$backup" ] || [ -L "$backup" ] \
                        || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                        || [ -e "$transaction" ] || [ -L "$transaction" ]; then
                        return 1
                    fi
                }

                recover_transaction() {
                    if [ ! -f "$backup" ] || [ -L "$backup" ] \
                        || { [ ! -f "$dns_state_backup" ] && [ ! -L "$dns_state_backup" ]; } \
                        || ! read_dns_state "$dns_state_backup"; then
                        return 1
                    fi

                    old_dns_link=$parsed_dns_link
                    old_dns_server=$parsed_dns_server
                    old_dns_domains=("${parsed_dns_domains[@]}")
                    current_dns_link=
                    if read_dns_state "$dns_state"; then
                        current_dns_link=$parsed_dns_link
                    fi

                    recovery_failed=0
                    if [[ "$current_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]]; then
                        resolvectl dns "$current_dns_link" '' || recovery_failed=1
                        resolvectl domain "$current_dns_link" '' || recovery_failed=1
                    fi
                    rm -f -- "$restore_candidate" "$dns_restore_candidate" || recovery_failed=1
                    cp -a --no-dereference -- "$backup" "$restore_candidate" || recovery_failed=1
                    if [ -e "$restore_candidate" ] || [ -L "$restore_candidate" ]; then
                        mv -fT -- "$restore_candidate" "$live" || recovery_failed=1
                    fi
                    cp -a --no-dereference -- "$dns_state_backup" "$dns_restore_candidate" || recovery_failed=1
                    if [ -e "$dns_restore_candidate" ] || [ -L "$dns_restore_candidate" ]; then
                        mv -fT -- "$dns_restore_candidate" "$dns_state" || recovery_failed=1
                    fi
                    resolvectl dns "$old_dns_link" "$old_dns_server" || recovery_failed=1
                    old_resolvectl_domains=()
                    for old_dns_domain in "${old_dns_domains[@]}"; do
                        old_resolvectl_domains+=("~$old_dns_domain")
                    done
                    resolvectl domain "$old_dns_link" "${old_resolvectl_domains[@]}" || recovery_failed=1
                    if [ "$recovery_failed" -ne 0 ]; then
                        return 1
                    fi

                    uplink_snapshot=$(sed -n '3p' "$transaction")
                    peer_dns_restore_uplink all || return 1
                    old_default_route=$(sed -n '2p' "$transaction")
                    case "$old_default_route" in yes|no) ;; *) return 1 ;; esac
                    resolvectl default-route orbit "$old_default_route" || return 1
                    cleanup_transaction
                }

                if [ -e "$transaction" ] || [ -L "$transaction" ]; then
                    if [ ! -f "$transaction" ] || [ -L "$transaction" ] \
                        || [ "$(head -n 1 "$transaction")" != "$transaction_owner" ]; then
                        exit 42
                    fi
                    if ! recover_transaction; then
                        exit 46
                    fi
                elif [ -e "$candidate" ] || [ -L "$candidate" ] \
                    || [ -e "$backup" ] || [ -L "$backup" ] \
                    || [ -e "$dns_state_candidate" ] || [ -L "$dns_state_candidate" ] \
                    || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                    || [ -e "$transaction_candidate" ] || [ -L "$transaction_candidate" ] \
                    || [ -e "$restore_candidate" ] || [ -L "$restore_candidate" ] \
                    || [ -e "$dns_restore_candidate" ] || [ -L "$dns_restore_candidate" ]; then
                    exit 42
                fi

                if [ ! -f "$live" ] || [ -L "$live" ] \
                    || { [ ! -f "$dns_state" ] && [ ! -L "$dns_state" ]; } \
                    || ! read_dns_state "$dns_state" \
                    || ! systemctl is-active --quiet wg-quick@orbit \
                    || ! active_public_key=$(wg show orbit public-key) \
                    || [ "$active_public_key" != "$expected_public_key" ]; then
                    exit 43
                fi
                old_dns_link=$parsed_dns_link
                old_dns_server=$parsed_dns_server
                old_dns_domains=("${parsed_dns_domains[@]}")

                dns_link=orbit

                resolvectl_domains=()
                persistent_domains=()
                for dns_domain in "${dns_domains[@]}"; do
                    resolvectl_domains+=("~$dns_domain")
                    printf -v dns_domain_escaped '%q' "~$dns_domain"
                    persistent_domains+=("$dns_domain_escaped")
                done
                printf -v dns_server_escaped '%q' "$dns_server"
                dns_hooks=
                if [ "$dns_mode" = wireguard ]; then
                    dns_hooks="PostUp = resolvectl dns %i $dns_server_escaped; resolvectl domain %i ${persistent_domains[*]}"$'\n'
                fi

                if [ "$old_dns_link" != orbit ] && [ "$dns_link" = orbit ]; then
                    network_state=$(timeout --kill-after=5s 10s networkctl --no-legend --no-pager --full list "$old_dns_link") || exit 43
                    read -r network_index network_name network_type network_operational network_setup <<< "$network_state"
                    [ "$network_name" = "$old_dns_link" ] && [ "$network_setup" = configured ] || exit 43
                fi

                if [ "$old_dns_link" = orbit ] && ! peer_dns_has_uplink "$dns_server" "${old_dns_server:-}"; then
                    echo 'No non-Orbit per-link or global DNS server is configured.' >&2
                    exit 47
                fi

                if ! printf '%s' "$dns_hooks" | awk -v old_link="$old_dns_link" '
                    FILENAME == "-" { replacement = replacement $0 ORS; next }
                    { lines[++count] = $0 }
                    END {
                        for (i = 1; i <= count; i++) {
                            if (lines[i] ~ /^PostUp = resolvectl dns %i [^;]+; resolvectl domain %i .+\r?$/) hooks++
                            if (lines[i] ~ /^\[Interface\]\r?$/) headers++
                        }
                        if (hooks > 1 || (hooks == 0 && (old_link == "orbit" || headers != 1))) exit 1
                        for (i = 1; i <= count; i++) {
                            if (lines[i] ~ /^PostUp = resolvectl dns %i [^;]+; resolvectl domain %i .+\r?$/) {
                                printf "%s", replacement
                                if (lines[i+1] ~ /^PreDown = resolvectl revert %i\r?$/ || lines[i+1] ~ /^PreDown = resolvectl dns %i \047\047; resolvectl domain %i \047\047\r?$/) i++
                            } else {
                                print lines[i]
                                if (hooks == 0 && lines[i] ~ /^\[Interface\]\r?$/) printf "%s", replacement
                            }
                        }
                    }
                ' - "$live" > "$candidate"; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                chown root:root "$candidate"
                chmod 0600 "$candidate"
                if ! wg-quick strip "$candidate" >/dev/null; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                if ! printf '%s\n' "$dns_link" "$dns_server" "${dns_domains[@]}" > "$dns_state_candidate"; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                chown root:root "$dns_state_candidate"
                chmod 0600 "$dns_state_candidate"

                uplink_snapshot=
                if [ "$old_dns_link" != orbit ]; then
                    peer_dns_capture_uplink "$old_dns_link" || exit 43
                fi
                old_default_route=$(resolvectl default-route orbit)
                old_default_route=${old_default_route##*: }
                case "$old_default_route" in yes|no) ;; *) exit 43 ;; esac
                if ! cp -a --no-dereference -- "$live" "$backup" \
                    || ! cp -a --no-dereference -- "$dns_state" "$dns_state_backup" \
                    || ! printf '%s\n%s\n%s\n' "$transaction_owner" "$old_default_route" "$uplink_snapshot" > "$transaction_candidate" \
                    || ! chmod 0600 "$transaction_candidate" \
                    || ! mv -fT -- "$transaction_candidate" "$transaction"; then
                    rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                        "$backup" "$dns_state_backup" "$transaction"
                    exit 44
                fi

                publication_failed=0
                mv -fT -- "$candidate" "$live" || publication_failed=1
                if [ "$publication_failed" -eq 0 ]; then
                    mv -fT -- "$dns_state_candidate" "$dns_state" || publication_failed=1
                fi
                if [ "$publication_failed" -ne 0 ]; then
                    if recover_transaction; then
                        exit 45
                    fi

                    exit 46
                fi

                uplink_failed=0
                apply_failed=0
                if [ "$old_dns_link" != "$dns_link" ]; then
                    resolvectl revert "$old_dns_link" || apply_failed=1
                    peer_dns_restore_uplink protocols || apply_failed=1
                    if [ "$old_dns_link" != orbit ] && [ "$dns_link" = orbit ]; then
                        timeout --kill-after=5s 15s networkctl reconfigure "$old_dns_link" || apply_failed=1
                        provider_usable=0
                        provider_deadline=$((SECONDS + 15))
                        while [ "$apply_failed" = 0 ] && [ "$SECONDS" -lt "$provider_deadline" ]; do
                            network_state=$(timeout --kill-after=2s 2s networkctl --no-legend --no-pager --full list "$old_dns_link") || network_state=
                            read -r network_index network_name network_type network_operational network_setup <<< "$network_state"
                            if peer_dns_has_uplink "$dns_server" "${old_dns_server:-}" && [ "$network_name" = "$old_dns_link" ] && [ "$network_setup" = configured ]; then
                                provider_usable=1
                            fi
                            [ "$provider_usable" = 0 ] || break
                            sleep 0.25
                        done
                        if [ "$provider_usable" != 1 ] || ! peer_dns_has_uplink "$dns_server" "${old_dns_server:-}"; then
                            uplink_failed=1
                            apply_failed=1
                        fi
                    fi
                fi
                if [ "$uplink_failed" != 0 ]; then
                    recover_transaction || exit 46
                    exit 47
                fi
                resolvectl dns "$dns_link" "$dns_server" || apply_failed=1
                resolvectl domain "$dns_link" "${resolvectl_domains[@]}" || apply_failed=1
                if [ "$dns_link" = orbit ]; then
                    resolvectl default-route orbit no || apply_failed=1
                fi
                if ! active_public_key=$(wg show orbit public-key) \
                    || [ "$active_public_key" != "$expected_public_key" ]; then
                    apply_failed=1
                fi

                if [ "$apply_failed" -ne 0 ]; then
                    if recover_transaction; then
                        exit 45
                    fi

                    exit 46
                fi

                if ! cleanup_transaction; then
                    exit 46
                fi
                BASH_WRAP,
            ),
            maxOutputBytes: 4096,
        );
    }
}
