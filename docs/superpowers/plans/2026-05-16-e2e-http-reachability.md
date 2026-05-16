# E2E HTTP Reachability Assertions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a new category of E2E assertion — *runtime HTTP reachability* — into the Orbit E2E suite. Today the suite verifies only state convergence (DB rows, file shapes, doctor reports, JSON command output). It never asserts that a deployed app actually responds to HTTP on its TLD hostname. This plan adds that, starting with the smallest credible slice (control node → gateway over WG by hostname → HTTP 200) and growing to cover the deployment-then-access loop on both dev and prod app nodes.

**Why this exists as its own plan:** When auditing the suite as part of the gateway DNS provisioning work (see `2026-05-16-gateway-dns-provisioning.md`), the following greps returned zero hits across `tests/E2E/`:

- `->get('http`, `->getJson('http`, `file_get_contents('http`, `HttpClient::`, `->statusCode()`
- The strings `reachable` and `reachability`
- Any `curl` use that wasn't a stubbed binary intercepted for testing deploy step execution

Adding reachability assertions is large enough — it touches the topology fixtures, requires a usable WG client + DNS resolution from inside an E2E control container, and introduces new helpers (`assertHttpReachable(...)`) that don't exist yet — that bundling it into the DNS provisioning plan would have obscured both. This plan owns it standalone.

**Dependency:** This plan depends on `2026-05-16-gateway-dns-provisioning.md` landing first. Without gateway DNS provisioning, the reachability tests have nothing to assert against.

**Architecture:** Three layers, smallest to largest:
1. **DNS lookup** from a control container, resolving `<name>.<tld>` against the gateway's `WG_DEFAULT_DNS` (currently `10.6.0.1`, served by `orbit-dns` inside wg-easy's network namespace). Validates that the DNS provisioning plan's output is actually working over WG.
2. **HTTP reachability** to the gateway itself (`https://<gateway-name>.<gateway-tld>/` → 200) over WG. Validates DNS + WG routing + Caddy serving.
3. **App-deployment reachability** — after `orbit app:new` lands a real app on a dev app node and after `orbit deploy` runs on a prod app node, the deployed app responds with 200 from the control node, via its TLD hostname.

**Tech Stack:** Pest E2E (`tests/E2E/...`), `E2ECommand::ssh()` to run `curl` / `dig` inside the control container, existing topology fixtures (`E2ETopologyKind::*`, `e2eProvisionGatewayThroughNodeNew`), Docker topology driver. No new infrastructure — just new assertions inside existing topology shapes.

**Reference material:**
- `tests/E2E/AppNewProvisioningTest.php` — current "full topology" test pattern; ends at state convergence. This plan adds a reachability tail to that pattern.
- `app/E2E/Support/E2ENetwork.php` — `routeWireGuardPeer` handles IP-level routing for the Docker topology. DNS-level routing is what the gateway DNS plan provides.
- `docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md` — prerequisite.

**Out of scope:**
- Provisioning DNS (that's the prerequisite plan).
- TLS verification on dev TLDs (Orbit uses internal CA; tests can use `curl -k` or trust the gateway CA inside the test container).
- Performance / load / chaos testing of reachability.
- App-node → app-node reachability. The flow Orbit cares about is control-node → app-node and gateway → app-node.

---

## Status

**Remaining:**
- [ ] Task 1: Add `assertDnsResolvesOverWg` helper and minimum-viable DNS reachability test
- [ ] Task 2: Add `assertHttpReachable` helper and gateway-self reachability test
- [ ] Task 3: Extend `AppNewProvisioningTest` (or a sibling) to assert the deployed dev app responds 200 over its TLD hostname
- [ ] Task 4: Add a prod-app reachability test covering `deploy` → 200 over TLD hostname
- [ ] Task 5: Wire reachability into `node:remove` / `app:remove` regression — assert reachability gone afterward
- [ ] Task 6: Document the reachability assertion pattern so future E2E tests can opt in cheaply

---

## File Map

- Create `app/E2E/Support/E2EReachability.php`: encapsulate the `dig` + `curl -k` over-WG-via-control-node pattern. Two static methods: `assertDnsResolvesOverWg(E2EInstance $control, string $hostname, string $expectedIp, string $dnsServer = '10.6.0.1')` and `assertHttpReachable(E2EInstance $control, string $url, int $expectedStatus = 200)`. Both run inside the control container via `E2ECommand::ssh()`.
- Create `tests/E2E/Support/GatewayDnsReachableTest.php`: smallest credible test. Topology = gateway only (or `E2ETopologyKind::*` whichever currently bundles gateway-via-control). Provision gateway with TLD `gateway`. From the control, `dig orbit.gateway @10.6.0.1` must resolve to the gateway's WG IP, then `curl -k https://orbit.gateway/` must return 200 (gateway API root).
- Modify `tests/E2E/AppNewProvisioningTest.php` (or add a sibling `AppNewReachableTest.php` if mixing concerns muddies it): after the existing state-convergence assertions, run the new reachability helpers — control resolves `<app-name>.<app-tld>` to the app node's WG IP and gets 200 back.
- Create `tests/E2E/AppDeployedReachableTest.php`: similar but exercises a `deploy` against a prod app node. Stand up a fake site (echo `<h1>orbit-e2e-deploy-marker</h1>` from a tiny `index.php`), run `app:new --environment=production` + `deploy`, then assert 200 + marker string from the control over the TLD.
- Create `tests/E2E/AppRemoveReachabilityTest.php`: deploy, assert reachable, `app:remove`, assert no longer reachable (404 or connection refused, depending on Caddy behavior — pick one and lock it in as the contract).
- Modify `docs/abstractions/` (or wherever cross-cutting E2E patterns live): document the new reachability helper and when to use it.

---

## Decisions & Open Questions

**Decided:**
- **Reachability is verified from the control node, not from the host.** The flow Orbit cares about is "a human or agent on a control node visits a hostname." Verifying from the Docker host bypasses WG entirely and proves nothing about the real path.
- **`curl -k` is acceptable.** Orbit's internal CA is not the thing under test here. A separate (small) follow-up could add a CA-trust assertion if useful.
- **Topology-driver scenarios only.** This plan does not introduce new topology kinds. It uses the existing `Control + Gateway + AppDev + AppProd` shapes already exercised by `AppNewProvisioningTest`.

**Open questions:**
- **Caddy-default behavior after `app:remove`.** Today, removing an app leaves Caddy with no site for that hostname. Does Caddy return 404 (current behavior we observed for `http://orbit.gateway/` after legacy UI removal), or should Orbit explicitly install a deny block? Either is fine; we just need to pick and lock it in. **Recommend:** 404 — minimal config, mirrors what we already saw on the live gateway.
- **Test isolation.** Reachability tests share WG networks. If multiple tests run in parallel and reuse a topology, one test's `app:remove` could affect another's reachability assertion. **Recommend:** ensure each reachability test acquires a fresh topology lease (or document the constraint and serialize the reachability group).
- **Smoke vs E2E.** Does this plan introduce a `--group=reachability` Pest selector for faster local runs? **Recommend:** yes — tag tests `pest()->group('e2e-feature', 'e2e-feature-reachability')` so they can be run on their own.

---

### Task 1: `assertDnsResolvesOverWg` helper + minimum-viable DNS reachability test

**Files:**
- Create `app/E2E/Support/E2EReachability.php` (DNS half only for this task).
- Create `tests/E2E/GatewayDnsReachableTest.php` (DNS-only assertion).

**Summary:** From the control container, run `dig +short <name>.<tld> @10.6.0.1` and assert the answer equals the expected WG IP. This is the smallest credible test that proves the DNS provisioning plan's output is reachable over WG.

- [ ] Step 1: Write the failing test against the topology (gateway DNS provisioning plan must already be landed; otherwise this test cannot pass).
- [ ] Step 2: Implement `E2EReachability::assertDnsResolvesOverWg`.
- [ ] Step 3: Run, observe pass.

### Task 2: `assertHttpReachable` helper + gateway-self reachability test

**Files:**
- Modify `app/E2E/Support/E2EReachability.php` (add HTTP half).
- Modify `tests/E2E/GatewayDnsReachableTest.php` (add HTTP assertion).

**Summary:** Extend the helper to wrap `curl -k -s -o /dev/null -w "%{http_code}" <url>` over SSH to the control container. Assert status equals expected.

- [ ] Step 1: Failing test asserting `curl -k https://<gateway-name>.<gateway-tld>/` returns 200 from the control.
- [ ] Step 2: Implement helper.
- [ ] Step 3: Run, pass.

### Task 3: App-new reachability (dev TLD)

**Files:**
- Modify `tests/E2E/AppNewProvisioningTest.php` (preferred — keeps the existing topology hot) or create `tests/E2E/AppNewReachableTest.php` (if mixing concerns hurts readability — judge during implementation).

**Summary:** After the existing state-convergence assertions, drop in `assertHttpReachable($control, "https://<app-name>.<app-tld>/", 200)`. Depending on what `app:new` puts on disk by default, this may need a `echo OK` placeholder route installed before the assertion — wire the minimum needed.

- [ ] Step 1: Run existing test; observe it does not assert reachability. Confirm.
- [ ] Step 2: Add a placeholder route or minimal `index.php` that returns 200 — whatever's least invasive.
- [ ] Step 3: Add the reachability assertion. Run. Fix any DNS/Caddy/Process gaps surfaced.

### Task 4: Prod-app deploy → reachability

**Files:**
- Create `tests/E2E/AppDeployedReachableTest.php`.

**Summary:** Topology with prod app node. `app:new --environment=production`, then `deploy` with a tiny app that echoes a marker. Control asserts 200 + marker via TLD hostname.

- [ ] Step 1: Sketch the fake-app fixture (minimal PHP source committed to a test-only repo path).
- [ ] Step 2: Wire `deploy` invocation.
- [ ] Step 3: Assert.

### Task 5: Reachability regression on `app:remove`

**Files:**
- Create `tests/E2E/AppRemoveReachabilityTest.php`.

**Summary:** Deploy app, assert reachable, run `app:remove`, assert *not* reachable (status code per the locked-in contract from "Open questions").

- [ ] Step 1: Deploy + reach (reuses Task 4 fixture).
- [ ] Step 2: Remove.
- [ ] Step 3: Assert post-remove status.

### Task 6: Document the pattern

**Files:**
- Modify or create the appropriate cross-cutting E2E doc (likely under `docs/abstractions/` or `tests/E2E/Support/README.md` if one exists).

**Summary:** One short page: when to add a reachability assertion, how to use `E2EReachability`, what topology kinds support it, the `e2e-feature-reachability` group tag.

- [ ] Step 1: Draft.
- [ ] Step 2: Cross-link from any existing E2E authoring guide.

---

## Verification Gate

Before marking this plan complete, all of:
- All new tests in this plan pass under the configured E2E lane.
- `pest --group=e2e-feature-reachability` runs the new tests as a focused subset.
- A second pass of the audit greps (`->get('http`, `reachable`, `curl`) shows the new tests as the only legitimate hits, with no false negatives elsewhere.
