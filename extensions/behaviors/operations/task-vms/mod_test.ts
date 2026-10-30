import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { taskVms } from "./mod.ts";
import { incusInvocation } from "./contract.ts";
import {
  type Context,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = Operation.parse({
    id: 19,
    kind: "task_vm.incus_start",
    node_id: 3,
    status: "running",
    cancel_requested: false,
    input: {
      task_vm_id: 5,
      vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
      operation: { kind: "start" },
    },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  receipt: Record<string, unknown> | null = null;
  result: Record<string, unknown> = { ready: true };
  mutations = 0;
  lose: "start" | "run" | "finish" | "complete" | null = null;
  leaseLost = false;
  receiptPendingOnce = false;
  http: Http = async (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body: structuredClone(body) });
    await Promise.resolve();
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = path === `/api/v1/operations/${this.operation.id}`
      ? { ...this.operation, result: null, error_code: null }
      : this.operation;
    if (path.includes("/receipts/")) {
      const pending = this.receiptPendingOnce;
      this.receiptPendingOnce = false;
      data = {
        state: pending
          ? "running"
          : this.receipt
          ? this.receipt.error_code ? "failed" : "succeeded"
          : "unknown",
        result: pending ? null : this.receipt,
      };
    }
    if (path.endsWith("/step")) {
      const name = String(body.name);
      const entry = this.operation.steps.find((entry) => entry.name === name);
      if (!entry) {
        this.operation.steps.push({ name, status: String(body.status) });
      } else entry.status = String(body.status);
      if (body.status === "succeeded") {
        this.operation.step_results[name] = body.result;
      }
    }
    if (path.endsWith("/run")) {
      if (!this.receipt) {
        this.receipt = structuredClone(this.result);
        this.mutations++;
      }
      data = this.receipt;
    }
    const boundary = path.endsWith("/run")
      ? "run"
      : path.endsWith("/complete")
      ? "complete"
      : path.endsWith("/step")
      ? (body.status === "running" ? "start" : "finish")
      : null;
    if (boundary && this.lose === boundary) {
      this.lose = null;
      if (boundary === "run") this.receiptPendingOnce = true;
      throw new Error(`Lost ${boundary} reply`);
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute(worker = "workflow-19") {
    return taskVms(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}

Deno.test("fixed Incus effects keep admitted host and VM identity", async () => {
  for (const verb of ["create", "start", "stop", "delete"]) {
    const f = new Fixture();
    f.operation.kind = `task_vm.incus_${verb}`;
    f.operation.input.operation = verb === "create"
      ? {
        kind: verb,
        image: "a".repeat(64),
        expected_fingerprint: "a".repeat(64),
        base_alias: "orbit-task-base",
        gateway_public_key: "ssh-ed25519 public",
        cpus: 2,
        memory: "4GiB",
        disk: "20GiB",
        network: "orbittask0",
        pool: "default",
        topology: true,
      }
      : verb === "delete"
      ? { kind: verb, pool: "default" }
      : { kind: verb };
    assertEquals((await f.execute()).status, "succeeded");
    const run = f.calls.find((call) => call.path.endsWith("/run"))!.body;
    assertEquals(run.name, "incus.vm");
    assertEquals(run.key, `incus-${verb}`);
    assertEquals("node_id" in run, false);
    assertEquals(run.input, {
      vm: f.operation.input.vm,
      operation: f.operation.input.operation,
    });
    assertEquals(f.mutations, 1);
  }
});

for (const boundary of ["start", "run", "finish", "complete"] as const) {
  Deno.test(`process restart after a lost ${boundary} reply reuses the effect and completion identity`, async () => {
    const f = new Fixture();
    f.lose = boundary;
    await assertRejects(() => f.execute(), Error, `Lost ${boundary} reply`);
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.mutations, 1);
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/claim")).length,
      1,
    );
    const runs = f.calls.filter((call) => call.path.endsWith("/run"));
    assertEquals(new Set(runs.map((call) => call.body.key)).size, 1);
    if (boundary === "complete") assertEquals(runs.length, 1);
  });
}

Deno.test("recorded refusals survive a restart after the failed step", async () => {
  const f = new Fixture();
  f.result = { error_code: "task_vm.image_mismatch" };
  f.lose = "finish";
  await assertRejects(() => f.execute());
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "task_vm.image_mismatch");
  assertEquals(f.mutations, 1);
});

Deno.test("an expired lease stops before new effects and a new owner resumes", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), ExecutorError, "operation.lease_lost");
  assertEquals(f.mutations, 0);
  f.leaseLost = false;
  assertEquals((await f.execute("workflow-recovered")).status, "succeeded");
  assertEquals(f.calls.at(-1)!.body.worker, "workflow-recovered");
});

Deno.test("cancellation before a host effect performs no mutation", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.mutations, 0);
});

Deno.test("cancellation during an uncertain effect reconciles its keyed receipt", async () => {
  const f = new Fixture();
  f.lose = "run";
  await assertRejects(() => f.execute());
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
});

Deno.test("a Gateway cancellation refusal finishes the step and cancels the operation", async () => {
  const f = new Fixture();
  f.result = { error_code: "operation.cancelled" };
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.operation.steps[0].status, "failed");
});

Deno.test("a nonconfirming command cannot create successful completion intent", async () => {
  const f = new Fixture();
  f.result = { ready: false };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "task_vm.effect_unproven");
  assertEquals(f.operation.steps[0].status, "failed");
});

Deno.test("an effect cannot change kind, VM identity or command fields", () => {
  const f = new Fixture();
  assertThrows(() =>
    incusInvocation("task_vm.incus_delete", f.operation.input)
  );
  assertThrows(() => incusInvocation("node.remove", f.operation.input));
  assertThrows(() =>
    incusInvocation(f.operation.kind, { ...f.operation.input, task_vm_id: 99 })
  );
  assertThrows(() =>
    incusInvocation(f.operation.kind, {
      ...f.operation.input,
      operation: { kind: "start", script: "unrelated" },
    })
  );
});

for (
  const [reason, lostReply] of [
    ["Archive checksum mismatch", false],
    ["HTTP 429", false],
    ["x".repeat(160), false],
    ["Archive checksum mismatch", true],
  ] as const
) {
  Deno.test(`cache fill keeps verified archives with reason ${reason.slice(0, 24)}${lostReply ? " after a lost reply" : ""}`, async () => {
    const f = new Fixture();
    f.operation.kind = "task_vm.fill_package_cache_batch";
    f.operation.input = {
      host_node_id: 3,
      artifacts: [],
      conflicts: {},
      max_bytes: 1024,
      github_token: { $secret: "task_vm.package_cache_github", id: 3 },
    };
    f.result = {
      ready: true,
      archive_ids: ["verified"],
      archive_digests: [`sha256-${"a".repeat(64)}`],
      failed_ids: ["failed-archive"],
      failure_reasons: [reason],
    };
    if (lostReply) {
      f.lose = "run";
      await assertRejects(() => f.execute());
    }
    assertEquals((await f.execute()).status, "failed");
    const completion = f.calls.at(-1)!.body;
    assertEquals(completion.error_code, "task_vm.package_cache_failed");
    assertEquals(completion.result, f.result);
    assertEquals(f.operation.steps[0].status, "succeeded");
    assertEquals(f.mutations, 1);
    const run = f.calls.find((call) => call.path.endsWith("/run"))!.body;
    assertEquals(run.transport, "task_vm_package-cache");
    assertEquals("node_id" in run, false);
    assertEquals((run.input as Record<string, unknown>).github_token, {
      $secret: "task_vm.package_cache_github",
      id: 3,
    });
  });
}

Deno.test("a typed cache fill refusal fails its step and completion with the same code", async () => {
  const f = new Fixture();
  f.operation.kind = "task_vm.fill_package_cache_batch";
  f.operation.input = {
    host_node_id: 3,
    artifacts: [],
    conflicts: {},
    max_bytes: 1024,
    github_token: { $secret: "task_vm.package_cache_github", id: 3 },
  };
  f.result = { error_code: "task_vm.package_cache_failed" };

  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "task_vm.package_cache_failed");
  assertEquals(f.operation.steps[0].status, "failed");
  assertEquals(f.mutations, 1);
  assertEquals(
    f.calls.find((call) => call.path.endsWith("/run"))!.body.transport,
    "task_vm_package-cache",
  );
});

Deno.test("a preservation refusal stops deletion with the Gateway error", async () => {
  const f = new Fixture();
  f.operation.kind = "task_vm.mark_destroying";
  f.operation.input = { task_vm_id: 5 };
  f.result = { error_code: "task_vm.workspace_not_preserved" };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(
    f.calls.at(-1)!.body.error_code,
    "task_vm.workspace_not_preserved",
  );
  assertEquals(f.mutations, 1);
});

Deno.test("an empty placed claim makes no assignment or effect", async () => {
  const f = new Fixture();
  const http: Http = (_method, path) => {
    assertEquals(path, "/api/v1/operation-executor/claim");
    return Promise.resolve({ status: 200, body: { data: null } });
  };
  assertEquals(
    await taskVms(() => http).methods.execute.execute({
      kind: f.operation.kind,
      worker: "empty",
    }, f.context()),
    { status: "idle" },
  );
  assertEquals(f.resources.size, 0);
  assertEquals(f.mutations, 0);
});

Deno.test("a dispatch retry cannot adopt another Operation after losing an effect reply", async () => {
  const f = new Fixture();
  f.lose = "run";
  await assertRejects(() => f.execute());
  f.operation.id = 99;
  await assertRejects(() => f.execute(), Error, "Wrong task VM assignment");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
  assertEquals(f.mutations, 1);
});

Deno.test("enrollment preparation recovers its exact reserved Node and enrolled flag after a lost reply", async () => {
  for (const enrolled of [false, true]) {
    const f = new Fixture();
    f.operation.kind = "task_vm.prepare_enrollment";
    f.operation.input = { task_vm_id: 5 };
    f.result = { ready: true, node_id: 12, enrolled };
    f.lose = "run";
    await assertRejects(() => f.execute(), Error, "Lost run reply");
    assertEquals(f.receipt, { ready: true, node_id: 12, enrolled });
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.operation.step_results["prepare-enrollment"], f.result);
    assertEquals(f.calls.at(-1)!.body.result, f.result);
    assertEquals(f.mutations, 1);
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/run")).length,
      1,
    );
  }
});

for (
  const [status, attempts, expected] of [
    ["queued", 0, "cancel_requested"],
    ["queued", 1, "preserved"],
    ["running", 1, "preserved"],
    ["waiting", 1, "preserved"],
    ["succeeded", 1, "preserved"],
    ["failed", 1, "preserved"],
    ["cancelled", 0, "preserved"],
  ] as const
) {
  Deno.test(`native queue expiry ${expected}: child ${status}, attempts ${attempts}`, async () => {
    const f = new Fixture();
    f.resources.set("queue-wait-native", { unclaimed_child_id: 56 });
    const calls: string[] = [];
    const http: Http = (_method, path) => {
      calls.push(path);
      return Promise.resolve({
        status: 200,
        body: { data: { id: 56, status, attempts } },
      });
    };
    const result = await taskVms(() => http).methods.cancelUnclaimedChild
      .execute({ worker: "native" }, f.context());
    assertEquals(result.status, expected);
    assertEquals(
      calls.includes("/api/v1/operations/56/cancel"),
      expected === "cancel_requested",
    );
  });
}
