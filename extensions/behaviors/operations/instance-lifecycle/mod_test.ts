import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { Context } from "../../operation-runner/mod.ts";
import { instanceLifecycle } from "./mod.ts";
import type { Receipt } from "./runtime.ts";

class Fixture {
  calls: { path: string; body: Receipt }[] = [];
  effects = new Map<string, Receipt>();
  steps: Receipt = {};
  resources = new Map<string, Receipt>();
  loseEffect: string | null = null;
  loseCompletion = false;
  uncertainReply = false;
  domain: string | null = null;
  childStatus = "queued";
  status = "running";
  result: Receipt | null = null;
  errorCode: string | null = null;
  noWork = false;
  worker = "workflow-100";
  http: Http = (_method, path, raw) =>
    Promise.resolve().then(() => {
      const body = raw as Receipt;
      this.calls.push({ path, body });
      let data: unknown = {
        id: 100,
        kind: "instance.rename",
        node_id: 9,
        status: this.status,
        result: this.result,
        error_code: this.errorCode,
        cancel_requested: false,
        step_results: this.steps,
        input: { instance_id: 501, domain: this.domain, route_id: 30 },
        child_context: {
          children: this.domain === null ? [] : [{
            key: "route-update",
            kind: "route.update",
            node_id: 9,
            input: { route_id: 30, domain: this.domain },
          }],
        },
        execution_context: {
          descriptors: [
            {
              key: "rename-inspect",
              step: "rename-inspect",
              transport: "instance_lifecycle",
              name: "instance.inspect-development-source",
              input: { checkout: "/apps/demo/proof" },
              required: true,
              resumable: true,
            },
            {
              key: "lifecycle-finish",
              step: "lifecycle-finish",
              transport: "gateway",
              name: "instance.lifecycle-state",
              input: { action: "finish", operation_id: 100 },
              required: true,
              resumable: true,
            },
          ],
        },
      };
      if (path.endsWith("/claim") && this.noWork) data = null;
      if (path.endsWith("/complete")) {
        this.status = String(body.status);
        this.result = body.result as Receipt;
        this.errorCode = body.error_code as string | null;
      }
      if (path.endsWith("/children")) {
        data = _method === "GET"
          ? [{
            id: 200,
            key: "route-update",
            kind: "route.update",
            node_id: 9,
            status: this.childStatus,
            error_code: null,
            result: this.childStatus === "succeeded" ? { route_id: 30 } : null,
          }]
          : { id: 200 };
      }
      if (path.endsWith("/wait")) data = { status: "waiting" };
      if (path.includes("/receipts/")) {
        const key = decodeURIComponent(path.split("/").at(-1)!);
        const result = this.effects.get(key) ?? null;
        data = {
          effect_key: `op-100.${key}`,
          state: result ? "succeeded" : "unknown",
          exit_code: result ? 0 : null,
          result,
          finished_at: result ? "2026-10-28T10:00:00Z" : null,
        };
      }
      if (path.endsWith("/run")) {
        const key = String(body.key);
        if (!this.effects.has(key)) {
          this.effects.set(
            key,
            key === "rename-inspect"
              ? { source_verified: true, branch: "feature" }
              : { lifecycle_complete: true, instance_id: 501 },
          );
        }
        if (this.loseEffect === key) {
          this.loseEffect = null;
          throw new Error("Lost effect reply");
        }
        data = this.uncertainReply
          ? { error_code: "operation.execution_uncertain" }
          : this.effects.get(key);
        this.uncertainReply = false;
      }
      if (path.endsWith("/step") && body.status === "succeeded") {
        this.steps[String(body.name)] = body.result;
      }
      if (path.endsWith("/complete") && this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion reply");
      }
      return { status: 200, body: { data: structuredClone(data) } };
    });
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
      writeResource: async (spec: string, name: string, value: Receipt) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute() {
    return instanceLifecycle(() => this.http).methods.execute.execute({
      kind: "instance.rename",
      worker: this.worker,
    }, this.context());
  }
}

Deno.test("the lifecycle behavior reports its proven final state receipt", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals([...f.effects.keys()], ["rename-inspect", "lifecycle-finish"]);
  assertEquals(f.calls.at(-1)!.body.result, {
    lifecycle_complete: true,
    instance_id: 501,
  });
});

Deno.test("a behavior restart after an uncertain effect uses the original run receipt", async () => {
  const f = new Fixture();
  f.loseEffect = "rename-inspect";
  await assertRejects(() => f.execute(), Error, "Lost effect reply");
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects.size, 2);
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.key === "rename-inspect"
    ).length,
    1,
  );
});

Deno.test("a lost completion response confirms terminal state without lease writes or new effects", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.calls.at(-1)!.path, "/api/v1/operations/100");
});

Deno.test("a parent awaiting a child returns its worker without saving terminal completion", async () => {
  const f = new Fixture();
  f.domain = "preview.test";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.resources.has("operation-100"), false);
  assertEquals(f.resources.get("operation-100-waiting"), {
    keys: ["route-update"],
  });
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals(f.effects.has("lifecycle-finish"), false);
  assertEquals(f.calls.at(-1)!.body, {
    worker: "workflow-100",
    keys: ["route-update"],
  });
  f.childStatus = "succeeded";
  f.worker = "workflow-101";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.key === "rename-inspect"
    ).length,
    1,
  );
});

Deno.test("uncertain effects do not persist failed completion intent that would prevent recovery", async () => {
  const f = new Fixture();
  f.uncertainReply = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.execution_uncertain",
  );
  assertEquals(f.resources.has("operation-100"), false);
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((call) =>
      call.path.endsWith("/run") && call.body.key === "rename-inspect"
    ).length,
    1,
  );
});

Deno.test("no claimable lifecycle work saves no assignment or completion", async () => {
  const f = new Fixture();
  f.noWork = true;
  assertEquals(await f.execute(), { claimed: false });
  assertEquals(f.resources.size, 0);
  assertEquals(f.effects.size, 0);
});
Deno.test("a retry never claims a second operation after effects started", async () => {
  const f = new Fixture();
  f.loseEffect = "rename-inspect";
  await assertRejects(() => f.execute());
  await f.execute();
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
});
Deno.test("a saved completion refuses a mismatched terminal result", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute());
  f.result = { instance_id: 999 };
  await assertRejects(
    () => f.execute(),
    Error,
    "Unproven saved lifecycle completion",
  );
  assertEquals(f.calls.filter((c) => c.path.endsWith("/complete")).length, 1);
});
