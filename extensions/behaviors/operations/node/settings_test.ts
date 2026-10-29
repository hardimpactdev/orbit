import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { node } from "./mod.ts";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "node.settings",
    status: "running",
    cancel_requested: false,
    input: {
      node_id: 2,
      root: "/srv/apps",
      user: "orbit",
      group: "orbit",
      create_missing: true,
    },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  runs = 0;
  lostRun = false;
  lostCompletion = false;
  cancelAtRun = false;
  leaseLost = false;
  refusal: string | null = null;
  receipt: Record<string, unknown> | null = null;
  terminal: Record<string, unknown> | null = null;
  resources = new Map<string, Record<string, unknown>>();
  http: Http = async (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = path.startsWith("/api/v1/operations/")
      ? this.terminal ?? { ...this.operation, result: null, error_code: null }
      : this.operation;
    if (path.includes("/receipts/")) {
      data = {
        effect_key: `op-7.${decodeURIComponent(path.split("/").at(-1)!)}`,
        exit_code: null,
        finished_at: null,
        state: this.receipt ? "succeeded" : "unknown",
        result: this.receipt,
      };
    }
    if (path.endsWith("/run")) {
      if (this.receipt === null) {
        if (this.cancelAtRun) {
          this.receipt = { error_code: "operation.cancelled" };
        } else {
          this.runs++;
          this.receipt = this.refusal ? { error_code: this.refusal } : {};
        }
      }
      if (this.lostRun) {
        this.lostRun = false;
        throw new Error("Lost run reply");
      }
      data = this.receipt;
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete") && this.lostCompletion) {
      this.lostCompletion = false;
      throw new Error("Lost completion reply");
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const globals = {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/unused",
    };
    const { context } = createModelTestContext({ globalArgs: globals });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: globals,
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
  execute(worker = "run-1") {
    return node(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}

Deno.test("settings prepares only the admitted root on the Operation Node", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "succeeded");
  const run = f.calls.find((c) => c.path.endsWith("/run"))!.body;
  assertEquals(run, {
    worker: "run-1",
    key: "node-storage-root",
    transport: "agent",
    name: "node.prepare-storage-root",
    input: {
      root: "/srv/apps",
      user: "orbit",
      group: "orbit",
      create_missing: true,
    },
  });
  assertEquals(f.calls.at(-1)!.body.result, {});
});
Deno.test("settings without app-dev inspects without creating directories", async () => {
  const f = new Fixture();
  f.operation.input.create_missing = false;
  await f.execute();
  assertEquals(
    (f.calls.find((c) => c.path.endsWith("/run"))!.body.input as Record<
      string,
      unknown
    >).create_missing,
    false,
  );
});
Deno.test("settings refusal preserves its public failure code", async () => {
  const f = new Fixture();
  f.refusal = "node.storage_root_unsafe";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "node.settings_root_failed");
  assertEquals(f.calls.at(-2)!.body.status, "failed");
});
Deno.test("lost Node reply is reconciled from the target receipt after restart", async () => {
  const f = new Fixture();
  f.lostRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 1);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/run")).map((c) => c.body.key),
    ["node-storage-root"],
  );
});
Deno.test("lost completion reply replays saved intent without another Node call", async () => {
  const f = new Fixture();
  f.lostCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(count).map((call) => call.path), [
    "/api/v1/operations/7",
    "/api/v1/operation-executor/7/heartbeat",
    "/api/v1/operation-executor/7/complete",
  ]);
  assertEquals(f.runs, 1);
});
Deno.test("a reclaimed Operation resumes a proven empty result without repeating it", async () => {
  const f = new Fixture();
  f.operation.step_results["node-storage-root"] = {};
  assertEquals((await f.execute("run-2")).status, "succeeded");
  assertEquals(f.runs, 0);
});
Deno.test("expired lease stops before any Node work or completion", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.runs, 0);
  assertEquals(f.calls.length, 1);
});
Deno.test("cancel before the effect avoids the Node", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
});
Deno.test("cancel after a proven effect keeps the receipt", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  f.operation.step_results["node-storage-root"] = {};
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 0);
});
Deno.test("Gateway cancellation before dispatch closes the failed step", async () => {
  const f = new Fixture();
  f.cancelAtRun = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
  assertEquals(f.calls.at(-1)!.body.error_code, "operation.cancelled");
});
Deno.test("another kind or Node cannot use the settings workflow", async () => {
  for (const wrong of ["kind", "node"]) {
    const f = new Fixture();
    if (wrong === "kind") f.operation.kind = "firewall.remove";
    else f.operation.input.node_id = 3;
    await assertRejects(() => f.execute());
    assertEquals(f.runs, 0);
  }
});

Deno.test("an accepted completion is verified without another claim or write", async () => {
  const f = new Fixture();
  await f.execute();
  const completion = f.calls.at(-1)!.body;
  f.terminal = { id: 7, kind: "node.settings", ...completion };
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(count).map((call) => call.path), [
    "/api/v1/operations/7",
  ]);
  assertEquals(f.runs, 1);
});
Deno.test("terminal proof refuses a changed status, kind, error or result", async () => {
  for (
    const change of [
      { id: 8 },
      { kind: "node.access_remove" },
      { status: "failed" },
      { error_code: "operation.failed" },
      { result: { forged: true } },
    ]
  ) {
    const f = new Fixture();
    await f.execute();
    f.terminal = {
      id: 7,
      kind: "node.settings",
      ...f.calls.at(-1)!.body,
      ...change,
    };
    const count = f.calls.length;
    await assertRejects(
      () => f.execute(),
      Error,
      "operation.completion_unproven",
    );
    assertEquals(f.calls.slice(count).map((call) => call.path), [
      "/api/v1/operations/7",
    ]);
    assertEquals(f.runs, 1);
  }
});
