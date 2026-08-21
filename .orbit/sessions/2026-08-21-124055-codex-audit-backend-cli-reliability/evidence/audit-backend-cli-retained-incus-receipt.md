# Backend and CLI audit retained-Incus receipt

candidate=`6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc`
git_tree_clean=true
venue=retained-incus
environment=dev-fixture
topology_id=`dev-9422d7`
topology_kind=`operator_gateway_app-dev`
topology_host=`beast`
instances=operator `orbit-e2e-dev-9422d7-operator`, gateway `orbit-e2e-dev-9422d7-gateway`, dev `orbit-e2e-dev-9422d7-dev`
checkout_roles=gateway,dev
checkout_path=`/home/orbit/orbit-run`
solo_terminal=`2642` (`retained-incus-proof-vm-dev-9422d7`), attached with a
TTY to `orbit-e2e-dev-9422d7-gateway`; UFW node execution used Solo terminal
`2640`
quality_receipt=`.orbit/quality-gates/quality-check-2026-08-21T102004Z-fdb3f7bb64b8.json` (commit `6db5ad6d`, dirty=false, exit=0, all 45 subgates zero)

## Candidate binding

The VM reported hostname `orbit-e2e-dev-9422d7-gateway`, OS `Linux`, and
checkout `/home/orbit/orbit-run`. SHA-256 digests matched
the feature worktree for all four behavior-changing runtime files:

- `UpdateLeaseManager.php`: `d151c4b1e941a3a380d90e851ff39bf408253859d49453416af76410f073f7a7`
- `UfwFirewallRule.php`: `bf06d3b125de71f86b3060743e14eb1a9b38936a928b16cd467e2aa7cd64e7a9`
- `LocalResolver.php`: `85331f04a0562fbc7e7c61a0495048a1af52002e41bfac53d9375c1dda7de3b7`
- `bin/install-orbit`: `d60206a35097eaef384939f3f169f1d1b1e48de8751d7fb891949498df36a5f9`

## Runtime matrix

| Surface | Exercise | Observed | Result |
| --- | --- | --- | --- |
| Candidate launcher | Run `./apps/cli/orbit --version` and `php apps/gateway/artisan --version` from the mounted checkout | Both reported Orbit `0.1.196` | PASS |
| Gateway and CLI | From the VM checkout, run `./apps/cli/orbit node:list --json`, request `http://10.6.0.2/api/ca/root`, and run `orbit doctor --node=app-dev-1 --family=process --json` | JSON listed operator, gateway, and active app-dev node; CA endpoint returned HTTP 200 in 0.077501 seconds; Doctor completed healthy with zero issues and exit code 0 | PASS |
| Update lease | Against `/home/orbit/.config/orbit/gateway.sqlite`, create a real queued operation row; acquire a lease; reject a wrong-owner heartbeat; renew with the correct owner; release; repeat release with a different token to prove inactive-release idempotency; delete the proof rows | `LEASE_HEARTBEAT_OWNER_IDEMPOTENT_RELEASE=PASS`; follow-up `LEASE_PROOF_ROWS=0` | PASS |
| Installer archive forwarding | Source the mounted installer with only `WG_EASY_IMAGE_ARCHIVE` set and a temporary gateway env file | Env file contained `ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES=1`; exported value was `1`; temporary files removed | PASS |
| UFW apply/delete parity | Generate apply/delete commands from the mounted `UfwFirewallRule`; apply the harmless TCP/65534 rule to the dev node, inspect it with `ufw show added`, execute the matching delete body, and inspect again | Apply and delete shared the normalized `10.255.255.254` → `0.0.0.0/0` body; add reported `Rules updated`; final added-rule list was `(None)` | PASS |

The macOS-only resolver write/cache-flush path cannot run on Linux Incus. Its
process count, failure contract, and changed-state behavior are covered by the
focused CLI Pest suite and the full candidate-bound quality receipt.

No `composer test:e2e*` command ran. The topology command only acquired the
retained source-mounted fixture. The UFW proof rule and lease rows were removed.

result=passed
