import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";

import { GatewayClient } from "./gateway.ts";
import {
  isAutomaticDeployment,
  PolicyError,
  StateSchema,
  trigger,
} from "./policy.ts";
import {
  A,
  B,
  PolicyFixture,
} from "../../../tests/_support/automatic_deploy.ts";

Deno.test("automatic origin accepts Gateway callers and both stored labels while excluding rollbacks", () => {
  for (const kind of ["deploy", "instance.deploy"]) {
    for (const triggered_by of ["orbit:auto-deploy", "swamp:auto-deploy"]) {
      assertEquals(isAutomaticDeployment({ kind, triggered_by }), true);
      assertEquals(
        isAutomaticDeployment({ kind, triggered_by, caller_node_id: null }),
        true,
      );
    }
    for (const triggered_by of ["services", null]) {
      assertEquals(isAutomaticDeployment({ kind, triggered_by }), false);
      assertEquals(
        isAutomaticDeployment({ kind, triggered_by, caller_node_id: null }),
        false,
      );
      assertEquals(
        isAutomaticDeployment({ kind, triggered_by, caller_node_id: 2 }),
        true,
      );
      assertEquals(
        isAutomaticDeployment({
          kind,
          triggered_by,
          input: { caller_node_id: 2 },
        }),
        true,
      );
      for (const caller_node_id of [0, -1, 1.5]) {
        assertEquals(
          isAutomaticDeployment({ kind, triggered_by, caller_node_id }),
          false,
        );
      }
    }
  }
  for (
    const kind of ["rollback", "instance.rollback", "instance.policy_event"]
  ) {
    for (const triggered_by of ["orbit:auto-deploy", "swamp:auto-deploy"]) {
      assertEquals(
        isAutomaticDeployment({ kind, triggered_by, caller_node_id: 2 }),
        false,
      );
    }
  }
});

Deno.test("E05 a restarted tick follows its recorded receipt without a new start", async () => {
  const f = new PolicyFixture();
  await f.tick();
  f.advance(1);
  assertEquals(await f.tick(), "deploying");
  assertEquals(f.gateway.starts.length, 1);
  assertEquals(
    f.gateway.requests.at(-1),
    "GET /api/v1/operations/100",
  );
});
Deno.test("E10 lost admission response persists intent and holds the target", async () => {
  const f = new PolicyFixture();
  f.gateway.admissionError = new Error("connection lost");
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.intent?.key, `auto.7.${B}.1`);
  assertEquals(f.state.error_code, "automatic.outcome_unknown");
  f.gateway.admissionError = null;
  f.gateway.row({ commit: A });
  f.gateway.resume();
  await f.tick();
  assertEquals(f.state.intent?.key, `auto.7.${B}.1`);
  assertEquals(f.state.effect_hold, null);
  assertEquals(f.gateway.starts, []);
  assertEquals(
    f.gateway.requests.filter((r) => r.startsWith("POST")).length,
    1,
  );
});
Deno.test("E10 terminal receipt without history cannot imply safe retry", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "failed";
  f.gateway.observation.holder = null;
  f.advance(60);
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "automatic.outcome_unknown");
  assertEquals(f.gateway.starts.length, 1);
  assertEquals(f.state.intent, null);
  assertEquals(f.state.effect_hold?.operation_id, op.id);
  f.gateway.row({ commit: A });
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
});
Deno.test("E10 worker-loss receipt clears admission and holds until a later manual deploy", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  f.gateway.finish("failed");
  op.error_code = "operation.worker_lost";
  f.advance(60);
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "automatic.outcome_unknown");
  assertEquals(f.gateway.starts.length, 1);
  assertEquals(f.state.intent, null);
  assertEquals(f.state.effect_hold?.operation_id, op.id);
  f.gateway.chain([A, B, "c".repeat(40)]);
  f.gateway.resume();
  assertEquals(await f.tick(), "failed");
  f.gateway.row({ commit: A, status: "failed" });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
  assertEquals(f.state.effect_hold, null);
});
Deno.test("E12 admission refusal codes and advice survive", async () => {
  for (
    const [status, code] of [[403, "access.denied"], [
      503,
      "operation.queue_unavailable",
    ], [422, "validation.failed"]] as const
  ) {
    const f = new PolicyFixture();
    f.gateway.refusal = { status, code };
    assertEquals(await f.tick(), "failed");
    assertEquals(f.state.error_code, code);
    assertEquals(f.state.message, "Refused");
    assertEquals(f.state.intent, null);
  }
  const f = new PolicyFixture();
  f.gateway.refusal = { status: 409, code: "operation.target_busy" };
  f.gateway.operations.set(900, {
    id: 900,
    kind: "instance.deploy",
    status: "queued",
    input: {},
    triggered_by: "laptop",
    result: null,
    error_code: null,
  });
  assertEquals(await f.tick(), "busy");
  assertEquals(f.state.operation_id, 900);
});
Deno.test("E14 automatic start sends only a pinned commit and required key", async () => {
  const requests: unknown[] = [];
  const client = new GatewayClient((method, path, body, key) => {
    requests.push({ method, path, body, key });
    return Promise.resolve({
      status: 202,
      body: {
        data: {
          id: 9,
          attempts: 0,
          target_type: "instance",
          target_id: 7,
          kind: "instance.deploy",
          status: "queued",
          input: { commit: B },
          triggered_by: trigger,
          result: null,
          error_code: null,
        },
      },
    });
  });
  await client.deploy(7, B, `auto.7.${B}.1`);
  assertEquals(requests, [{
    method: "POST",
    path: "/api/v1/instances/7/deploy/automatic",
    body: { commit: B },
    key: `auto.7.${B}.1`,
  }]);
});
Deno.test("E16 keyed conflict holds intent and cannot create a fresh attempt", async () => {
  const f = new PolicyFixture();
  f.gateway.refusal = { status: 409, code: "operation.idempotency_conflict" };
  await f.tick();
  assertEquals(f.state.error_code, "operation.idempotency_conflict");
  f.gateway.refusal = null;
  await f.tick();
  assertEquals(f.state.error_code, "automatic.outcome_unknown");
  assertEquals(f.gateway.starts, []);
});
Deno.test("E17 branch head movement cannot change the selected pinned commit", async () => {
  const f = new PolicyFixture();
  await f.tick();
  f.gateway.chain([A, B, "c".repeat(40)]);
  assertEquals(await f.tick(), "deploying");
  assertEquals(f.gateway.starts[0].commit, B);
});
Deno.test("intent is saved before POST and malformed success receipts stay unresolved", async () => {
  const f = new PolicyFixture();
  f.gateway.deploy = (_instance, commit, key) => {
    assertEquals(f.state.intent?.commit, commit);
    assertEquals(f.state.intent?.key, key);
    return Promise.reject(
      new PolicyError("automatic.outcome_unknown", "Unreadable receipt"),
    );
  };
  await f.tick();
  assertEquals(f.state.intent?.operation_id, null);
  assertStringIncludes(f.state.error_code!, "unknown");
  StateSchema.parse(f.state);
});

Deno.test("queued rollback winning admission is busy without a stall", async () => {
  const f = new PolicyFixture();
  f.gateway.operations.set(900, {
    id: 900,
    kind: "instance.rollback",
    status: "queued",
    input: {},
    triggered_by: "laptop",
    result: null,
    error_code: null,
  });
  f.gateway.refusal = { status: 409, code: "operation.target_busy" };
  assertEquals(await f.tick(), "busy");
  f.advance(31);
  assertEquals(await f.tick(), "busy");
  assertEquals(f.state.stall, null);
  assertEquals(f.alerts, []);
  assertEquals(f.gateway.starts, []);
});

Deno.test("terminal receipt is read before fresh live history", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const read = f.gateway.operation.bind(f.gateway);
  f.gateway.operation = (id) => {
    f.gateway.finish();
    return read(id);
  };
  assertEquals(await f.tick(), "up_to_date");
  assertEquals(f.gateway.starts.length, 1);
});
Deno.test("an unresolved historical worker loss holds newer commits too", async () => {
  const f = new PolicyFixture();
  f.gateway.failure(B, "operation", "operation.worker_lost");
  f.advance(60);
  f.gateway.chain([A, B, "c".repeat(40)]);
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "automatic.outcome_unknown");
  assertEquals(f.gateway.starts, []);
  f.gateway.row({ commit: A, status: "failed" });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
});
Deno.test("malformed or mismatched admission receipts cannot establish success", async () => {
  for (
    const data of [null, {}, {
      id: 9,
      attempts: 0,
      kind: "instance.deploy",
      target_type: "instance",
      target_id: 8,
      status: "queued",
      input: { commit: B },
      triggered_by: trigger,
      result: null,
      error_code: null,
    }]
  ) {
    const client = new GatewayClient(() =>
      Promise.resolve({ status: 202, body: { data } })
    );
    const error = await assertRejects(
      () => client.deploy(7, B, `auto.7.${B}.1`),
      PolicyError,
    );
    assertEquals(error.code, "automatic.outcome_unknown");
  }
});

Deno.test("queued cancellation clears its request but holds effects until manual deploy and resume", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "cancelled";
  op.error_code = null;
  op.result = null;
  op.attempts = 0;
  f.gateway.observation.holder = null;
  f.gateway.chain([A]);
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.intent, null);
  assertEquals(f.state.effect_hold?.operation_id, op.id);
  f.gateway.chain([A, B, "c".repeat(40)]);
  f.gateway.resume();
  assertEquals(await f.tick(), "failed");
  f.gateway.row({ commit: A });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
  assertEquals(f.state.effect_hold, null);
});

Deno.test("first-attempt commit_blocked without history holds effects and preserves the rolled-away block", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "failed";
  op.error_code = "deployment.commit_blocked";
  op.result = null;
  op.attempts = 1;
  f.gateway.observation.holder = null;
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.effect_hold?.operation_id, op.id);
  assertEquals(f.state.intent, null);
  assertEquals(f.state.blocked, [B]);
  f.gateway.row({ commit: A });
  f.gateway.resume();
  f.gateway.chain([A, B, "c".repeat(40)]);
  assertEquals(await f.tick(), "started");
  assertEquals(f.gateway.starts.length, 2);
});
Deno.test("worker loss without a row clears admission and holds only until manual deploy plus resume", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "failed";
  op.error_code = "operation.worker_lost";
  op.result = null;
  op.attempts = 1;
  f.gateway.observation.holder = null;
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.intent, null);
  f.gateway.chain([A, B, "c".repeat(40)]);
  f.gateway.resume();
  assertEquals(await f.tick(), "failed");
  assertEquals(f.gateway.starts.length, 1);
  f.gateway.row({ commit: A, status: "failed" });
  assertEquals(await f.tick(), "paused");
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
  assertEquals(f.state.effect_hold, null);
});

for (
  const code of [
    "deployment.paused",
    "instance.auto_deploy_role_inactive",
    "instance.smoke_held",
    "instance.auto_deploy_policy_owner",
  ]
) {
  Deno.test(`first-attempt ${code} before deployment clears intent without an effect hold`, async () => {
    const f = new PolicyFixture();
    await f.tick();
    const op = f.gateway.observation.holder!;
    Object.assign(op, { status: "failed", attempts: 1, error_code: code });
    f.gateway.observation.holder = null;
    assertEquals(await f.tick(), "started");
    assertEquals(f.state.effect_hold, null);
    assertEquals(f.state.intent?.key, `auto.7.${B}.2`);
  });
  Deno.test(`resumed ${code} without effect evidence still holds`, async () => {
    const f = new PolicyFixture();
    await f.tick();
    const op = f.gateway.observation.holder!;
    Object.assign(op, { status: "failed", attempts: 2, error_code: code });
    f.gateway.observation.holder = null;
    assertEquals(await f.tick(), "failed");
    assertEquals(f.state.intent, null);
    assertEquals(f.state.effect_hold?.operation_id, op.id);
    assertEquals(f.gateway.starts.length, 1);
  });
}
Deno.test("manual deploy and resume before observing worker loss still resolve that receipt", async () => {
  const f = new PolicyFixture();
  await f.tick();
  const op = f.gateway.observation.holder!;
  op.status = "failed";
  op.error_code = "operation.worker_lost";
  op.result = null;
  op.attempts = 1;
  f.gateway.observation.holder = null;
  f.gateway.row({ commit: B });
  f.gateway.resume();
  f.gateway.chain([A, B, "c".repeat(40)]);
  assertEquals(await f.tick(), "started");
  assertEquals(f.state.effect_hold, null);
});
for (
  const [label, status, code] of [
    ["env.operation_busy", "failed", "env.operation_busy"],
    ["Gateway exception", "failed", "operation.failed"],
    ["unexpected error code", "failed", "example.unexpected_failure"],
    ["cancel after start", "cancelled", null],
  ] as const
) {
  Deno.test(`terminal no-row ${label} clears its request and uses the manual resolution boundary`, async () => {
    const f = new PolicyFixture();
    await f.tick();
    const op = f.gateway.observation.holder!;
    op.status = status;
    op.error_code = code;
    op.result = null;
    op.attempts = 1;
    f.gateway.observation.holder = null;
    assertEquals(await f.tick(), "failed");
    assertEquals(f.state.intent, null);
    assertEquals(f.state.effect_hold?.operation_id, op.id);
    f.gateway.chain([A, B, "c".repeat(40)]);
    f.gateway.resume();
    f.advance(24 * 60);
    assertEquals(await f.tick(), "failed");
    assertEquals(f.gateway.starts.length, 1);
    f.gateway.row({ commit: A, status: "failed" });
    assertEquals(await f.tick(), "paused");
    f.gateway.resume();
    assertEquals(await f.tick(), "started");
    assertEquals(f.state.effect_hold, null);
    assertEquals(f.gateway.starts.length, 2);
  });
  Deno.test(`manual deploy and resume before observing terminal no-row ${label} resolve it`, async () => {
    const f = new PolicyFixture();
    await f.tick();
    const op = f.gateway.observation.holder!;
    op.status = status;
    op.error_code = code;
    op.result = null;
    op.attempts = 1;
    f.gateway.observation.holder = null;
    f.gateway.row({ commit: A });
    f.gateway.resume();
    f.gateway.chain([A, B, "c".repeat(40)]);
    assertEquals(await f.tick(), "started");
    assertEquals(f.state.effect_hold, null);
    assertEquals(f.gateway.starts.length, 2);
  });
}

Deno.test("503 queue_unavailable is a refusal and the next tick can admit", async () => {
  const f = new PolicyFixture();
  f.gateway.refusal = {
    status: 503,
    code: "operation.queue_unavailable",
    message:
      "Operations run on the Gateway queue, but ORBIT_GATEWAY_QUEUE_CONNECTION is empty. See /reference/gateway-queue.",
  };
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "operation.queue_unavailable");
  assertEquals(f.state.intent, null);
  assertEquals(f.state.operation_id, null);
  assertStringIncludes(f.state.message!, "/reference/gateway-queue");
  assertEquals(f.gateway.starts, []);
  f.gateway.refusal = null;
  assertEquals(await f.tick(), "started");
});
Deno.test("503 dispatch_failed follows its operation then applies the terminal no-history rule", async () => {
  const f = new PolicyFixture();
  f.gateway.refusal = { status: 503, code: "operation.dispatch_failed" };
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.error_code, "operation.dispatch_failed");
  assertEquals(f.state.intent?.operation_id, 900);
  f.gateway.operations.set(900, {
    id: 900,
    kind: "instance.deploy",
    status: "failed",
    input: { commit: B },
    triggered_by: trigger,
    result: null,
    error_code: "operation.dispatch_failed",
    attempts: 0,
  });
  f.gateway.refusal = null;
  assertEquals(await f.tick(), "failed");
  assertEquals(f.state.intent, null);
  assertEquals(f.state.effect_hold?.operation_id, 900);
  f.gateway.row({ commit: A });
  f.gateway.resume();
  assertEquals(await f.tick(), "started");
  assertEquals(f.state.effect_hold, null);
});

Deno.test("uncontracted server failures cannot be classified as a safe refusal", async () => {
  for (
    const [status, code] of [[500, "operation.queue_unavailable"], [
      503,
      "server.error",
    ]] as const
  ) {
    const f = new PolicyFixture();
    f.gateway.refusal = { status, code };
    assertEquals(await f.tick(), "failed");
    assertEquals(f.state.error_code, "automatic.outcome_unknown");
    f.gateway.refusal = null;
    assertEquals(await f.tick(), "failed");
    assertEquals(f.gateway.starts, []);
  }
});
