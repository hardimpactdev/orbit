# Firewall Doctor

[Back to Firewall commands.](README.md)

`doctor --family=firewall_rule` verifies whether gateway firewall configuration still matches node firewall reality. It covers firewall rules that Orbit owns.

The firewall family owns these facts:

- firewall rule rows owned by the gateway: node, name, direction, action, source, destination, protocol, port, reason, and backend metadata needed to identify the applied rule;
- managed backend rules rendered from those rows;
- drift between gateway configuration and the node firewall backend;
- adoption facts for explicitly selected observed rules that can safely become Orbit-owned firewall configuration.

Node reachability belongs to `node`. Proxy routes, apps, workspaces, processes, schedules, and tools remain outside the firewall family even when their capabilities depend on firewall policy.

## Probe Layers

The firewall probe reads gateway firewall rules and checks these layers:

1. **Registry configuration:** every selected rule has a valid node reference, rule name, direction, action, source, protocol, and port.
2. **Node eligibility:** the node reference resolves to a visible active Ubuntu node with role `gateway` or `app`.
3. **Baseline policy boundary:** the rule does not attempt to mutate node bootstrap policy owned by the node family.
4. **Backend presence:** the expected backend rule exists when gateway configuration says it should exist.
5. **Backend shape:** the observed backend rule matches action, direction, source, destination, port, and protocol.
6. **Adoption scope:** during `doctor --fix --adopt`, explicitly selected observed backend rules may be inspected for compatible firewall-rule facts.

Observed backend firewall rules without gateway firewall configuration are unmanaged node reality by default. They are not reported as drift unless the operator requested an explicit adoption scope.

Backend rows that cannot be represented in Orbit firewall-rule fields are reported as unverifiable or skipped according to the probe result. They are not deleted by `doctor --fix --restore` and are not adopted by `doctor --fix --adopt`.

## Firewall Issue Codes

Each code below identifies a specific kind of drift the firewall probe can detect.

| Code | Detected when |
| --- | --- |
| `firewall_rule.record_incomplete` | A selected gateway firewall rule lacks node, name, direction, action, source, protocol, port, or backend identity metadata required for comparison. |
| `firewall_rule.node_invalid` | The rule points at a missing, unauthorized, inactive, unsupported, non-Ubuntu, or role-incompatible node. |
| `firewall_rule.baseline_conflict` | The rule attempts to edit role bootstrap policy owned by the node family. |
| `firewall_rule.rule_missing` | Gateway configuration expects a managed backend rule, but the rule is absent from node reality. |
| `firewall_rule.rule_mismatch` | A managed backend rule exists but differs from gateway configuration. |
| `firewall_rule.rule_extra` | During an explicit adoption scope, a selected observed backend rule has no matching gateway firewall rule row. |

## Firewall Fix Map

This table shows what `doctor --fix --restore` does for each fixable issue code.

| Code | `doctor --fix --restore` behavior |
| --- | --- |
| `firewall_rule.rule_missing` | Recreate the backend firewall rule from gateway configuration when the node is reachable and eligible. |
| `firewall_rule.rule_mismatch` | Replace the backend firewall rule with the gateway-configured rule when the rule can be identified safely. |

`doctor --fix --restore` does not handle `firewall_rule.record_incomplete`, `firewall_rule.node_invalid`, `firewall_rule.baseline_conflict`, or `firewall_rule.rule_extra`.

`doctor --fix --restore` re-applies or replaces only gateway-configured managed rules. It does not delete unmanaged backend rules, role bootstrap policy, WireGuard interface policy, or public-ingress policy owned by the node/proxy/app domains.

## Firewall Adopt Map

This table shows what `doctor --fix --adopt` does for each adoptable issue code.

| Code | `doctor --fix --adopt` behavior |
| --- | --- |
| `firewall_rule.rule_extra` | Create a gateway firewall rule row when: the operator selected a specific node and backend rule; the node is eligible; and the backend rule can be represented in Orbit firewall-rule fields. |
| `firewall_rule.rule_mismatch` | Update gateway configuration only when the operator selected the specific rule and the observed backend rule can be represented without changing node bootstrap policy. |

`doctor --fix --adopt` does not scan arbitrary hosts, adopt unsupported firewall backends, infer app/proxy ownership from ports, or adopt node bootstrap policy into the firewall family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/FirewallFamilyDoctorContractTest.php` | Firewall-family dispatch, probe-layer selection, issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering for firewall probes. |
| `tests/Unit/Services/Firewall/FirewallProbeTest.php` | In-memory firewall probe diff behavior for registry configuration, node eligibility, baseline policy boundaries, missing rules, mismatched rules, extra rules in adoption scope, and exclusion of node/proxy/app drift from firewall issue codes. |
| `tests/E2E/Read/FirewallDoctorTest.php` | Real read-only `doctor --family=firewall_rule --json` against nodes with managed firewall rules. |
| `tests/E2E/Ephemeral/FirewallDoctorFixTest.php` | Real `doctor --fix --family=firewall_rule --restore` repair of safe managed firewall drift. |
| `tests/E2E/Ephemeral/FirewallDoctorAdoptTest.php` | Real `doctor --fix --family=firewall_rule --adopt` for compatible selected observed firewall rule adoption. |
