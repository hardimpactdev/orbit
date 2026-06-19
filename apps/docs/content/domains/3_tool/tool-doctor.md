# Tool Doctor

[Back to Tool commands.](README.md)

The tool family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `tool`.

`doctor --family=tool` verifies whether gateway tool rows still match the node
capabilities those rows expect. It covers both managed tools, where Orbit owns
installation, update, adoption, removal, or configuration artifacts, and
observational tools, where Orbit only records and verifies expected capability
state. Long-running lifecycle belongs to processes. A tool row's expected
capability state is `installed` or `absent`; runtime up/down state and logs
are process-family facts and never tool drift.

The tool family owns these facts:

- gateway-owned tool rows: node, name, managed flag, expected capability state,
  expected version, expected configuration, credential contract, and backend probe
  metadata;
- managed install artifacts declared by the tool definition, such as packages,
  binaries, container definitions, systemd units, generated config, and managed
  secret material;
- update, reconfigure, and removal support declared by the tool
  definition;
- service endpoint configuration owned by the tool and declared by the tool definition, while
  backend proxy artifacts and firewall application remain verified by their own
  state families;
- adoption facts for explicitly selected observed node capabilities that can be
  tied to a supported Orbit tool definition.

Node reachability belongs to `node`. App, workspace, process, schedule, proxy
route, and firewall drift remain outside the tool family even when those
families depend on a tool.

For the `metrics` role, tool doctor verifies the Docker substrate capability
that Prometheus and Grafana need and the node-exporter host binary capability
that the systemd process needs. Prometheus, Grafana, and node-exporter runtime
lifecycle and drift are process-family facts, not tool facts.

## Probe Layers

The tools probe reads gateway tool rows and checks these layers:

1. **Registry configuration:** every selected tool row has a valid node reference,
   known tool definition, expected capability state, managed flag, and
   definition-specific required fields.
2. **Node eligibility:** the node reference resolves to a visible active node
   whose role and platform support the selected tool definition.
3. **Capability presence:** the expected package, binary, container, service, or
   observational capability is present when the row expects it to exist.
4. **Version state:** the observed version matches gateway expected version when
   the tool definition tracks versions.
5. **Configuration state:** managed config files, generated settings, and tool
   backend metadata match gateway configuration when the tool definition owns them.
6. **Credential material:** managed credentials or connection metadata exist and
   match the tool definition when credentials are part of the tool contract.
7. **Adoption scope:** during `doctor --adopt`, explicitly selected observed tools may
   be inspected for compatible tool facts.

Observed node capabilities without gateway tool rows are unmanaged inventory by
default. They are not reported as drift unless the operator requested an
explicit adoption scope.

Probe failures mark the selected tool unverifiable for that run. Tool doctor
does not guess observed state, adopt unknown probe output, or run fixes that
depend on facts the probe could not establish.

## Tool Issue Codes

Each code below identifies a specific kind of drift the tool probe can detect.

| Code | Detected when |
| --- | --- |
| `tool.record_incomplete` | A selected gateway tool row lacks node, name, expected state, managed flag, or definition-specific required fields. |
| `tool.node_invalid` | The tool row points at a missing, unauthorized, inactive, or role-incompatible node. |
| `tool.definition_missing` | The tool row references a tool name that is not present in Orbit's tool catalog. |
| `tool.unsupported_on_node` | The tool definition exists but does not support the selected node role or platform. |
| `tool.capability_missing` | The expected package, binary, container, service, or observational capability is absent. |
| `tool.version_mismatch` | The observed version differs from gateway expected version. |
| `tool.config_missing` | Managed configuration required by the tool definition is absent. |
| `tool.config_mismatch` | Managed configuration exists but differs from gateway configuration. |
| `tool.config_probe_failed` | Managed configuration could not be inspected reliably, so config drift is unverifiable for this run. |
| `tool.credentials_missing` | Managed credential material required by the tool definition is absent. |
| `tool.credentials_mismatch` | Managed credential metadata exists but differs from gateway configuration. |
| `tool.credentials_probe_failed` | Managed credential material could not be inspected reliably, so credential drift is unverifiable for this run. |
| `tool.unregistered_capability` | During an explicit adoption scope, a selected observed capability has no matching gateway tool row. |
| `tool.dns_container_missing` | The `orbit-dns` container is not present on a gateway that should be serving DNS over WireGuard. |
| `tool.dns_port_not_listening` | `orbit-dns` is running but nothing is listening on port 53 inside the wg-easy network namespace. |
| `tool.dns_config_drift` | The on-disk `dnsmasq.conf` differs from what the gateway would emit from the current `node.tld` and `node.wireguard_address` of every node carrying an active `app-dev` or `agent` role. |
| `tool.agent_route_missing` | An installed agent tool with a declared internal proxy route has no tool-owned route under the active agent role TLD. |
| `tool.agent_user_missing` | An agent tool is installed on a node whose `agent` user is absent or not configured as the tool's runtime user. |
| `tool.agent_credentials_missing` | An agent tool declares credentials but no managed credential material is present on the node tool row. |
| `tool.seaweedfs.row_missing` | No `seaweedfs` tool row exists on an active `s3` role node. Not auto-fixable; requires manual tool adoption or re-provision. |
| `tool.seaweedfs.credentials_missing` | The `seaweedfs` tool row exists but lacks service-level credentials (`credentials['fields']['access_key_id']` / `secret_access_key`). |
| `tool.seaweedfs.runtime_container_missing` | The SeaweedFS runtime container (`orbit-seaweedfs`) is absent on the node, or its rendered config diverges from gateway intent (missing or divergent). |
| `tool.seaweedfs.bind_public_interface` | The SeaweedFS API is bound to a public or non-WireGuard interface instead of the node's WireGuard address only. |

The three `tool.dns_*` codes are owned by the VPN-facing development DNS
bootstrap contract; see [`dns-bootstrap-contract.md`](dns-bootstrap-contract.md)
for the runtime layout they probe.

The four `tool.seaweedfs.*` codes are owned by the S3 role's SeaweedFS managed tool
contract; they are detected by the `S3DoctorProbe` via container introspection
over `RemoteHostExecutor` (SSH host substrate + Docker inspection).

Managed config and secret rows are repairable only when gateway intent contains
an absolute `path`, declared SHA-256 `hash`, and `content` whose hash matches
that declaration. Optional `mode` and `directory_mode` fields are enforced
through the same managed-file convergence resource used by restore. Incomplete
or contradictory intent is reported as `tool.record_incomplete`; unreachable
remote file probes are reported as the probe-failure codes above and are not
treated as repairable mismatches.

## Tool Fix Map

This table shows what `doctor --restore` does for each fixable issue code.
When a tool issue overlaps with managed-node setup, restore uses the same
internal convergence path that `node:new` used before activation. The public
doctor result still remains a tool-family restore action, and the tool
definition still owns the safe install, update, config, and
credential repair logic.

| Code | `doctor --restore` behavior |
| --- | --- |
| `tool.capability_missing` | Install or restore the managed capability only when the tool definition declares a safe install or repair path. |
| `tool.version_mismatch` | Update or downgrade the managed tool only when the tool definition supports the target version transition. |
| `tool.config_missing` | Recreate managed configuration from gateway configuration when the tool definition declares a safe reconfigure path. |
| `tool.config_mismatch` | Rewrite managed configuration from gateway configuration when the tool definition declares a safe reconfigure path. |
| `tool.credentials_missing` | Recreate managed credential material when the tool definition owns credential generation and declares the repair safe. |
| `tool.credentials_mismatch` | Rewrite managed credential metadata or generated material when the tool definition declares the repair safe. |
| `tool.dns_container_missing` | Re-run the orbit-dns installer (renders compose file + dnsmasq.conf + `docker compose up -d`). Requires wg-easy to be running first. |
| `tool.dns_port_not_listening` | Restart the `orbit-dns` container. |
| `tool.dns_config_drift` | Rewrite `dnsmasq.conf` from the gateway intent and SIGHUP `orbit-dns` (no container restart). |
| `tool.agent_route_missing` | Recreate the tool-owned internal proxy route for the agent tool under the active agent role TLD. |
| `tool.agent_user_missing` | Re-apply the `agent` role baseline to recreate the `agent` user. |
| `tool.agent_credentials_missing` | Regenerate managed credential material when the tool definition declares credential generation safe. |
| `tool.seaweedfs.credentials_missing` | Regenerate managed SeaweedFS credentials via the `seaweedfs` tool definition credential generation path. |
| `tool.seaweedfs.runtime_container_missing` | Recreate or restart the `orbit-seaweedfs` runtime container from gateway S3 config using `SeaweedfsTool` repair commands. |
| `tool.seaweedfs.bind_public_interface` | Restart and re-render the `orbit-seaweedfs` container bound to the node's WireGuard address only. |

`doctor --restore` does not handle `tool.record_incomplete`, `tool.node_invalid`,
`tool.definition_missing`, `tool.unsupported_on_node`, `tool.unregistered_capability`,
`tool.config_probe_failed`, `tool.credentials_probe_failed`, or
`tool.seaweedfs.row_missing` (the `seaweedfs` tool row must be created through
`tool:adopt` or re-provision; restore does not create tool rows).

Tools without a safe repair path are reported with the required manual action.
Tool doctor never creates apps, workspaces, processes, schedules, custom proxy
routes, non-tool firewall rules, node identities, or node grants. It may repair
endpoint configuration owned by the tool only when the selected tool definition declares that
ownership; live proxy and firewall artifact drift remains in the `proxy` and
`firewall_rule` families.

Tool fixes apply existing gateway configuration to node reality. They do not change
`expected_version`, expected capability state, generated config, or credential
configuration to match observed node state; adoption owns those configuration changes.

## Tool Adopt Map

This table shows what `doctor --adopt` does for each adoptable issue code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `tool.unregistered_capability` | Create a gateway tool row (see conditions below). |
| `tool.version_mismatch` | Update expected version only when the observed version is supported and the operator selected the specific tool for adoption. |
| `tool.config_mismatch` | Update expected config when the tool definition can prove the observed config belongs to the selected tool row and every adopted field is supported. |
| `tool.credentials_mismatch` | Update credential metadata only when the tool definition declares the observed credential material safe to adopt. |
| `tool.dns_config_drift` | Parse the observed `dnsmasq.conf` into node-family mapping triples and adopt those into node-family state; re-render `dnsmasq.conf` from canonical state afterward. See note below. |

`tool.dns_config_drift` adoption crosses into node-family state on purpose. The
tool family does not own DNS records; the node family does (see
[Architecture: DNS responsibilities](../../architecture.md#dns-responsibilities)).
The adopt path parses each `(node, tld, wireguard_address)` triple from the
observed `dnsmasq.conf`, records those triples into node-family state, then
re-renders `dnsmasq.conf` from the canonical node state so the file and DB
stay in lockstep. Narrow use case: an operator hand-edited the file for an
emergency and wants Orbit to adopt the change.

`tool.unregistered_capability` adoption requires three conditions:

- the operator selected a specific node and observed capability;
- the capability maps to a supported tool definition;
- the tool definition declares what observed facts may become configuration.

`doctor --adopt` does not scan arbitrary hosts for inventory, adopt unsupported
packages or containers, infer app/database ownership, adopt generated process or
schedule artifacts, or adopt firewall/proxy backend artifacts as tool facts.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Doctor/ToolsFamilyDoctorContractTest.php` | Tools-family dispatch, probe-layer selection, tool issue codes, tool fix map, tool adopt map, denied fix/adopt cases, and scope filtering as it affects tool probes. |
| `apps/gateway/tests/Unit/Services/Tools/ToolsProbeTest.php` | In-memory tool probe diff behavior (scope below). |
| `apps/gateway/tests/E2E/Read/ToolsDoctorTest.php` | Real read-only `doctor --family=tool --json` against nodes with managed and observational tool rows. |
| `apps/gateway/tests/E2E/Ephemeral/ToolsDoctorFixTest.php` | Real `doctor --family=tool --restore` repair of safe managed tool drift. |
| `apps/gateway/tests/E2E/Ephemeral/ToolsDoctorAdoptTest.php` | Real `doctor --family=tool --adopt` for compatible selected observed tool adoption. |

`ToolsProbeTest` covers registry configuration, node eligibility, capability
presence, version/configuration/credential drift, and adoption
scopes. It also asserts that app, workspace, process, schedule, proxy,
firewall, and node drift do not surface as tool issue codes, and that runtime
up/down state never surfaces as tool drift.
