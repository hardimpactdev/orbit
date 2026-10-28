import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { RecoveryFixture } from "./fixtures/production-smoke/recovery.ts";

Deno.test("the shipped recovery gate never creates a hold or dispatch", async () => {
  for (const [apply, effects] of [[true, false], [false, true]]) {
    const f = new RecoveryFixture();
    assertEquals((await f.cycle(apply, effects)).status, "disabled");
    assertEquals(f.requests, []);
  }
});
Deno.test("hold precedes admission and lost hold replies reconcile across restart", async () => {
  const f = new RecoveryFixture();
  f.lostControl = true;
  await f.cycle();
  assertEquals(f.held, true);
  assertEquals(f.admitted.size, 0);
  f.lostControl = false;
  await f.cycle();
  assertEquals(
    f.posts.filter((r) =>
      r.path.endsWith("/control") &&
      (r.body as { action: string }).action === "hold"
    ).length,
    1,
  );
  assertEquals(f.admitted.size, 1);
  f.pass();
  assertEquals((await f.cycle()).status, "passed");
  assertEquals(f.held, false);
  const count = f.posts.length;
  await f.cycle();
  assertEquals(f.posts.length, count);
});
Deno.test("a baseline from before hold acquisition is smoked again", async () => {
  const f = new RecoveryFixture();
  await f.tick("baseline");
  f.pass();
  f.baseline = structuredClone(f.receipt);
  await f.tick();
  await f.cycle();
  assertEquals(f.held, true);
  assertEquals(f.admitted.size, 2);
  assertEquals(f.receipt.request_key, "smoke.7.9.retry.1");
});
Deno.test("terminal unknown retries persist backoff and never roll back", async () => {
  const f = new RecoveryFixture();
  await f.cycle();
  // Use the real result schema, with no verified JUnit failure.
  f.pass();
  f.receipt.smoke!.state = "unknown";
  f.receipt.operation!.status = "failed";
  await f.cycle();
  assertEquals(f.admitted.size, 2);
  f.pass();
  f.receipt.smoke!.state = "unknown";
  f.receipt.operation!.status = "failed";
  const life = await f.cycle();
  assertEquals(f.admitted.size, 2);
  assertEquals(life.generation, 1);
  f.now = Number(life.due) + 1;
  await f.cycle();
  assertEquals(f.admitted.size, 3);
  assertEquals(f.effects, []);
  assertEquals(f.held, true);
});
Deno.test("absence retries the same admission key; pending Gateway work never gets a new generation", async () => {
  const f = new RecoveryFixture();
  f.lostBefore = true;
  await f.cycle();
  f.now += 601_000;
  await f.cycle();
  const keys = f.posts.filter((r) => r.path.endsWith("/smoke")).map((r) =>
    r.key
  );
  assert(keys.length >= 2);
  assertEquals(new Set(keys).size, 1);
  assertEquals(f.effects, []);
  f.lostBefore = false;
  f.now += 901_000;
  await f.cycle();
  f.receipt.operation!.status = "running";
  f.now += 901_000;
  const count = f.admitted.size;
  await f.cycle();
  assertEquals(f.admitted.size, count);
  assertEquals(f.held, true);
});
Deno.test("a pass read after local timeout releases instead of retrying or rolling back", async () => {
  const f = new RecoveryFixture();
  await f.cycle();
  f.now += 601_000;
  f.receipt.operation!.status = "running";
  await f.cycle();
  f.pass();
  f.receipt.operation!.status = "succeeded";
  assertEquals((await f.cycle()).status, "passed");
  assertEquals(f.held, false);
  assertEquals(f.admitted.size, 1);
  assertEquals(f.effects, []);
});
Deno.test("verified failure rolls back, smokes the restored selection, then releases", async () => {
  const f = new RecoveryFixture();
  await f.prepare();
  assertEquals((await f.cycle()).status, "recovering");
  assertEquals(f.held, true);
  f.complete();
  const restored = await f.restoredSmoke();
  assertEquals(restored.status, "passed", JSON.stringify(restored));
  assertEquals(f.held, false);
  assertEquals(f.effects.filter((r) => r.path.endsWith("/rollback")).length, 1);
  const release = f.posts.findLast((r) =>
    (r.body as { action?: string })?.action === "release"
  )!;
  assertEquals(
    (release.body as { expected: { deployment_id: number } }).expected
      .deployment_id,
    11,
  );
});
Deno.test("failed restored smoke holds assistance without rolling back another release", async () => {
  const f = new RecoveryFixture();
  await f.prepare();
  await f.cycle();
  f.complete();
  await f.cycle();
  await f.cycle();
  f.pass();
  f.receipt.smoke!.state = "failed";
  f.receipt.smoke!.failures = 1;
  assertEquals((await f.cycle()).status, "assistance_required");
  assertEquals(f.held, true);
  assertEquals(f.effects.filter((r) => r.path.endsWith("/rollback")).length, 1);
});
Deno.test("queued-auto cancellation requires a pinned redeploy and its selected proof", async () => {
  const f = new RecoveryFixture();
  await f.prepare();
  f.needsRedeploy = true;
  await f.cycle();
  f.complete();
  await f.cycle();
  assert(f.redeploy);
  assertEquals(
    f.redeploy.input && (f.redeploy.input as { commit: string }).commit,
    "a".repeat(40),
  );
  f.redeploy.status = "succeeded";
  f.observed.selected = {
    deployment_id: 12,
    commit: "a".repeat(40),
    release: "release-12",
  };
  f.observed.retained_releases.push("release-12");
  f.redeployment = { ...f.observed.selected, status: "succeeded" };
  const restored = await f.restoredSmoke();
  assertEquals(restored.status, "passed", JSON.stringify(restored));
  assertEquals(f.held, false);
});
Deno.test("unreadable controls and changed ownership never release or roll back", async () => {
  for (const kind of ["receipt", "repository", "instance"]) {
    const f = new RecoveryFixture();
    await f.cycle();
    if (kind === "receipt") f.brokenControl = true;
    if (kind === "repository") f.observed.repository_url = "other";
    if (kind === "instance") f.observed.instance_id = 99;
    assertEquals((await f.cycle()).status, "assistance_required");
    assertEquals(f.held, true);
    assertEquals(f.effects, []);
  }
});

Deno.test("configuration changes retain the original hold without admitting on another target", async () => {
  const f = new RecoveryFixture();
  await f.cycle();
  const count = f.posts.length;
  f.project.productionSmoke!.instance = 8;
  f.observed.instance_id = 8;
  assertEquals((await f.cycle()).status, "assistance_required");
  assertEquals(f.posts.length, count);
  assertEquals(f.held, true);
});
Deno.test("a smoke admitted before hold must finish before a fresh passing generation can release it", async () => {
  const f = new RecoveryFixture();
  await f.tick("baseline");
  await f.cycle();
  assertEquals(f.admitted.size, 1);
  f.pass();
  await f.cycle();
  assertEquals(f.admitted.size, 2);
  assertEquals(f.held, true);
  f.pass();
  assertEquals((await f.cycle()).status, "passed");
  assertEquals(f.held, false);
});

Deno.test("a lost release reply is reconciled before holding a newer deployment", async () => {
  const f = new RecoveryFixture();
  await f.cycle();
  f.pass();
  f.lostControl = true;
  await f.cycle();
  assertEquals(f.held, false);
  f.lostControl = false;
  f.observed.selected = {
    deployment_id: 10,
    commit: "c".repeat(40),
    release: "release-10",
  };
  f.observed.retained_releases.push("release-10");
  await f.cycle();
  assertEquals(f.held, true);
  assertEquals(
    f.posts.filter((r) =>
      r.path.endsWith("/control") &&
      (r.body as { action: string }).action === "hold"
    ).length,
    2,
  );
  assertEquals(
    f.posts.filter((r) =>
      r.path.endsWith("/control") &&
      (r.body as { action: string }).action === "release"
    ).length,
    1,
  );
});
