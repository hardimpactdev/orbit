import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { RepairFixture } from "./fixtures/production-smoke/repair.ts";

Deno.test("repair planning and the shipped effects gate never create tasks or start factories", async () => {
  for (
    const [apply, effects] of [[false, true], [true, false], [false, false]]
  ) {
    const f = new RepairFixture();
    await f.prepare();
    assertEquals((await f.repair(apply, effects)).status, "planned");
    assertEquals(f.repairPosts, []);
    assertEquals(f.starts, 0);
  }
});
Deno.test("one bound repair is durably recorded before the existing task factory starts", async () => {
  const f = new RepairFixture();
  await f.prepare();
  f.startFactory = (workItem) => {
    assertEquals(
      f.stored.get("repair-10")?.repair,
      f.repairs.get("smoke-failure.7.10.repair"),
    );
    f.factory.set(workItem, { workItem, status: "active" });
    return Promise.resolve();
  };
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.repairPosts.map((r) => ({ key: r.key, body: r.body })), [{
    key: "smoke-failure.7.10.repair",
    body: { failure_key: "smoke-failure.7.10" },
  }]);
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.starts, 1);
  assertEquals(f.repairs.size, 1);
});
for (
  const failure of [
    "unknown",
    "pending",
    "empty",
    "clean",
    "skipped",
    "run",
    "request",
    "instance",
  ]
) {
  Deno.test(`${failure} smoke cannot create a code repair`, async () => {
    const f = new RepairFixture();
    await f.prepare();
    if (["unknown", "pending"].includes(failure)) {
      f.receipt.smoke!.state = failure;
    }
    if (failure === "empty") f.receipt.smoke!.tests = 0;
    if (failure === "clean") f.receipt.smoke!.failures = 0;
    if (failure === "skipped") {
      f.receipt.smoke!.skipped = f.receipt.smoke!.tests;
    }
    if (failure === "run") Object.assign(f.receipt.smoke!, { run_id: null });
    if (failure === "request") f.receipt.request_key = "foreign";
    if (failure === "instance") f.receipt.operation!.target_id = 99;
    assertEquals((await f.repair()).status, "not_failed");
    assertEquals(f.repairPosts, []);
    assertEquals(f.starts, 0);
  });
}
for (const when of ["before", "after"]) {
  Deno.test(`a reply lost ${when} Gateway admission reconciles the same key after restart`, async () => {
    const f = new RepairFixture();
    await f.prepare();
    f.repairLostBefore = when === "before";
    f.repairLostAfter = when === "after";
    assertEquals((await f.repair()).status, "assistance_required");
    f.repairLostBefore = false;
    f.repairLostAfter = false;
    assertEquals((await f.repair()).status, "started");
    assertEquals(f.repairs.size, 1);
    assertEquals(f.starts, 1);
    assertEquals(new Set(f.repairPosts.map((r) => r.key)).size, 1);
    assertEquals(f.repairPosts.length, when === "before" ? 2 : 1);
  });
}
Deno.test("a process lost after the Gateway reply cannot create a second repair", async () => {
  const f = new RepairFixture();
  await f.prepare();
  f.crashAfterReceipt = true;
  await assertRejects(() => f.repair());
  assertEquals(f.repairs.size, 1);
  assertEquals(f.starts, 0);
  f.crashAfterReceipt = false;
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.repairPosts.length, 1);
  assertEquals(f.starts, 1);
});
Deno.test("a lost factory reply reconciles official state rather than resetting or starting twice", async () => {
  const f = new RepairFixture();
  await f.prepare();
  f.lostFactory = true;
  assertEquals((await f.repair()).status, "assistance_required");
  assertEquals(f.starts, 1);
  f.lostFactory = false;
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.starts, 1);
});
for (const status of ["completed", "cancelled"]) {
  Deno.test(`${status} repair remains the same group and never restarts`, async () => {
    const f = new RepairFixture();
    await f.prepare();
    await f.repair();
    f.groupStatus = status;
    f.factory.clear();
    assertEquals((await f.repair()).status, "completed");
    assertEquals(f.repairs.size, 1);
    assertEquals(f.starts, 1);
  });
}
Deno.test("historical pending repair retries after successful recovery and moved selection", async () => {
  const f = new RepairFixture();
  await f.prepare();
  f.refused = true;
  assertEquals((await f.repair()).status, "assistance_required");
  f.refused = false;
  f.observed.selected = {
    deployment_id: 11,
    commit: "a".repeat(40),
    release: "release-9",
  };
  const state = f.stored.get("state")!;
  f.stored.set("state", {
    ...state,
    status: "passed",
    job: null,
    selected: f.observed.selected,
  });
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.repairs.size, 1);
  assertEquals(f.repairPosts.at(-1)?.key, "smoke-failure.7.10.repair");
});
Deno.test("repair creation does not depend on rollback success", async () => {
  const f = new RepairFixture();
  await f.prepare();
  f.refuseRollback = true;
  await f.recover();
  assertEquals((await f.repair()).status, "started");
  assertEquals(f.repairs.size, 1);
});
for (const bad of ["lookup", "group", "config", "repository"]) {
  Deno.test(`wrong ${bad} evidence refuses factory routing`, async () => {
    const f = new RepairFixture();
    await f.prepare();
    if (bad === "lookup") f.badLookup = true;
    if (bad === "group") f.badGroup = true;
    if (bad === "config") f.project.productionSmoke!.ref = "another-ref";
    if (bad === "repository") {
      f.observed.repository_url = "https://github.com/other/repo";
    }
    assertEquals((await f.repair()).status, "assistance_required");
    assertEquals(f.starts, 0);
  });
}
Deno.test("a different failed deployment gets a different repair incident", async () => {
  const f = new RepairFixture();
  await f.prepare();
  await f.repair();
  const job = f.stored.get("job-10")!;
  const request = { ...job.request as object, deployment_id: 12 };
  f.stored.set("job-12", { ...job, key: "smoke.7.12", request });
  f.receipt = {
    ...f.receipt,
    request_key: "smoke.7.12",
    operation: { ...f.receipt.operation!, input: request },
    smoke: { ...f.receipt.smoke!, ...request },
  };
  assertEquals((await f.repair(true, true, 12)).status, "started");
  assertEquals(f.repairs.size, 2);
  assertStringIncludes(f.repairPosts.at(-1)!.key!, "smoke-failure.7.12.repair");
});
