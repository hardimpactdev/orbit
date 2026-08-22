# Retained Incus proof: versioned Ubuntu firewall eligibility

- Candidate: `0499352501b627146956cffc528a093a51876f44`
- Environment: `dev-fixture`
- Provider host: Beast through the existing Beast SSH identity over
  `10.6.0.7`
- Topology: `dev-ed0062` (`operator_gateway_app-dev`)
- Runtime checkout: `/home/orbit/orbit-run`
- Proof window: `feat-codex-fix-firewall-versioned-ubuntu:proof-3`
- Candidate file hashes matched the local worktree:
  - `FirewallRuleQuery.php`:
    `f7b0cbe14e1f96694d1a70f5b14aca6d18bad1a5204dbaebd95d9e178ce63d3d`
  - `FirewallRuleIntent.php`:
    `b15bc5ee34ed653f56314b96ff02215a5589e19b09fd8c9f0370ead9164c1524`
  - `FirewallTargetPlatform.php`:
    `71a4e1dc7b8ac8edd8e1b8e423878086d22234c5b9e647a5095168b3f50f4858`

The disposable gateway fixture was updated so `node:list --json` reported the
active, role-bearing target `app-dev-1` with platform `ubuntu_24-04`.

The proof window ran the source-mounted launcher and exercised the public CLI:

1. `firewall:allow` created `retained-versioned-ubuntu` for `app-dev-1`, source
   `10.6.0.3/32`, port `22422/tcp`; result: `action=created`,
   `backend_enacted=true`.
2. The same command repeated; result: `action=converged`,
   `backend_enacted=true`.
3. `firewall:list --node=app-dev-1 --json` returned exactly one matching rule.
4. `doctor --node=app-dev-1 --family=firewall_rule --stream-json` completed
   healthy with zero issues.
5. `firewall:remove ... --force --json` returned `backend_removed=true`.
6. A final scoped list returned zero rules, and a final firewall doctor run
   completed healthy with zero issues.

An exploratory rule with an explicit destination CIDR enacted successfully,
but the existing UFW probe parsed its destination as null and reported drift.
That parser behavior is outside this eligibility candidate. The accepted proof
used the normal node-scoped shape without an explicit destination and converged.
