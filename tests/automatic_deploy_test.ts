import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import {
  A,
  B,
  C,
  D,
  PolicyFixture,
  SIDE,
} from "./_support/automatic_deploy.ts";
import { trigger } from "../extensions/behaviors/automatic-deploy/policy.ts";

Deno.test("P01 newest green is pinned, then quiet", async () => {
  const f = new PolicyFixture();
  f.gateway.chain([A, B, C]);
  assertEquals(await f.tick(), "started");
  assertEquals(f.gateway.starts[0].commit, C);
  f.gateway.finish();
  assertEquals(await f.tick(), "up_to_date");
  const writes = f.stateWrites;
  await f.tick();
  assertEquals(f.gateway.starts.length, 1);
  assertEquals(f.stateWrites, writes);
});
Deno.test("P02 pending is rechecked on the next tick", async () => {
  const f = new PolicyFixture();
  f.gateway.check(B, null, "queued");
  assertEquals(await f.tick(), "gate_pending");
  await f.tick();
  f.gateway.check(B, "success");
  assertEquals(await f.tick(), "started");
});
Deno.test("P03 red is cached until head changes or expiry", async () => {
  const f = new PolicyFixture();
  f.gateway.check(B, "failure");
  assertEquals(await f.tick(), "gate_failed");
  const reads = f.gateway.reads.length;
  await f.tick();
  assertEquals(f.gateway.reads.slice(reads), ["observe", "head"]);
  f.gateway.chain([A, B, C]);
  assertEquals(await f.tick(), "started");
});
Deno.test("P04 failed check is read after five minutes", async () => {
  const f = new PolicyFixture();
  f.gateway.check(B, "failure");
  await f.tick();
  f.gateway.check(B, "success");
  f.advance(4);
  assertEquals(await f.tick(), "gate_failed");
  f.advance(1);
  assertEquals(await f.tick(), "started");
});
Deno.test("P05 pending head is read once per walk per tick", async () => {
  const f = new PolicyFixture();
  f.gateway.check(B, null, "queued");
  await f.tick();
  await f.tick();
  assertEquals(f.gateway.reads.filter((r) => r === `checks:${B}`).length, 2);
});
Deno.test("P06 three transient attempts stop after 10 and 20 minute waits", async () => {
  const f = new PolicyFixture();
  await f.tick();
  f.gateway.finish("failed");
  f.advance(10);
  assertEquals(await f.tick(), "started");
  f.gateway.finish("failed");
  f.advance(19);
  assertEquals(await f.tick(), "backing_off");
  f.advance(1);
  assertEquals(await f.tick(), "started");
  f.gateway.finish("failed");
  f.advance(120);
  assertEquals(await f.tick(), "no_green_commit");
  assertEquals(f.gateway.starts.length, 3);
  assertEquals(new Set(f.gateway.starts.map((s) => s.key)).size, 3);
});
Deno.test("P07 newest green behind red head", async () => {
  const f = new PolicyFixture();
  f.gateway.chain([A, B, C]);
  f.gateway.check(C, "failure");
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, B);
});
Deno.test("P08 no check run means pending", async () => {
  const f = new PolicyFixture();
  f.gateway.runs.delete(B);
  assertEquals(await f.tick(), "gate_pending");
  assertEquals(f.gateway.starts, []);
});
Deno.test("P09 ungated project deploys the head without checks", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.gate = null;
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, B);
  assertEquals(f.gateway.reads, ["observe", "head"]);
});
Deno.test("P10 lasting own-step failure blocks until manual reset or new commit", async () => {
  const f = new PolicyFixture();
  f.gateway.failure(B, "before_activation", "deployment.step_failed");
  assertEquals(await f.tick(), "no_green_commit");
  f.gateway.row({ status: "failed", commit: A });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
});
Deno.test("P11 new commit proceeds after failed one", async () => {
  const f = new PolicyFixture();
  f.gateway.failure(B, "after_activation");
  f.gateway.chain([A, B, C]);
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, C);
});
Deno.test("P12 ungated failed head stays blocked", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.gate = null;
  f.gateway.failure(B, "before_activation");
  assertEquals(await f.tick(), "commit_failed");
  assertEquals(f.gateway.starts, []);
});
Deno.test("P13 first retry is at ten minutes", async () => {
  const f = new PolicyFixture();
  await f.tick();
  f.gateway.finish("failed");
  f.advance(9);
  assertEquals(await f.tick(), "backing_off");
  f.advance(1);
  assertEquals(await f.tick(), "started");
});
Deno.test("P14 rollback pauses, explicit resume keeps rolled-away block", async () => {
  const f = new PolicyFixture();
  f.gateway.chain([A, B, C]);
  f.gateway.row({ commit: C, triggered_by: trigger });
  const row = f.gateway.row({ kind: "rollback", commit: A });
  assertEquals(await f.tick(), "paused");
  assertEquals(f.state.pause?.deployment_id, row.id);
  f.gateway.resume();
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, B);
  assertEquals(f.state.blocked, [C]);
});
Deno.test("P15 rolled-away head is never redeployed, next commit proceeds", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ commit: B });
  f.gateway.row({ kind: "rollback", commit: A });
  await f.tick();
  f.gateway.resume();
  assertEquals(await f.tick(), "no_green_commit");
  f.gateway.chain([A, B, C]);
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, C);
});
Deno.test("P16 resume before a tick still blocks rolled-away head", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ commit: B });
  f.gateway.row({ kind: "rollback", commit: A });
  f.gateway.resume();
  assertEquals(await f.tick(), "no_green_commit");
  assertEquals(f.state.blocked, [B]);
});
Deno.test("P17 manual attempt after rollback does not erase block", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ commit: B });
  f.gateway.row({ kind: "rollback", commit: A });
  f.gateway.row({ commit: B, status: "failed" });
  await f.tick();
  assertEquals(f.state.pause?.reason, "manual_deploy");
  f.gateway.resume();
  assertEquals(await f.tick(), "no_green_commit");
  f.gateway.chain([A, B, D]);
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, D);
});
Deno.test("P18 block survives history pruning", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ commit: B });
  const rollback = f.gateway.row({ kind: "rollback", commit: A });
  f.gateway.resume();
  await f.tick();
  f.gateway.observation.history = [rollback];
  assertEquals(await f.tick(), "no_green_commit");
  assertEquals(f.state.blocked, [B]);
});
Deno.test("P19 running rollback pauses even without a commit", async () => {
  const f = new PolicyFixture();
  f.gateway.row({
    kind: "rollback",
    commit: null,
    status: "running",
    finished_at: null,
  });
  assertEquals(await f.tick(), "paused");
  assertEquals(f.gateway.starts, []);
  assertEquals(f.alerts, []);
});
Deno.test("P20 Gateway pause after admission is observed without replay", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  f.gateway.row({ kind: "rollback", commit: A });
  op.status = "failed";
  op.error_code = "deployment.paused";
  f.gateway.observation.holder = null;
  assertEquals(await f.tick(), "paused");
  assertEquals(f.gateway.starts.length, 1);
  assertEquals(f.gateway.requests.at(-1), `GET /api/v1/operations/${op.id}`);
});
Deno.test("P21 Gateway superseded receipt stays paused after rollback", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "cancelled";
  op.error_code = "operation.superseded";
  f.gateway.row({ kind: "rollback", commit: A });
  f.gateway.observation.holder = null;
  assertEquals(await f.tick(), "paused");
  await f.tick();
  assertEquals(f.gateway.starts.length, 1);
});
Deno.test("P22 manual deployment pauses and resumes from its commit", async () => {
  const f = new PolicyFixture();
  f.gateway.chain([A, B, C]);
  f.gateway.row({ commit: B });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  await f.tick();
  assertEquals(f.gateway.starts[0].commit, C);
});
Deno.test("P23 busy records one notable skip", async () => {
  const f = new PolicyFixture();
  f.gateway.hold();
  assertEquals(await f.tick(), "busy");
  assertEquals(f.state.operation_id, 900);
  assertEquals(
    (f.decision.activity as { description: string }).description,
    "auto_deploy.busy",
  );
  await f.tick();
  assertEquals(f.decision.activity, null);
  assertEquals(f.gateway.starts, []);
});
Deno.test("P24 first deployment must be manual", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.history = [];
  assertEquals(await f.tick(), "not_deployed");
  assertEquals(f.gateway.reads, ["observe"]);
});
Deno.test("P25 unreadable gate fails tick without a start", async () => {
  for (const at of ["walk", "checks"] as const) {
    const f = new PolicyFixture();
    f.gateway.gateFailureAt = at;
    f.gateway.gateResponse = {
      status: 500,
      body: {
        error: {
          code: "server.error",
          message: "The Gateway read failed.",
          details: {},
        },
      },
    };
    assertEquals(await f.tick(), "failed");
    assertEquals(f.state.error_code, "instance.auto_deploy_gate_unavailable");
    assertEquals(f.state.message, "The Gateway read failed.");
    assertEquals(f.gateway.starts, []);
    assertEquals(f.decision.ready, false);
  }
});
Deno.test("P26 unsupported source access fails without reading checks", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.gateReadable = false;
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "instance.auto_deploy_gate_unreadable");
  assertEquals(f.gateway.reads, ["observe", "head"]);
});
Deno.test("P27 disabled Instances are untouched", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.enabled = false;
  assertEquals(await f.tick(), null);
  assertEquals(f.stateWrites, 0);
  assertEquals(f.gateway.reads, ["observe"]);
});
Deno.test("P28 one failure alert intent per commit", async () => {
  const f = new PolicyFixture();
  f.gateway.chain([A]);
  f.gateway.failure(B, "before_activation");
  await f.tick();
  await f.tick();
  assertEquals(f.alerts.length, 1);
  f.gateway.failure(C, "after_activation");
  await f.tick();
  assertEquals(f.alerts.length, 2);
  assertEquals(f.alerts.map((a) => a.subject.sha), [B, C]);
});
Deno.test("P29 no alert for a failure before enablement", async () => {
  const f = new PolicyFixture();
  f.gateway.failure(B);
  f.gateway.resume();
  await f.tick();
  assertEquals(f.alerts, []);
});
Deno.test("P30 one rollback pause alert intent", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ kind: "rollback", commit: A });
  await f.tick();
  await f.tick();
  assertEquals(f.alerts.length, 1);
  assertEquals(f.alerts[0].kind, "instance_deploy_paused");
});
Deno.test("P31 green commit alerts once per stall episode", async () => {
  const f = new PolicyFixture();
  f.gateway.hold();
  await f.tick();
  f.advance(29);
  await f.tick();
  assertEquals(f.alerts, []);
  f.advance(1);
  await f.tick();
  f.advance(30);
  await f.tick();
  assertEquals(f.alerts.length, 1);
  assertStringIncludes(f.alerts[0].summary, "busy");
  f.gateway.observation.holder = null;
  f.gateway.row({ commit: A, status: "failed" });
  assertEquals(await f.tick(), "paused");
  assertEquals(f.state.stall, null);
  f.gateway.resume();
  f.gateway.hold();
  await f.tick();
  f.advance(30);
  await f.tick();
  await f.tick();
  const stalls = f.alerts.filter((a) => a.kind === "instance_deploy_stalled");
  assertEquals(stalls.length, 2);
  assertEquals(stalls.map((a) => a.subject.sha), [B, B]);
  assertEquals(stalls[0].key === stalls[1].key, false);
});
Deno.test("P32 pending head alerts once per stall episode", async () => {
  const f = new PolicyFixture();
  f.gateway.check(B, null, "queued");
  await f.tick();
  f.advance(30);
  await f.tick();
  await f.tick();
  assertEquals(f.alerts.length, 1);
  assertStringIncludes(f.alerts[0].summary, "gate_pending");
  f.gateway.observation.holder = null;
  f.gateway.row({ commit: A, status: "failed" });
  assertEquals(await f.tick(), "paused");
  assertEquals(f.state.stall, null);
  f.gateway.resume();
  await f.tick();
  f.advance(30);
  await f.tick();
  await f.tick();
  const stalls = f.alerts.filter((a) => a.kind === "instance_deploy_stalled");
  assertEquals(stalls.length, 2);
  assertEquals(stalls.map((a) => a.subject.sha), [B, B]);
  assertEquals(stalls[0].key === stalls[1].key, false);
});
Deno.test("P33 one manual pause alert intent with resume advice", async () => {
  const f = new PolicyFixture();
  f.gateway.row({ commit: B });
  await f.tick();
  await f.tick();
  assertEquals(f.alerts.length, 1);
  assertStringIncludes(
    f.alerts[0].summary,
    "orbit instance:update 7 --auto-deploy=true",
  );
});
Deno.test("P34 queued rollback is busy with no stall", async () => {
  const f = new PolicyFixture();
  f.gateway.hold("instance.rollback");
  assertEquals(await f.tick(), "busy");
  f.advance(31);
  await f.tick();
  assertEquals(f.state.stall, null);
  assertEquals(f.alerts, []);
  assertEquals(f.gateway.starts, []);
});
Deno.test("P35 deployed commit ends the stall", async () => {
  const f = new PolicyFixture();
  await f.tick();
  assertEquals(f.state.stall?.sha, B);
  f.gateway.finish();
  await f.tick();
  assertEquals(f.state.stall, null);
});
Deno.test("P36 no GitHub repository skips alert", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.repository = null;
  f.gateway.observation.gate = null;
  f.gateway.failure(B);
  f.gateway.chain([A]);
  await f.tick();
  assertEquals(f.alerts, []);
});
Deno.test("P37 notifier payload is durable; Gateway Activity delivery is a gap", async () => {
  const f = new PolicyFixture();
  f.gateway.failure(B, "before_activation", "deployment.step_failed");
  await f.tick();
  assertEquals(f.alerts[0].kind, "instance_deploy_failed");
  assertEquals(f.alerts[0].subject, {
    target: "instance:7",
    repository: "acme/shop",
    sha: B,
    release_id: "deployment-2",
  });
  assertStringIncludes(
    f.alerts[0].summary,
    "before_activation: deployment.step_failed",
  );
  assertEquals(f.gateway.requests, []);
});
Deno.test("P38 every manual status pauses", async () => {
  for (const status of ["running", "failed", "succeeded"] as const) {
    const f = new PolicyFixture();
    f.gateway.row({ status, commit: B });
    assertEquals(await f.tick(), "paused", status);
    assertEquals(f.gateway.starts, []);
  }
});
Deno.test("P39 durable Gateway block survives resume and pruning before any tick", async () => {
  const f = new PolicyFixture();
  f.gateway.observation.blocked = [B];
  f.gateway.resume();
  assertEquals(await f.tick(), "no_green_commit");
  assertEquals(f.state.blocked, [B]);
});
Deno.test("P40 projection fields, stable since, and separate receipt and stall", async () => {
  const f = new PolicyFixture();
  f.gateway.hold();
  await f.tick();
  const since = f.state.since;
  f.advance(30);
  await f.tick();
  assertEquals(f.state.since, since);
  assertEquals(f.decision.activity, null);
  assertEquals(f.decision.projection, {
    enabled: true,
    gate: "Required checks",
    warning: null,
    result: "busy",
    commit: B,
    head: B,
    error_code: null,
    message: null,
    since,
    pause: null,
  });
  assertEquals(f.state.operation_id, 900);
  assertEquals(f.alerts[0].kind, "instance_deploy_stalled");
});
Deno.test("P41 only green first-parent descendants with exact Actions runs qualify", async () => {
  for (
    const invalid of ["side", "ancestry", "app", "sha", "duplicate"] as const
  ) {
    const f = new PolicyFixture();
    f.gateway.chain([A, B, C]);
    f.gateway.check(C, "failure");
    f.gateway.commits.push({
      sha: SIDE,
      parents: [A],
      descendsFromDeployed: true,
    });
    f.gateway.check(SIDE, "success");
    f.gateway.commits.at(-2)!.parents.push(SIDE);
    if (invalid === "side") f.gateway.check(B, "failure");
    if (invalid === "ancestry") {
      f.gateway.commits[1].descendsFromDeployed = false;
    }
    if (invalid === "app") f.gateway.runs.get(B)![0].app = "untrusted";
    if (invalid === "sha") f.gateway.runs.get(B)![0].head_sha = A;
    if (invalid === "duplicate") {
      f.gateway.runs.get(B)!.push({
        ...f.gateway.runs.get(B)![0],
        conclusion: "failure",
      });
    }
    await f.tick();
    assertEquals(f.gateway.starts, [], invalid);
  }
});
Deno.test("P42 lasting codes block immediately and manual reset uses a fresh request key", async () => {
  for (
    const code of [
      "deployment.cancelled",
      "deployment.commit_not_on_branch",
      "deployment.repository_mismatch",
      "deployment.repository_rebind_required",
      "deployment_config.unavailable",
    ]
  ) {
    const f = new PolicyFixture();
    f.gateway.failure(B, "operation", code);
    assertEquals(await f.tick(), "no_green_commit", code);
  }
  const f = new PolicyFixture();
  await f.tick();
  f.gateway.finish("failed", "before_activation");
  await f.tick();
  f.gateway.row({ status: "failed" });
  f.gateway.resume();
  await f.tick();
  assertEquals(f.gateway.starts.length, 2);
  assertEquals(f.gateway.starts.map((s) => s.key), [
    `auto.7.${B}.1`,
    `auto.7.${B}.2`,
  ]);
});
Deno.test("P43 own operation is deploying, another is busy, unchanged writes no deploy", async () => {
  const f = new PolicyFixture();
  f.gateway.hold("instance.deploy", true);
  assertEquals(await f.tick(), "deploying");
  assertEquals(f.state.operation_id, 900);
  f.gateway.hold();
  assertEquals(await f.tick(), "busy");
  f.gateway.chain([A]);
  assertEquals(await f.tick(), "up_to_date");
  assertEquals(f.gateway.starts, []);
});
