# Firewall Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
firewall command ports.

Product behavior remains owned by `docs/commands/4_firewall/**` and the
top-level product docs.

## Domain Constraints

- The gateway is the source of truth for firewall rule intent.
- The state family key is `firewall_rule`; the command prefix is `firewall:*`.
- UFW is the current backend for supported Ubuntu nodes. It is not the product
  model.
- Firewall rules target registered active Ubuntu managed nodes with role
  `gateway` or `app`.
- Control nodes, unsupported platforms, inactive nodes, unmanaged roles, and
  missing node identities are not firewall-rule targets.
- Rules are expressed in Orbit terms: name, node, direction, action, source,
  destination, protocol, port, and reason.
- Rule names are unique on the target node. The same named rule with the same
  policy shape is idempotent; the same name with a different policy fails
  before mutation.
- Role bootstrap policy belongs to the node family. Firewall commands must not
  create, delete, adopt, or repair node bootstrap rules, WireGuard management
  rules, public SSH ingress for app nodes, or role-specific public ingress
  policy.
- Firewall reads use gateway intent by default. Live backend reality belongs to
  `doctor --family=firewall_rule`.
- Backend discovery/import is explicit doctor adoption work, not a firewall
  command or background sync.

## Schema And Model Pattern

- `firewall_rules`
  - `node_id`
  - `name`
  - `direction`
  - `action`
  - `source`
  - `destination` nullable
  - `port`
  - `protocol`
  - `reason` nullable
  - `source_hash`

`FirewallRule` belongs to `Node`. Command and API code should expose the JSON
entity from `docs/commands/4_firewall/README.md`; old implementation names such
as `destination_port` and `comment` are evidence, not the public contract for
the rebuilt codebase.

`source_hash` should represent the rendered backend artifact expected from
gateway intent. It is an implementation comparison helper, not product state
shown directly to users.

## Rule Intent Pattern

- `direction` is `incoming` or `outgoing`.
- `action` is `allow` or `deny`.
- `source` is a source CIDR or `any`.
- `destination` is nullable and represents a destination CIDR when the backend
  supports it.
- `port` is an integer or documented port range.
- `protocol` is `tcp` or `udp`.
- `reason` is an operator note carried into backend ownership metadata when the
  backend supports comments.
- Gateway writes validate node eligibility, rule identity, and baseline-policy
  boundaries before persisting or enacting intent.

## Command Pattern

- `firewall:list` is a gateway intent read. It applies visibility,
  authorization, and optional node filtering without SSH or live backend
  probing.
- `firewall:allow` and `firewall:deny` create or update firewall-rule intent,
  reject same-name policy conflicts, then enact the backend rule when the target
  node is reachable.
- `firewall:remove` removes only Orbit-owned firewall-rule intent, requires
  destructive consent, then removes the matching backend artifact when it can be
  identified safely.
- Control and app callers use typed gateway API requests. Gateway callers use
  local database state and gateway-owned node execution services for backend
  enactment.
- Runtime backend failures after successful intent persistence are reported as
  firewall-family warnings and repaired by doctor once fix handlers are
  available.
- Human and JSON output should use the firewall rule JSON entity from
  `docs/commands/4_firewall/README.md`.

## Backend Rendering Pattern

- UFW rendering should live in a pure renderer that turns a firewall-rule intent
  row into a deterministic canonical string and backend command shape.
- The renderer should not perform I/O. Shell execution belongs behind the
  gateway-owned remote execution edge.
- Canonical identity should include direction, action, source, destination,
  port, and protocol. Reason/comment text may be compared as metadata but must
  not cause a different policy to share an identity silently.
- Backend parsing should treat UFW line numbers as ephemeral. Stable matching
  should use parsed rule shape and Orbit ownership metadata.
- IPv6, unparseable backend rows, node bootstrap rows, and WireGuard interface
  policy must not be silently mutated by firewall command paths.

## Doctor Pattern

- `FirewallRuleProbe` should check registry completeness, node eligibility, and
  baseline policy boundaries before remote backend checks.
- Backend checks compare expected UFW rule presence and shape against node
  reality.
- Observed backend rules without gateway firewall intent are unmanaged node
  reality by default. They are reported only when an explicit adoption scope
  selected them and the row can be represented in Orbit firewall-rule fields.
- `--fix` can recreate missing managed rules or replace safely identified
  mismatched managed rules. It must not delete unmanaged rules, node bootstrap
  policy, WireGuard policy, or public-ingress policy owned by other domains.
- `--adopt` can create or update gateway firewall-rule intent only for a
  specific selected backend rule on an eligible node.
- Family-owned fix/adopt handlers use the generic doctor action-map runner and
  must keep rule selection explicit before mutating gateway intent or backend
  rules.

## Evidence Pointers

- `docs/commands/4_firewall/README.md`
- `docs/commands/4_firewall/firewall-doctor.md`
- `docs/commands/4_firewall/1_firewall-list`
- `docs/commands/4_firewall/2_firewall-allow`
- `docs/commands/4_firewall/3_firewall-deny`
- `docs/commands/4_firewall/4_firewall-remove`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Console/Commands/FirewallListCommand.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/FirewallAllowCommand.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/FirewallDenyCommand.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/FirewallRemoveCommand.php`
- Old evidence: `../orbit-old-may/app/Models/FirewallRule.php`
- Old evidence: `../orbit-old-may/app/Services/FirewallRules/FirewallRuleProbe.php`
- Old evidence: `../orbit-old-may/app/Services/FirewallRules/FirewallRuleRenderer.php`
- Old evidence: `../orbit-old-may/app/Services/FirewallRules/FirewallRuleEnactor.php`
- Old evidence: `../orbit-old-may/app/Services/FirewallRules/FirewallRuleWriter.php`
- Old evidence: `../orbit-old-may/tests/Unit/Services/FirewallRules/FirewallRuleProbeTest.php`
- Old evidence: `../orbit-old-may/tests/E2E/Ephemeral/FirewallRulesEnactmentTest.php`
