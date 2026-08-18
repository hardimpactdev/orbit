# Todo 764 — Blast Radius Inventory

Candidate: `456b4dc5307cd3030d4b97cc00a97b12e2daee68`
Base: `089e0e0fe` (main incl. todo 763)

## Method

Repository-wide review of every E2E topology "facts" owner. The pre-implementation
Explore sweep identified four duplicated fact tables across
`apps/e2e/app/E2E/Support/`: the kind→roles `match` (4 copies), the websocket-kind
predicate (3 copies), the canonical 10.6.0.x WireGuard address map (2 identical copies),
and the artifact/docker/incus physical-role slug maps. This change centralizes those into
one typed value model and points all four call sites at it.

## Result — complete

Diff `089e0e0fe..456b4dc5` — 13 files, +1021 / −180:

- **New typed model**: `apps/e2e/app/E2E/Support/E2ETopologyFacts.php` (+192) — owns
  per-kind logical roles, artifact↔docker↔incus physical role maps, the role-alias
  table, the websocket-kind set, the canonical WireGuard address map (gateway .2,
  operator .3, dev .4, prod .5, agent .6, ingress .7, websocket .8), the
  prod-hosts-ingress co-location fact, and the gateway node-name map. Implemented as
  constant-array lookups (no control-flow) to satisfy Mago class-size/method-count and
  kan-defect gates.
- **Call sites migrated (duplicate tables deleted)**:
  `E2EPreparedTopology.php` (−76 net), `IncusTopologyTemplate.php` (−33),
  `DockerTopologyBuilder.php` (−47), `DockerTopologyProvider.php` (−41) — each now reads
  the shared facts; provider build/stage/lease orchestration stays local.
- **Characterization matrix** (new): `E2ETopologyFactsCharacterizationTest.php` under
  both `apps/e2e/tests/Feature/E2ESupport/` and `apps/gateway/tests/Feature/E2ESupport/`
  (the gateway autoloads the `App\E2E\Support` namespace via the composer remap, so the
  synced test tree carries both), plus extensions to
  `TopologyTerminologyContractTest`, `DockerTopologyBuilderTest`,
  `DockerTopologyProviderTest`, `E2EPreparedTopologyTest`, `IncusTopologyBuilderTest`,
  `IncusTopologyTemplateTest` — asserting all four sources agree for all 11 kinds and
  every alias (48 tests, 544 assertions).

## Preserved co-location / alias facts (verified green)

- `OperatorGatewayAppprodIngress` folds ingress→prod (runtimeRoles / Incus / Builder =
  `[operator, gateway, prod]`; Provider logical roles still include ingress).
- `OperatorGatewayAppdevAppprodIngress` keeps a DEDICATED ingress role.
- Websocket kinds stay `[operator, gateway, dev, …]` with no dedicated websocket
  instance; websocket role assignment stays on `app-dev-1`.
- `prodHostsIngressRole` set unchanged (prod-hosted kinds true; dedicated-ingress kind
  false).
- Node map: dev/websocket→app-dev-1, prod→app-prod-1, agent→agent-1, ingress→edge-1.
- WireGuard bytes unchanged.

## Out of scope (untouched)

`E2ETopologyKind` enum + input-alias/deprecated maps; provider build/stage/lease
orchestration; the topology matrix itself (no kinds/roles added or removed).

Result: complete — evidence=Explore duplication sweep + diff review of the four call
sites and the new value model; all four sources proven equal per kind/alias by the
characterization matrix.
