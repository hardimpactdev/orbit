# Firewall address-family retained Incus proof

- Candidate: `54367677b9cc2f78cd303d9da1ed5ee812aafec4`
- Venue: retained Incus
- Topology: `dev-ccc4b0` (`operator_gateway_app-dev`)
- Host: `beast` through LAN address `192.168.6.20`
- Solo terminal: `firewall-incus-proof` (`2294`)
- Runtime checkout: `/home/orbit/orbit-run`
- Target: `app-dev-1` (`10.6.0.4`)

The runtime checkout matched the candidate files:

```text
af823e1e1deba2ee72876a63a9939f6e59294d57f5312029a1a71cf28aca5fa0  apps/gateway/app/Services/Firewall/FirewallRuleShapeCanonicalizer.php
c6cd10b9c72bd4d6e641e396ff5d684ce832a3efc9a8242c530986b5a1b67277  apps/gateway/app/Services/Firewall/FirewallRuleProbe.php
828c56cb79a590e43efacbe24bfc4ee9b1909342e6147f528323c8c0ad8012af  apps/gateway/app/Services/Convergence/UfwFirewallRule.php
```

The prepared app-node image did not contain the host-side `/usr/local/bin/orbit-cli` launcher. The proof linked that disposable fixture path to the source-mounted CLI at `/home/orbit/orbit-run/apps/cli/orbit`. No repository or live-node state was changed.

## Operator flow

```text
$ orbit firewall:allow proof-cidr-family --node=app-dev-1 --port=49283 --from=10.6.0.0/24 --reason=stabilization-proof --json
action=created
backend_enacted=true
stored address_family=both

$ ssh orbit@10.6.0.4 'sudo ufw show added | grep 49283'
ufw allow from 10.6.0.0/24 to any port 49283 proto tcp comment 'stabilization-proof'

$ orbit doctor --node=app-dev-1 --family=firewall_rule --json
healthy=true
issues=0
genuine_drift=0

$ orbit firewall:allow proof-cidr-family --node=app-dev-1 --port=49283 --from=10.6.0.0/24 --reason=stabilization-proof --json
action=converged
backend_enacted=true

$ orbit doctor --node=app-dev-1 --family=firewall_rule --json
healthy=true
issues=0
genuine_drift=0

$ orbit firewall:remove proof-cidr-family --node=app-dev-1 --force --json
backend_removed=true

$ ssh orbit@10.6.0.4 'sudo ufw show added | grep 49283 || echo UFW_RULE_REMOVED'
UFW_RULE_REMOVED

$ orbit firewall:list --node=app-dev-1 --json
rules=[]
count=0
```

Result: Orbit applied the IPv4 CIDR rule from stored `both` intent, Doctor recognized the concrete UFW rule as equivalent, a second apply stayed converged, and cleanup removed both backend and gateway state.
