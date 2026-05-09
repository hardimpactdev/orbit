# Firewall Doctor

[Back to Firewall commands.](README.md)

`doctor --family=firewall_rule` verifies whether gateway firewall intent still
matches node firewall reality. It covers Orbit-owned firewall rules only.

The firewall family owns these facts:

- gateway-owned firewall rule rows: node, name, direction, action, source,
  destination, protocol, port, reason, and backend metadata needed to identify
  the enacted rule;
- managed backend rules rendered from those rows;
- drift between gateway intent and the node firewall backend;
- adoption facts for explicitly selected observed rules that can safely become
  Orbit-owned firewall intent.

Node reachability belongs to `node`. Proxy routes, apps, workspaces, processes,
schedules, and tools remain outside the firewall family even when their
capabilities depend on firewall policy.

## Probe Layers

The firewall probe reads gateway firewall rules and checks these layers:

1. **Registry intent:** every selected rule has a valid node reference, rule
   name, direction, action, source, protocol, and port.
2. **Node eligibility:** the node reference resolves to a visible active Ubuntu
   node with role `gateway` or `app`.
3. **Baseline policy boundary:** the rule does not attempt to mutate node
   bootstrap policy owned by the node family.
4. **Backend presence:** the expected backend rule exists when gateway intent
   says it should exist.
5. **Backend shape:** the observed backend rule matches action, direction,
   source, destination, port, and protocol.
6. **Adoption scope:** during `doctor --fix --adopt`, explicitly selected observed backend
   rules may be inspected for compatible firewall-rule facts.

Observed backend firewall rules without gateway firewall intent are unmanaged
node reality by default. They are not reported as drift unless the operator
requested an explicit adoption scope.

Backend rows that cannot be represented in Orbit firewall-rule fields are
reported as unverifiable or skipped according to the probe result. They are not
deleted by `doctor --fix --restore` and are not adopted by `doctor --fix --adopt`.

## Firewall Issue Codes

| Code | Detected when |
| --- | --- |
| `firewall_rule.record_incomplete` | A selected gateway firewall rule lacks node, name, direction, action, source, protocol, port, or backend identity metadata required for comparison. |
| `firewall_rule.node_invalid` | The rule points at a missing, unauthorized, inactive, unsupported, non-Ubuntu, or role-incompatible node. |
| `firewall_rule.baseline_conflict` | The rule attempts to edit role bootstrap policy owned by the node family. |
| `firewall_rule.rule_missing` | Gateway intent expects a managed backend rule, but the rule is absent from node reality. |
| `firewall_rule.rule_mismatch` | A managed backend rule exists but differs from gateway intent. |
| `firewall_rule.rule_extra` | During an explicit adoption scope, a selected observed backend rule has no matching gateway firewall rule row. |

## Firewall Fix Map

| Code | `doctor --fix --restore` behavior |
| --- | --- |
| `firewall_rule.rule_missing` | Recreate the backend firewall rule from gateway intent when the node is reachable and eligible. |
| `firewall_rule.rule_mismatch` | Replace the backend firewall rule with the gateway-intended rule when the rule can be identified safely. |

`doctor --fix --restore` does not handle `firewall_rule.record_incomplete`,
`firewall_rule.node_invalid`, `firewall_rule.baseline_conflict`, or
`firewall_rule.rule_extra`.

`doctor --fix --restore` re-applies or replaces only gateway-intended managed
rules. It does not delete unmanaged backend rules, role bootstrap policy,
WireGuard interface policy, or public-ingress policy owned by the
node/proxy/app domains.

## Firewall Adopt Map

| Code | `doctor --fix --adopt` behavior |
| --- | --- |
| `firewall_rule.rule_extra` | Create a gateway firewall rule row only when the operator selected a specific node and backend rule, the node is eligible, and the backend rule can be represented in Orbit firewall-rule fields. |
| `firewall_rule.rule_mismatch` | Update gateway intent only when the operator selected the specific rule and the observed backend rule can be represented without changing node bootstrap policy. |

`doctor --fix --adopt` does not scan arbitrary hosts, adopt unsupported firewall backends,
infer app/proxy ownership from ports, or adopt node bootstrap policy into the
firewall family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/FirewallFamilyDoctorContractTest.php` | Firewall-family dispatch, probe-layer selection, firewall issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering as it affects firewall probes. |
| `tests/Unit/Services/Firewall/FirewallProbeTest.php` | In-memory firewall probe diff behavior for registry intent, node eligibility, baseline policy boundaries, missing rules, mismatched rules, extra rules in adoption scope, and exclusion of node/proxy/app drift from firewall issue codes. |
| `tests/E2E/Read/FirewallDoctorTest.php` | Real read-only `doctor --family=firewall_rule --json` against nodes with managed firewall rules. |
| `tests/E2E/Ephemeral/FirewallDoctorFixTest.php` | Real `doctor --fix --family=firewall_rule --restore` repair of safe managed firewall drift. |
| `tests/E2E/Ephemeral/FirewallDoctorAdoptTest.php` | Real `doctor --fix --family=firewall_rule --adopt` for compatible selected observed firewall rule adoption. |
