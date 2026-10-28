import { assertEquals } from "jsr:@std/assert@1.0.19";
import { FailureFixture } from "./fixtures/production-smoke/failure.ts";

Deno.test("failure planning and the shipped effects gate make no mutation", async () => {
  for (
    const [apply, effects] of [[false, true], [true, false], [false, false]]
  ) {
    const f = new FailureFixture();
    await f.prepare();
    assertEquals((await f.recover(apply, effects)).status, "planned");
    assertEquals(f.posts, []);
    assertEquals(
      f.requests.some((r) =>
        r.path.includes("/failure/") || r.path.includes("/rollback")
      ),
      false,
    );
  }
});
Deno.test("a new smoke snapshots the last good release before a failure", async () => {
  const f = new FailureFixture();
  await f.prepare();
  assertEquals(
    f.stored.get("job-10")?.previous_good,
    f.baseline.smoke &&
      { deployment_id: 9, commit: "a".repeat(40), release: "release-9" },
  );
});
for (
  const scenario of [
    "missing",
    "pending",
    "unknown",
    "cancelled",
    "empty",
    "clean",
    "skipped",
    "forged",
    "run_id",
    "run_attempt",
    "workflow_sha",
    "run_url",
    "operation_id",
    "request_key",
    "unreadable",
    "outage",
  ]
) {
  Deno.test(`${scenario} smoke requires a hold and never blocks or rolls back`, async () => {
    const f = new FailureFixture();
    await f.prepare();
    if (scenario === "missing") {
      Object.assign(f.receipt, { found: false, operation: null, smoke: null });
    }
    if (scenario === "pending" || scenario === "unknown") {
      f.receipt.smoke!.state = scenario;
    }
    if (scenario === "cancelled") {
      f.receipt.operation!.status = "cancelled";
      f.receipt.smoke!.state = "unknown";
    }
    if (scenario === "empty") f.receipt.smoke!.tests = 0;
    if (scenario === "clean") f.receipt.smoke!.failures = 0;
    if (scenario === "skipped") {
      f.receipt.smoke!.skipped = f.receipt.smoke!.tests;
    }
    if (scenario === "forged") {
      Object.assign(f.receipt.smoke!, { commit: "f".repeat(40) });
    }
    if (
      ["run_id", "run_attempt", "workflow_sha", "run_url"].includes(scenario)
    ) Object.assign(f.receipt.smoke!, { [scenario]: null });
    if (scenario === "operation_id") f.receipt.operation!.id = 99;
    if (scenario === "request_key") f.receipt.request_key = "foreign-key";
    if (scenario === "unreadable") {
      Object.assign(f.receipt, { unexpected: true });
    }
    if (scenario === "outage") f.outage = true;
    f.now += 720_000;
    assertEquals((await f.recover()).status, "hold_required");
    assertEquals(f.effects, []);
  });
}
Deno.test("a passing smoke never enters failure recovery", async () => {
  const f = new FailureFixture();
  await f.prepare();
  f.pass();
  assertEquals((await f.recover()).status, "idle");
  assertEquals(f.effects, []);
});
Deno.test("verified failure is blocked before guarded rollback; replay keeps the same keys", async () => {
  const f = new FailureFixture();
  await f.prepare();
  assertEquals((await f.recover()).status, "rollback_pending");
  assertEquals(f.effects.map((r) => r.key), [
    "smoke-failure.7.10",
    "smoke-failure.7.10.rollback",
  ]);
  assertEquals(f.effects[1].body, {
    failure_key: "smoke-failure.7.10",
    expected: {
      deployment_id: 10,
      commit: "c".repeat(40),
      release: "release-10",
    },
    target: { deployment_id: 9, commit: "a".repeat(40), release: "release-9" },
    target_smoke_request_key: "smoke.7.9",
  });
  await f.recover();
  assertEquals(f.effects.length, 2);
  f.complete();
  assertEquals((await f.recover()).status, "rolled_back");
  assertEquals(f.effects.length, 2);
  assertEquals(f.block?.blocked, true);
});
for (const lost of ["block", "rollback"]) {
  Deno.test(`lost ${lost} reply reconciles its receipt after restart`, async () => {
    const f = new FailureFixture();
    await f.prepare();
    f.lostBlock = lost === "block";
    f.lostRollback = lost === "rollback";
    await f.recover();
    f.stored.delete("recovery"); // Only permanent per-deployment resources survive.
    f.lostBlock = f.lostRollback = false;
    await f.recover();
    assertEquals(
      f.effects.filter((r) => r.path.endsWith("/failure")).length,
      1,
    );
    assertEquals(
      f.effects.filter((r) => r.path.endsWith("/rollback")).length,
      1,
    );
    f.complete();
    assertEquals((await f.recover()).status, "rolled_back");
  });
}
for (const when of ["before block", "after block"]) {
  Deno.test(`selection moves ${when}: retain the block without rollback`, async () => {
    const f = new FailureFixture();
    await f.prepare();
    const move = () => {
      f.observed.selected.deployment_id = 12;
    };
    if (when === "before block") move();
    else f.afterBlock = move;
    assertEquals((await f.recover()).status, "superseded");
    assertEquals(f.effects.length, 1);
    assertEquals(f.block?.blocked, true);
  });
}
for (const scenario of ["pruned", "no baseline", "bad proof"]) {
  Deno.test(`${scenario} rollback target leaves the block and requests assistance`, async () => {
    const f = new FailureFixture();
    await f.prepare();
    if (scenario === "pruned") f.observed.retained_releases = ["release-10"];
    if (scenario === "no baseline") {
      f.stored.get("job-10")!.previous_good = null;
    }
    if (scenario === "bad proof") f.baseline.smoke!.failures = 1;
    assertEquals((await f.recover()).status, "assistance_required");
    assertEquals(f.effects.length, 1);
    assertEquals(f.block?.blocked, true);
  });
}
Deno.test("the Gateway can refuse queued rollback execution after selection changes", async () => {
  const f = new FailureFixture();
  await f.prepare();
  await f.recover();
  f.beforeRollback = () => {
    f.observed.selected.deployment_id = 12;
    f.rollback!.status = "failed";
    f.rollback!.error_code = "instance.smoke_selection_changed";
  };
  const result = await f.recover();
  assertEquals(result.status, "assistance_required");
  assertEquals(result.reason, "instance.smoke_selection_changed");
  assertEquals(f.block?.blocked, true);
  assertEquals(f.effects.length, 2);
});
for (
  const scenario of [
    "wrong block",
    "wrong operation",
    "wrong selection",
    "wrong deployment",
    "cancelled",
  ]
) {
  Deno.test(`${scenario} recovery evidence never proves restored code`, async () => {
    const f = new FailureFixture();
    await f.prepare();
    if (scenario === "wrong block") {
      f.mutateReceipt = (value) => {
        if ("blocked" in value) value.instance_id = 99;
        if (value.block) Object.assign(value.block, { instance_id: 99 });
      };
    }
    await f.recover();
    if (scenario === "wrong operation") f.rollback!.target_id = 99;
    if (scenario === "cancelled") f.rollback!.status = "cancelled";
    if (scenario === "wrong selection" || scenario === "wrong deployment") {
      f.complete();
      if (scenario === "wrong selection") f.observed.selected.release = "other";
      else f.deployment!.commit = "f".repeat(40);
    }
    const result = await f.recover();
    assertEquals(
      ["assistance_required", "hold_required"].includes(String(result.status)),
      true,
    );
  });
}
Deno.test("changed project eligibility or policy refuses all recovery effects", async () => {
  for (
    const patch of [
      "repository",
      "instance",
      "production",
      "config",
      "disabled",
    ]
  ) {
    const f = new FailureFixture();
    await f.prepare();
    if (patch === "repository") {
      f.observed.repository_url = "git@github.com:other/repo.git";
    }
    if (patch === "instance") f.observed.instance_id = 99;
    if (patch === "production") f.observed.production = false;
    if (patch === "config") f.project.productionSmoke!.ref = "other";
    if (patch === "disabled") delete f.project.productionSmoke;
    assertEquals((await f.recover()).status, "hold_required");
    assertEquals(f.effects, []);
  }
});

Deno.test("a historical failed job can block after the observer supersedes it", async () => {
  const f = new FailureFixture();
  await f.prepare();
  f.observed.selected.deployment_id = 12;
  await f.tick();
  assertEquals(f.stored.get("state")?.job, null);
  assertEquals((await f.recover(true, true, 10)).status, "superseded");
  assertEquals(f.block?.blocked, true);
  assertEquals(f.effects.length, 1);
});
Deno.test("loss before admission resends only the saved body and key", async () => {
  const f = new FailureFixture();
  await f.prepare();
  f.transport = true;
  await f.recover();
  f.transport = false;
  await f.recover();
  assertEquals(f.effects.length, 2);
  assertEquals(f.block?.request_key, "smoke-failure.7.10");
  assertEquals(f.rollback?.input.failure_key, f.block?.request_key);
});
Deno.test("pruned targets after a lost rollback admission cannot choose another release", async () => {
  const f = new FailureFixture();
  await f.prepare();
  await f.recover();
  f.rollback = null;
  f.observed.retained_releases = ["release-10"];
  assertEquals((await f.recover()).status, "assistance_required");
  assertEquals(f.effects.length, 2);
  assertEquals(f.block?.blocked, true);
});

Deno.test("JUnit errors recover code without relying on a workflow failure conclusion", async () => {
  const f = new FailureFixture();
  await f.prepare();
  Object.assign(f.receipt.smoke!, {
    failures: 0,
    errors: 1,
    conclusion: "cancelled",
  });
  assertEquals((await f.recover()).status, "rollback_pending");
  assertEquals(f.block?.blocked, true);
});
