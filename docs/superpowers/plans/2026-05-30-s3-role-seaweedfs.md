# S3 Role: Replace RustFS with SeaweedFS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

> **Supersedes:** `docs/superpowers/plans/2026-05-21-s3-role-router.md` (RustFS single-node).
> That plan landed the `s3` role, the `rustfs` tool, the `19_s3` docs domain, the
> router/ingress S3 routes, and the `s3:publish/unpublish/credentials` commands. This
> plan keeps the entire **external contract** from that work and swaps only the storage
> **backend** (RustFS → SeaweedFS), then adds horizontal scale-out and redundancy.

**Goal:** Replace the alpha/beta RustFS backend of the `s3` role with SeaweedFS, keep the
single-node "set it up once" experience, and add an `s3-volume` role so storage scales by
adding volume-server nodes. Auto-enable cross-server redundancy once there are ≥2 volume
servers.

**Why:** RustFS is pre-GA (beta as of `1.0.0-beta.1`, 2026-04-29; docs say "do NOT use in
production"; distributed mode not officially released). SeaweedFS is production-proven since
2015 and its master/volume/filer split is purpose-built for "one head node, add volume
servers for capacity." The current RustFS integration is young, so this is a clean backend
swap with **no data migration** (greenfield).

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, SQLite JSON role settings, encrypted
`NodeTool::credentials`, SeaweedFS (`chrislusf/seaweedfs` Docker image, `weed` binary),
Docker-first runtime container rendering, Caddy route rendering, Orbit router/ingress roles,
WireGuard private networking.

> **Repository layout:** Gateway code and tests live under `apps/gateway`. Use
> `apps/gateway/app`, `apps/gateway/database`, `apps/gateway/routes`, and
> `apps/gateway/tests` unless a step targets root shims, `apps/cli`, `packages/core`,
> `bin`, or `docker`.
>
> **E2E contract:** `apps/docs/content/testing/README.md` and
> `apps/docs/content/testing/e2e/**` are authority. Docker E2E uses composable role images
> and runs the smallest requested topology. Incus feature E2E uses prepared role snapshots.

---

## Status

**Source context (read before implementing):**

- `docs/superpowers/plans/2026-05-21-s3-role-router.md` — the RustFS plan this replaces; its
  File Map and command/route/doctor contracts are the pattern to mirror.
- `apps/docs/content/architecture.md` (`s3` role, ~lines 108-114)
- `apps/docs/content/tech-stack.md` (`### S3 runtime`, ~lines 251-285)
- `apps/docs/content/domains/19_s3/**` (existing S3 command domain)
- `apps/docs/content/domains/1_node/node-concepts.md` (role matrix, settings, platform)
- `apps/docs/content/domains/3_tool/catalog/rustfs.md` (tool catalog entry to replace)
- Existing code: `apps/gateway/app/Services/S3/**`, `apps/gateway/app/Tools/RustfsTool.php`,
  `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/S3RoleBaseline.php`.

**Required dependency:** Router/ingress contract is already landed (the RustFS plan shipped on
top of it). This plan assumes `router` owns `s3.orbit` and the S3 backend pool, and `ingress`
forwards public S3 hosts to router.

## SeaweedFS facts this plan depends on

These are the concrete deltas from RustFS. Bake them into tests as literal values.

| Concern | RustFS (today) | SeaweedFS (this plan) |
| --- | --- | --- |
| Binary / image | `rustfs/rustfs` | `chrislusf/seaweedfs` (`weed`) |
| Single-node command | container default | `weed server -filer -s3 -ip=<wg> -dir=/data -s3.config=/etc/seaweedfs/s3.json` |
| Volume-only command | n/a | `weed volume -mserver=<head-wg>:9333 -ip=<wg> -dir=/data -dataCenter=orbit -rack=<node> -max=0` |
| S3 API port | `9000` | **`8333`** |
| Other ports | — | master `9333`/`19333`, volume `8080`/`18080`, filer `8888`/`18888` (gRPC `1xxxx` must be WG-reachable head↔volume) |
| Credentials | `RUSTFS_ACCESS_KEY` / `RUSTFS_SECRET_KEY` env | **S3 identities JSON** (`-s3.config`), mounted into the container |
| Bucket → storage | n/a | bucket = collection at `/buckets/<name>`; objects spread across all volume servers automatically |
| Default replication | n/a | **`000` (one copy)**; opt-in per path via `fs.configure -locationPrefix=/buckets/ -replication=001 -apply` |
| Re-replicate existing | n/a | `weed shell` → `volume.fix.replication` |
| Rebalance after adding a server | n/a | `weed shell` → `volume.balance -force` (NOT automatic) |
| Filer metadata store | n/a | `leveldb2` (embedded, under `/data`) in v1; Postgres/Redis is future HA work |

**Persistence:** the role's `data_path` mounts to `/data` and holds master metadata, the
leveldb2 filer store (the S3 key namespace), and local volume files. Back it up with the node.

**Trust model:** head↔volume gRPC is plaintext but only reachable over WireGuard, matching the
current "bind to WG only" posture. `security.toml`/mTLS between SeaweedFS components is optional
future hardening, not v1.

## Architecture

- **`s3` role node = SeaweedFS head:** `master + filer + leveldb2 filer store + S3 gateway +
  one local volume server`, all in ONE Docker runtime container (`weed server -filer -s3`).
  This is the "set it up once" node and works standalone with zero volume nodes.
- **`s3-volume` role node = volume server only:** `weed volume` pointing `-mserver` at the
  active head's WireGuard address. Adding one of these = "add a server for more storage."
- **Head is NOT the gateway** (decision): `s3` and `s3-volume` keep the same conflicts as
  today's `s3` role; the gateway stays control-plane-only.
- **Redundancy auto-protect at ≥2 volume servers** (decision): when an `s3-volume` node
  converges and total active volume servers ≥ 2, the gateway sets `/buckets/` replication to
  `001` and triggers `volume.fix.replication`. Never auto-downgrade when servers drop.
- **External contract unchanged:** `https://s3.orbit`, `<node>.s3.orbit` backends,
  `s3:publish/unpublish/credentials`, router/ingress flow, path-style addressing, the
  service-level credential shape (`access_key_id/secret_access_key/region/endpoint/bucket_style`).

## Tool naming

One tool slug `seaweedfs` (category `storage`) with `config.mode = head | volume`. The `s3`
baseline materializes `mode=head`; the `s3-volume` baseline materializes `mode=volume`. The
runtime renderer and doctor branch on `mode`. The `rustfs` slug, tool class, catalog doc, and
`tool.rustfs.*` doctor keys are removed (greenfield, no migration shim).

## Out of scope (v1)

- Filer/master HA (multi-master Raft, replicated/SQL filer store). Head is a single point of
  failure for the control path even though object data is replicated across volume servers.
- Erasure coding (EC) for cold data.
- Metadata-only head (head always runs a local volume in v1).
- Per-app bucket credentials, IAM users, bucket lifecycle commands, virtual-hosted buckets,
  wildcard DNS/TLS, public S3 console/admin UI exposure.
- Automatic re-balance scheduling beyond the convergence-time `volume.fix.replication` pass.
- RustFS→SeaweedFS data migration (none exists).

## Role Compatibility

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `s3` (head) | `app-development`, `database`, `websocket` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent`, `s3-volume` |
| `s3-volume` | `app-development`, `database`, `websocket` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent`, `s3` |

- `s3` and `s3-volume` are mutually exclusive on one node (the head already runs a volume).
- `s3-volume` requires an active `s3` head node to be useful; its baseline fails clearly when
  no active head exists.

## Vertical Slices

Ship in order; each slice is independently valuable and verifiable.

- **Slice A — Backend swap (single node).** Tasks 1-6. SeaweedFS head replaces RustFS on one
  node with full parity (port 8333, identities config, router/ingress, commands, doctor, E2E).
- **Slice B — Horizontal scale-out.** Tasks 7-8. `s3-volume` role; a volume node joins the
  head; 2-node E2E proves added capacity.
- **Slice C — Redundancy at ≥2 servers.** Tasks 9-10. Replication configurator, re-replication,
  doctor under-replication checks, E2E.

---

## Task 1: Align Product Documentation (Slice A)

> Product docs are authority. This task is the work the `updating-documentation` skill owns.

**Files:**

- Modify `apps/docs/content/architecture.md` — `s3` role paragraph: SeaweedFS instead of RustFS,
  add the head/volume split and the `s3-volume` role, note redundancy at ≥2 volume servers.
- Modify `apps/docs/content/tech-stack.md` — `### S3 runtime`: SeaweedFS image, single-container
  `weed server -filer -s3`, port `8333`, S3 identities config injection, leveldb2 filer store,
  `data_path` contents, replication posture, head↔volume WG gRPC.
- Modify `apps/docs/content/concepts.md` — replace RustFS term with SeaweedFS; add `s3-volume`
  role, "SeaweedFS head", "volume server", "filer", "collection/bucket", "replication policy".
- Modify `apps/docs/content/domains/1_node/node-concepts.md` — add `s3-volume` to role
  vocabulary, platform support, settings, and the compatibility matrix (both rows above).
- Modify `apps/docs/content/domains/1_node/1_node-new/**` — document `--role=s3-volume` and
  `--s3-data-path=` (shared setting name).
- Modify `apps/docs/content/domains/1_node/12_node-role-add/**` — document adding `s3-volume`.
- Replace `apps/docs/content/domains/3_tool/catalog/rustfs.md` →
  `apps/docs/content/domains/3_tool/catalog/seaweedfs.md`; update the catalog table in
  `domains/3_tool/README.md`.
- Modify `apps/docs/content/domains/19_s3/**` (`s3.md`, `s3-concepts.md`, README, the three
  command docs) — replace RustFS with SeaweedFS; document the head/volume model, the
  `replication` setting, and that adding volume servers adds capacity (and redundancy at ≥2).
- Modify `apps/docs/content/domains/8_proxy/**` — change the S3 backend port `9000` → `8333` in
  any rendered-route examples; the `s3.orbit` routing rule is otherwise unchanged.
- Modify `apps/docs/content/domains/authorization-matrix.md` — add `s3-volume` rows mirroring `s3`.

**Architecture paragraph (target language):**

```markdown
The `s3` role is a private workload role for Orbit-managed S3-compatible object storage. An
`s3` node runs a single SeaweedFS "head" — master, filer, S3 gateway, and a local volume
server — in one Docker runtime container rendered by Orbit, and binds every listener only to
the node's WireGuard address. Storage scales horizontally with the `s3-volume` role: each
`s3-volume` node runs a SeaweedFS volume server that joins the head's master over WireGuard.
Once two or more volume servers are active, Orbit enables `001` replication so objects survive
the loss of a single volume server. Public S3 traffic enters through `ingress`, flows to
`router`, then to the `s3.orbit` head. Apps and VPN clients use the stable `s3.orbit` endpoint
and never target a concrete node.
```

- [ ] **Step 1:** Apply the doc edits above.
- [ ] **Step 2:** Run `composer docs-lint`. Expected: `issues:0 errors:0 warnings:0`.

## Task 2: Rename role/tool surface for SeaweedFS (Slice A)

**Files:** `apps/gateway/app/Tools/RustfsTool.php`,
`apps/gateway/app/Providers/AppServiceProvider.php`,
`apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php`, plus any `rustfs` references.

- [ ] **Step 1:** Update the catalog test to expect slug `seaweedfs`, category `storage`,
  required role `s3`, capabilities including `credentials`, and
  `probeMetadata('seaweedfs')['runtime'] === 'docker-container'`. Run it — expect fail.
- [ ] **Step 2:** Rename `RustfsTool` → `SeaweedfsTool` (slug `seaweedfs`); set
  `probeMetadata()` to `['runtime' => 'docker-container', 'service' => 'seaweedfs', 'container'
  => '<rendered>', 'managed_by' => 's3-runtime-container']`. Register it in `AppServiceProvider`
  in place of `RustfsTool`. Delete the `rustfs` registration.
- [ ] **Step 3:** Grep `apps/gateway` for `rustfs`/`Rustfs`/`RUSTFS` and re-point each to the
  SeaweedFS equivalents (tool slug, doctor keys, config keys). Run the catalog test — expect pass.

## Task 3: Render the SeaweedFS head runtime container (Slice A)

**Files:** `apps/gateway/app/Services/S3/S3RuntimeContainer.php`,
`S3RuntimeContainerRenderer.php`, `S3CredentialGenerator.php`, `S3ServiceConfig.php` (mirror
the existing RustFS versions; change backend specifics). Tests:
`apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php`.

- [ ] **Step 1: Rewrite the renderer test** to assert the head container:
  - `image` = `chrislusf/seaweedfs:<pinned-tag>` (pin a real tag; do NOT use `latest`).
  - `command` = `['server','-filer','-s3','-ip','10.6.0.44','-dir','/data',
    '-master.volumeSizeLimitMB','30000','-s3.port','8333','-s3.config','/etc/seaweedfs/s3.json',
    '-volume.max','0']`.
  - `ports` contains `10.6.0.44:8333:8333` and NOT `0.0.0.0:8333:8333`.
  - `volumes` contains `/srv/orbit/s3/data:/data` and the rendered `s3.json` mount.
  - the rendered S3 identities config equals the expected JSON (one `orbit` identity with the
    stored access/secret key and `Admin,Read,Write,List,Tagging` actions).
  - Run — expect fail.
- [ ] **Step 2:** Add a `mode` field (`head|volume`) to `S3ServiceConfig`. Implement the head
  branch of `S3RuntimeContainerRenderer::render()` producing the container above. Render the
  `s3.json` from `S3CredentialGenerator` output (keep the `access_key_id='orbit'`, 48-char
  secret, `region='us-east-1'`, `endpoint='https://s3.orbit'`, `bucket_style='path'` shape).
  Pin the image tag in one constant on `S3RuntimeContainer`.
- [ ] **Step 3:** Run the renderer test — expect pass.

## Task 4: Converge the `s3` head baseline (Slice A)

**Files:** `S3ServiceConfigResolver.php`, `S3ServiceConfigurator.php`, `S3RoleBaseline.php`
(mirror existing; change tool slug to `seaweedfs`, set `config.mode='head'`, render the head
container, write the `s3.json` mount). Test:
`apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php`.

- [ ] **Step 1:** Update the baseline test to assert a `seaweedfs` tool row with
  `config.mode='head'`, `config.service_host='s3.orbit'`,
  `config.backend_host='storage-1.s3.orbit'`, `config.runtime='docker-container'`,
  `config.public_hosts=[]`, encrypted credentials present, and a `docker` tool row. Run — fail.
- [ ] **Step 2:** Implement the configurator/resolver/baseline changes. Preserve an existing
  `secret_access_key` when the tool row already has credentials (idempotent converge). `remove()`
  keeps `data_path` unless `--purge-data`.
- [ ] **Step 3:** Run the baseline test — expect pass.

## Task 5: Re-point router/ingress S3 routes to port 8333 (Slice A)

**Files:** `apps/gateway/app/Services/S3/S3RouteRegistrar.php`, `S3BackendName.php`, the router
and ingress route renderers, `ProxyRouteQuery.php`, `ProxyRouteProbe.php`. Test:
`apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php`.

- [ ] **Step 1:** Update route tests so the `s3.orbit` upstream is
  `http://storage-1.s3.orbit:8333` and the `upstreams` entry is `{scheme:'http',
  host:'storage-1.s3.orbit', port:8333}`. Ingress public-host routes still target
  `https://s3.orbit`. Run — expect fail.
- [ ] **Step 2:** Change the backend port constant `9000` → `8333` in the registrar/renderer.
  Keep the Host-preserving, no-request-buffering proxy directives. Run — expect pass.

## Task 6: Doctor + commands + E2E for the swap (Slice A)

**Files:** doctor services (rename `tool.rustfs.*` → `tool.seaweedfs.*` keys, change bind port
to 8333), `S3*Command` tests (slug/port assertions only — command behavior unchanged), E2E.

- [ ] **Step 1:** Update doctor tests/keys: `tool.seaweedfs.row_missing`,
  `tool.seaweedfs.credentials_missing`, `tool.seaweedfs.runtime_container_missing`,
  `tool.seaweedfs.bind_public_interface` (now checks `:8333`). Keep `node.s3.*` and
  `proxy.s3.*` keys. Run focused doctor tests — fail, then implement, then pass.
- [ ] **Step 2:** Update `S3PublishCommandTest` / `S3CredentialsCommandTest` only where they
  assert backend port/slug. The JSON contracts (`private_endpoint=https://s3.orbit`, fields)
  are unchanged. Run — expect pass.
- [ ] **Step 3:** Update `apps/gateway/tests/E2E/S3PrivateRouteTest.php` and
  `S3IngressRouteTest.php` to the SeaweedFS image and port 8333. Add an assertion that issues an
  S3 `PUT`+`GET` against `https://s3.orbit` through the router and gets the object back. Run
  `composer test:e2e:docker -- <both files>` — expect pass.
- [ ] **Step 4:** If the Docker lane can't prove WG-only bind, add an Incus-marked test
  asserting `8333` reachable from router over WG and not on the public interface (mirror the
  existing RustFS bind E2E from commit `53faa1f63`).

## Task 7: Add the `s3-volume` role + tool mode (Slice B)

**Files:** `NodeRoleName.php` (add `S3Volume = 's3-volume'`), `S3VolumeRoleSettings.php` (new,
`data_path` only — mirror `S3RoleSettings`), `NodeRoleRegistry.php`,
`NodeRoleBaselineConverger.php`, `S3VolumeRoleBaseline.php` (new). Tests mirror the `s3` role
tests.

- [ ] **Step 1:** Write `S3VolumeRoleSettingsTest` (defaults `/srv/orbit/s3/data`, rejects
  relative/unknown) and registry expectations (conflicts list above; mutual conflict with `s3`).
  Run — fail.
- [ ] **Step 2:** Add the enum case, settings class, and registry definition. Add `s3-volume`
  to the conflict lists of `gateway/vpn/router/ingress/app-production/agent/s3`; add `s3` to
  `s3-volume`'s conflicts. Run settings/registry tests — pass.
- [ ] **Step 3:** Write `S3VolumeRoleBaselineTest`: converging on a node with an active head
  materializes a `seaweedfs` tool row with `config.mode='volume'` and
  `config.master_host='<head>.s3.orbit'`; converging with **no** active head throws
  `RuntimeException('The s3-volume role requires an active s3 head node.')`. Run — fail.
- [ ] **Step 4:** Implement `S3VolumeRoleBaseline::converge()`: resolve the active `s3` head
  node, render the **volume** branch of `S3RuntimeContainerRenderer` (the `weed volume
  -mserver=<head-wg>:9333 -dataCenter=orbit -rack=<node-name> -max=0` command, all listeners
  bound to the node's WG address), and converge the container. Add the volume branch to the
  renderer with its own unit test. Register the baseline in `NodeRoleBaselineConverger`. Run — pass.

## Task 8: Multi-node E2E — added capacity (Slice B)

**Files:** `apps/gateway/tests/E2E/S3VolumeScaleOutTest.php` (new) + testing docs if a new
topology capability is needed (`apps/docs/content/testing/e2e/**`).

- [ ] **Step 1:** Write a Docker E2E that boots a head `s3` node and one `s3-volume` node,
  converges both, and asserts the master reports **2 volume servers** (query the head's master
  `/cluster/status` or `/dir/status` over WG, or `weed shell` `volume.list`). PUT an object via
  `s3.orbit`, confirm it is retrievable. Run `composer test:e2e:docker -- <file>` — expect pass.
- [ ] **Step 2:** If the prepared topology lacks a second storage node shape, extend the E2E
  topology per the testing-authority docs (document the new capability first).

## Task 9: Redundancy configurator at ≥2 volume servers (Slice C)

**Files:** `apps/gateway/app/Services/S3/S3ReplicationConfigurator.php` (new),
`S3RoleSettings.php` (+`replication` optional override), `S3VolumeRoleBaseline.php` (call the
configurator after a volume joins), head doctor. Tests: unit for the configurator.

- [ ] **Step 1:** Write `S3ReplicationConfiguratorTest`: given an `s3` head and ≥2 active volume
  servers, `configure()` issues (through the runtime execution lane against the head container)
  `weed shell` commands equivalent to `fs.configure -locationPrefix=/buckets/ -replication=001
  -apply` followed by `volume.fix.replication`. With exactly 1 volume server it issues neither
  (stays `000`). It never lowers an already-higher replication. Use a fake shell to capture
  commands. Run — fail.
- [ ] **Step 2:** Implement `S3ReplicationConfigurator`. Counting rule: total active volume
  servers = the head's local volume (always 1) + active `s3-volume` nodes. Allow an operator
  override via the `s3` role `replication` setting (e.g. `010` for cross-rack); the auto policy
  only ever raises toward `001`, never downgrades. Run — pass.
- [ ] **Step 3:** Call `S3ReplicationConfigurator::configure()` at the end of
  `S3VolumeRoleBaseline::converge()` and on `s3-volume` removal-driven re-converge (raise only).
  Add a focused feature test that converging the 2nd volume server triggers the configurator.

## Task 10: Redundancy doctor + E2E (Slice C)

**Files:** head doctor (proxy/tool family), `apps/gateway/tests/E2E/S3RedundancyTest.php` (new).

- [ ] **Step 1:** Add doctor checks (keys `tool.seaweedfs.replication_below_policy`,
  `tool.seaweedfs.under_replicated_volumes`) that flag, when ≥2 volume servers exist, that
  `/buckets/` replication is `001`+ and no volumes are under-replicated (query
  `volume.list`/`volume.check.disk`). Write failing tests, implement, pass.
- [ ] **Step 2:** Write a Docker E2E (`S3RedundancyTest`) booting head + 2 `s3-volume` nodes:
  assert `/buckets/` replication became `001`, PUT an object, then stop one volume node and
  assert the object is still retrievable through `s3.orbit`. Run `composer test:e2e:docker --
  <file>` — expect pass. (If killing a node mid-lane is unsupported, assert the replicated copy
  count via `weed shell` instead and document the gap.)

## Task 11: Final Verification

- [ ] **Step 1:** Focused suite —
  `bin/orbit-gateway-pest --compact --filter='s3|seaweedfs'`. Expected: pass.
- [ ] **Step 2:** `composer docs-lint`. Expected: no issues.
- [ ] **Step 3:** `bin/orbit-gateway-vendor-bin pint --dirty --format agent`. Expected: clean.
- [ ] **Step 4:** `composer quality-check`. Expected: pass.
- [ ] **Step 5:** S3 E2E lane — `composer test:e2e:docker -- apps/gateway/tests/E2E/S3*Test.php`.
  Expected: pass. Run the Incus bind test if added in Task 6.4.

## Resolved Decisions

- **Scaling model:** introduce the `s3-volume` role now (real horizontal scale-out), not a
  single-node-only seam.
- **Head placement:** SeaweedFS head runs on a dedicated `s3` node, never the gateway; role
  matrix conflicts unchanged from today's `s3`.
- **Redundancy:** auto-enable `001` replication once ≥2 volume servers are active, including a
  `volume.fix.replication` pass over existing data; never auto-downgrade; operator may override
  the target via the `s3` role `replication` setting.
- **Tool model:** one `seaweedfs` tool slug with `config.mode = head|volume`; `rustfs` removed
  outright (greenfield, no migration shim).
- **Image:** pin a concrete `chrislusf/seaweedfs` tag; do not track `latest`.

## Risks

- **Head is a single point of failure for the control path (master + filer).** Object data is
  replicated at ≥2 volume servers, but losing the head loses availability until it is restored
  from its `data_path` backup. Filer/master HA is explicit future work.
- **Replication flips only protect new writes until `volume.fix.replication` completes;** the
  re-replication pass must finish for existing objects to gain redundancy. Doctor surfaces
  under-replicated volumes.
- **Port/credential model differs from RustFS** (8333, identities JSON). Every `9000`/`RUSTFS_*`
  reference must be re-pointed; the Task 2 grep gate guards against stragglers.
- **head↔volume gRPC is unauthenticated over WG.** Acceptable under the current WG-only trust
  model; `security.toml` is future hardening.
