# Todo 764 — Retained-Incus Runtime Proof

- Candidate: `456b4dc5307cd3030d4b97cc00a97b12e2daee68`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-0d557d` (host `beast`)
- VMs: `orbit-e2e-dev-0d557d-{operator,gateway,dev}`
- Bind: sha256 of the new `E2ETopologyFacts.php` and the four migrated call sites
  (`E2EPreparedTopology.php`, `IncusTopologyTemplate.php`, `DockerTopologyBuilder.php`,
  `DockerTopologyProvider.php`) match exactly between the worktree candidate and the
  operator VM mount `/home/orbit/orbit`.

## Part A — refactored facts booted a correct live topology

`composer e2e:incus -- --start --id=dev-764a --topology=operator_gateway_app-dev
--checkout-roles=operator,gateway,dev` brought up the topology using the candidate's
`E2ETopologyFacts`. The role assignment and network facts produced by the refactored
model matched their canonical values on the live VMs:

- Roles assigned: `operator`, `gateway`, `dev` (per-role checkout + migrate succeeded on
  each node).
- WireGuard addresses observed live: gateway `10.6.0.2`, dev `10.6.0.4` — exactly the
  canonical bytes owned by `E2ETopologyFacts` (gateway .2, dev .4).
- Gateway API came up ready (`gateway-api.ready`).

This proves the centralized facts produce a valid, bootable topology at runtime.

## Part B — fact-matrix equivalence on the deployed runtime

Ran the characterization matrix Pest inside the operator VM runtime against an isolated
disposable test DB (NOT `composer test:e2e*`):
`ssh beast incus exec orbit-e2e-dev-0d557d-operator -- sudo -u orbit bash -lc 'cd
/home/orbit/orbit/apps/gateway && DB_DATABASE=/tmp/764-test.sqlite APP_ENV=testing
HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test
tests/Feature/E2ESupport/E2ETopologyFactsCharacterizationTest.php --compact'`.

Observed: **48 passed (544 assertions)** on the retained-topology PHP runtime — proving:
- all four sources (E2EPreparedTopology, IncusTopologyTemplate, DockerTopologyBuilder,
  DockerTopologyProvider) resolve every topology kind and every alias to the same facts
  as the canonical `E2ETopologyFacts`;
- the canonical WireGuard address map is owned once and unchanged;
- physical artifact/runtime role slug maps (including websocket→dev) are preserved;
- dedicated ingress vs prod-hosted ingress and websocket co-location are preserved.

Result: passed.
