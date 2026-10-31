import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { type ChildExecutor, LifecycleSession } from "./session.ts";
import type { Child, Receipt } from "./runtime.ts";

class Fixture {
  kind = "instance.create";
  cancelled = false;
  input = { instance_id: 501 };
  descriptor = {
    key: "prepare",
    step: "prepare-source",
    transport: "agent",
    name: "instance.prepare-development-source",
    input: {
      checkout: "/apps/demo/proof",
      git_read: { $secret: "instance.lifecycle_git", id: 50 },
    } as Receipt,
    required: true,
    resumable: true,
  };
  calls: { path: string; body: Receipt }[] = [];
  steps: Receipt = {};
  result: Receipt = { created: true };
  effects = 0;
  receipt: Receipt | null = null;
  loseReply = false;
  uncertainReply = false;
  leaseLost = false;
  contextNode = 9;
  children: Child[] = [];
  receiptStatus = 200;
  receiptState: "running" | "unknown" | null = null;
  contextResult: Receipt = {};
  receiptKey = "op-100.prepare";
  pauseCount = 0;
  onPause = () => {};
  loseBeforeEffect = false;
  http: Http = (_method, path, raw) =>
    Promise.resolve().then(() => {
      const body = raw as Receipt;
      this.calls.push({ path, body });
      if (this.leaseLost && path.endsWith("/heartbeat")) {
        return {
          status: 409,
          body: { error: { code: "operation.lease_lost" } },
        };
      }
      let data: unknown = {
        id: 100,
        kind: this.kind,
        node_id: path.endsWith("instance-lifecycle") ? this.contextNode : 9,
        input: this.input,
        status: "running",
        cancel_requested: this.cancelled,
        step_results: this.steps,
        execution_context: {
          descriptors: [this.descriptor],
          result: this.contextResult,
        },
        child_context: { children: this.children },
      };
      if (path.includes("/receipts/")) {
        if (this.receiptStatus !== 200) {
          return {
            status: this.receiptStatus,
            body: { error: { code: "operation.receipt_unavailable" } },
          };
        }
        data = {
          effect_key: this.receiptKey,
          state: this.receiptState ??
            (this.receipt
              ? (this.receipt.error_code ? "failed" : "succeeded")
              : "unknown"),
          exit_code: this.receiptState || !this.receipt
            ? null
            : this.receipt.error_code
            ? 1
            : 0,
          result: this.receiptState ? null : this.receipt,
          finished_at: this.receiptState || !this.receipt
            ? null
            : "2026-10-28T10:00:00Z",
        };
      }
      if (path.endsWith("/run")) {
        if (this.loseBeforeEffect) {
          this.loseBeforeEffect = false;
          throw new Error("Lost run before effect");
        }
        if (!this.receipt) {
          this.effects++;
          this.receipt = structuredClone(this.result);
        }
        if (this.loseReply) {
          this.loseReply = false;
          throw new Error("Lost run reply");
        }
        data = this.uncertainReply
          ? { error_code: "operation.execution_uncertain" }
          : this.receipt;
        this.uncertainReply = false;
      }
      if (path.endsWith("/step") && body.status === "succeeded") {
        this.steps[String(body.name)] = body.result;
      }
      return { status: 200, body: { data: structuredClone(data) } };
    });
  session(executeChild: ChildExecutor = () => {
    throw new Error("Unexpected child");
  }) {
    return new LifecycleSession(
      this.http,
      100,
      "workflow:instance.create",
      executeChild,
      () => {
        this.pauseCount++;
        this.onPause();
        return Promise.resolve();
      },
    );
  }
}

Deno.test("the adapter sends the Gateway descriptor and no Node override", async () => {
  const f = new Fixture();
  assertEquals(await f.session().run("prepare"), { created: true });
  const invocation = f.calls.find((call) => call.path.endsWith("/run"))!.body;
  assertEquals(invocation, {
    worker: "workflow:instance.create",
    key: "prepare",
    transport: f.descriptor.transport,
    name: f.descriptor.name,
    input: f.descriptor.input,
  });
  assertEquals(
    f.calls.find((call) => call.body?.status === "succeeded")!.body.name,
    "prepare-source",
  );
});

Deno.test("a new adapter recovers a lost reply without another invocation", async () => {
  const f = new Fixture();
  f.loseReply = true;
  await assertRejects(
    () => f.session().run("prepare"),
    Error,
    "Lost run reply",
  );
  assertEquals(
    f.calls.some((call) => call.body?.status === "succeeded"),
    false,
  );
  assertEquals(await f.session().run("prepare"), { created: true });
  const invocations = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(invocations.length, 1);
  assertEquals(
    f.calls.filter((call) => call.path.includes("/receipts/")).length,
    2,
  );
  assertEquals(f.effects, 1);
});

Deno.test("the adapter reuses a saved successful step after process restart", async () => {
  const f = new Fixture();
  f.steps["prepare-source"] = { created: true };
  assertEquals(await f.session().run("prepare"), { created: true });
  assertEquals(f.effects, 0);
});

Deno.test("lease loss stops before a new effect or successful step", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(
    () => f.session().run("prepare"),
    ExecutorError,
    "operation.lease_lost",
  );
  assertEquals(f.calls.length, 1);
  assertEquals(f.effects, 0);
});

Deno.test("context cannot replace the claimed Node", async () => {
  const f = new Fixture();
  f.contextNode = 10;
  await assertRejects(
    () => f.session().run("prepare"),
    Error,
    "does not match",
  );
  assertEquals(f.effects, 0);
});

Deno.test("a cancelled run closes its step as failed with the fixed code", async () => {
  const f = new Fixture();
  f.result = { error_code: "operation.cancelled" };
  assertEquals(await f.session().run("prepare"), f.result);
  const finished = f.calls.find((call) => call.body?.status === "failed")!;
  assertEquals(finished.body.error_code, "operation.cancelled");
  assertEquals(finished.body.key, "prepare:finish");
});

Deno.test("an unknown receipt permits the same key after a lost request", async () => {
  const f = new Fixture();
  f.loseBeforeEffect = true;
  await assertRejects(
    () => f.session().run("prepare"),
    Error,
    "Lost run before effect",
  );
  assertEquals(await f.session().run("prepare"), { created: true });
  const invocations = f.calls.filter((call) => call.path.endsWith("/run"));
  assertEquals(invocations.length, 2);
  assertEquals(invocations[0].body, invocations[1].body);
  assertEquals(f.effects, 1);
});

Deno.test("a running receipt waits and renews the lease before reading again", async () => {
  const f = new Fixture();
  f.receiptState = "running";
  f.onPause = () => {
    f.receiptState = null;
    f.receipt = { created: true };
  };
  assertEquals(await f.session().run("prepare"), { created: true });
  assertEquals(f.pauseCount, 1);
  assertEquals(f.effects, 0);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/heartbeat")).length,
    3,
  );
  assertEquals(f.calls.some((call) => call.path.endsWith("/run")), false);
});

Deno.test("a recovered failed receipt closes the step without invoking again", async () => {
  const f = new Fixture();
  f.receipt = { error_code: "instance.source_busy" };
  assertEquals(await f.session().run("prepare"), f.receipt);
  assertEquals(
    f.calls.find((call) => call.body?.status === "failed")!.body.result,
    f.receipt,
  );
  assertEquals(f.effects, 0);
});

Deno.test("a failed receipt read does not authorize another effect", async () => {
  const f = new Fixture();
  f.receiptStatus = 502;
  await assertRejects(
    () => f.session().run("prepare"),
    ExecutorError,
    "operation.receipt_unavailable",
  );
  assertEquals(f.effects, 0);
  assertEquals(f.calls.some((call) => call.path.endsWith("/run")), false);
});

Deno.test("another effect's receipt cannot prove the step", async () => {
  const f = new Fixture();
  f.receiptKey = "op-101.prepare";
  f.receipt = { created: true };
  await assertRejects(
    () => f.session().run("prepare"),
    ExecutorError,
    "operation.receipt_invalid",
  );
  assertEquals(
    f.calls.some((call) => call.body?.status === "succeeded"),
    false,
  );
  assertEquals(f.effects, 0);
});

Deno.test("the child client uses the Gateway's eligible Node and input", async () => {
  const f = new Fixture();
  f.children = [{
    key: "database",
    kind: "database.create",
    node_id: 20,
    input: { instance_id: 501 },
  }];
  const received: Child[] = [];
  const session = f.session((child) => {
    received.push(child);
    return Promise.resolve({ prepared: true });
  });
  assertEquals(
    await session.child({
      key: "database",
      kind: "database.create",
      node_id: 9,
      input: { instance_id: 999 },
    }),
    { prepared: true },
  );
  assertEquals(received, f.children);
});

Deno.test("a child that is not yet eligible is never admitted", async () => {
  const f = new Fixture();
  await assertRejects(
    () =>
      f.session().child({
        key: "route-converge",
        kind: "route.converge",
        node_id: 9,
        input: {},
      }),
    ExecutorError,
    "operation.child_forbidden",
  );
});

Deno.test("an uncertain run reply leaves its step open for receipt recovery", async () => {
  const f = new Fixture();
  f.uncertainReply = true;
  await assertRejects(
    () => f.session().run("prepare"),
    ExecutorError,
    "operation.execution_uncertain",
  );
  assertEquals(
    f.calls.some((call) =>
      ["succeeded", "failed"].includes(String(call.body?.status))
    ),
    false,
  );
  assertEquals(await f.session().run("prepare"), { created: true });
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
  assertEquals(f.effects, 1);
});

Deno.test("a proven failed target receipt remains terminal even with a generic failure code", async () => {
  const f = new Fixture();
  f.receipt = { error_code: "operation.execution_failed" };
  assertEquals(await f.session().run("prepare"), f.receipt);
  assertEquals(
    f.calls.find((call) => call.body?.status === "failed")!.body.result,
    f.receipt,
  );
  assertEquals(f.effects, 0);
});

Deno.test("cancelled transfer recovers its nonresumable capture before compensation without a second capture", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  Object.assign(f.descriptor, {
    key: "transfer-capture",
    step: "transfer-capture",
    transport: "instance_lifecycle",
    name: "instance.transfer-source",
    input: {
      operation: "capture",
      source: "/apps/demo/proof",
      layout: "checkout",
      sqlite_source: null,
    },
    resumable: false,
  });
  f.receiptKey = "op-100.transfer-capture";
  f.result = { captured: true, commit: "a".repeat(40), branch: "proof" };
  f.uncertainReply = true;
  await assertRejects(
    () => f.session().run("transfer-capture"),
    ExecutorError,
    "operation.execution_uncertain",
  );
  f.cancelled = true;
  f.receiptState = "running";
  f.onPause = () => {
    f.receiptState = null;
  };
  assertEquals(await f.session().run("transfer-capture"), f.result);
  assertEquals(f.steps["transfer-capture"], f.result);
  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")).length, 1);
  assertEquals(f.effects, 1);
  assertEquals(f.pauseCount, 1);
});

Deno.test("an unknown cancelled capture ends with Gateway pending recovery without another run", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.cancelled = true;
  f.contextResult = { rollback_pending: true };
  Object.assign(f.descriptor, {
    key: "transfer-capture",
    step: "transfer-capture",
    resumable: false,
  });
  f.receiptKey = "op-100.transfer-capture";

  assertEquals(await f.session().run("transfer-capture"), {
    error_code: "operation.cancelled",
    rollback_pending: true,
  });

  assertEquals(f.calls.filter((call) => call.path.endsWith("/run")), []);
  assertEquals(f.effects, 0);
  assertEquals(
    f.calls.find((call) => call.body?.status === "failed")!.body.result,
    { error_code: "operation.cancelled", rollback_pending: true },
  );
});

Deno.test("cancelled removal closes its unaccepted Gateway step without replaying the transaction", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.cancelled = true;
  f.descriptor = {
    ...f.descriptor,
    key: "removal-accept",
    step: "removal-accept",
    transport: "gateway",
    name: "instance.lifecycle-state",
    input: { action: "removal-accept", operation_id: 100 },
  };
  f.receiptKey = "op-100.removal-accept";
  assertEquals(await f.session().run("removal-accept"), {
    error_code: "operation.cancelled",
  });
  assertEquals(f.effects, 0);
  assertEquals(f.calls.some((call) => call.path.endsWith("/run")), false);
  assertEquals(
    f.calls.find((call) => call.body?.status === "failed")!.body.error_code,
    "operation.cancelled",
  );
});

Deno.test("cancelled removal still leaves a native effect open without its receipt", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.cancelled = true;
  f.descriptor.key = "removal-accept";
  f.descriptor.step = "removal-accept";
  f.receiptKey = "op-100.removal-accept";
  f.uncertainReply = true;
  await assertRejects(
    () => f.session().run("removal-accept"),
    ExecutorError,
    "operation.execution_uncertain",
  );
  assertEquals(f.calls.some((call) => call.body?.status === "failed"), false);
});
