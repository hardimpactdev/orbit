# Firewall Doctor

[Back to Firewall commands.](README.md)

The firewall family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `firewall_rule`.

`doctor --family=firewall_rule` verifies whether gateway firewall configuration still matches node firewall reality. It covers firewall rules that Orbit owns.

The firewall family owns these facts:

- firewall rule rows owned by the gateway, plus their backend identity metadata;
- managed backend rules rendered from those rows;
- drift between gateway configuration and the node firewall backend;
- adoption facts for explicitly selected observed rules that can safely become Orbit-owned firewall configuration.

Each gateway firewall rule row records the node, name, direction, action,
source, destination, protocol, port, address family, interface scope, owner,
protected flag, reason, and the backend metadata needed to identify the applied
rule.

Node reachability belongs to `node`. Proxy routes, apps, workspaces, processes, schedules, and tools remain outside the firewall family even when their capabilities depend on firewall policy.

## Probe Layers

The firewall probe reads gateway firewall rules and checks these layers:

1. **Registry configuration:** every selected rule has a valid node reference, rule name, direction, action, source, protocol, and port.
2. **Node eligibility:** the node reference resolves to a visible active Ubuntu node with at least one active role assignment from `gateway`, `app-dev`, `app-prod`, `database`, or `agent`.
3. **Baseline policy boundary:** the rule does not attempt to mutate node bootstrap policy owned by the node family.
4. **Ownership boundary:** protected rows are reported read-only in the
   firewall family unless the firewall family owns the representation drift.
   Public SSH policy owned by the node family remains
   `node.security.public_ssh_deny`.
5. **Backend presence:** the expected backend rule exists when gateway configuration says it should exist.
6. **Backend shape:** the observed backend rule matches action, direction, source, destination, port, protocol, address family, and interface scope.
7. **Adoption scope:** during `doctor --adopt`, explicitly selected observed backend rules may be inspected for compatible firewall-rule facts.

Observed backend firewall rules without gateway firewall configuration are unmanaged node reality by default. They are not reported as drift unless the operator requested an explicit adoption scope.

Backend rows that cannot be represented in Orbit firewall-rule fields are reported as unverifiable or skipped according to the probe result. They are not deleted by `doctor --restore` and are not adopted by `doctor --adopt`.

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

This table shows what `doctor --restore` does for each fixable issue code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `firewall_rule.rule_missing` | Recreate the backend firewall rule from gateway configuration when the node is reachable and eligible. |
| `firewall_rule.rule_mismatch` | Replace the backend firewall rule with the gateway-configured rule when the rule can be identified safely. |

`doctor --restore` does not handle `firewall_rule.record_incomplete`, `firewall_rule.node_invalid`, `firewall_rule.baseline_conflict`, or `firewall_rule.rule_extra`.

`doctor --restore` re-applies or replaces only gateway-configured managed rules. It does not delete unmanaged backend rules, role bootstrap policy, WireGuard interface policy, or ingress policy owned by the node/proxy/app domains.

## Firewall Adopt Map

This table shows what `doctor --adopt` does for each adoptable issue code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `firewall_rule.rule_extra` | Create a gateway firewall rule row when all `rule_extra` preconditions below are met. |
| `firewall_rule.rule_mismatch` | Update gateway configuration only when the operator selected the specific rule and the observed backend rule can be represented without changing node bootstrap policy. |

`rule_extra` adoption requires:

- the operator selected a specific node and backend rule
- the node is eligible
- the backend rule can be represented in Orbit firewall-rule fields

`doctor --adopt` does not scan arbitrary hosts, adopt unsupported firewall backends, infer app/proxy ownership from ports, or adopt node bootstrap policy into the firewall family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Doctor/FirewallFamilyDoctorContractTest.php` | Firewall-family dispatch, probe-layer selection, issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering for firewall probes. |
| `apps/gateway/tests/Unit/Services/Firewall/FirewallProbeTest.php` | In-memory firewall probe diff behavior (see breakdown below). |
| `apps/gateway/tests/E2E/Read/FirewallDoctorTest.php` | Real read-only `doctor --family=firewall_rule --json` against nodes with managed firewall rules. |
| `apps/gateway/tests/E2E/Ephemeral/FirewallDoctorFixTest.php` | Real `doctor --family=firewall_rule --restore` repair of safe managed firewall drift. |
| `apps/gateway/tests/E2E/Ephemeral/FirewallDoctorAdoptTest.php` | Real `doctor --family=firewall_rule --adopt` for compatible selected observed firewall rule adoption. |

`FirewallProbeTest.php` covers registry configuration, node eligibility,
baseline policy boundaries, missing rules, mismatched rules, extra rules in
adoption scope, and exclusion of node/proxy/app drift from firewall issue codes.
