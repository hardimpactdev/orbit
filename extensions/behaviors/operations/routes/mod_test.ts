import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { routes } from "./mod.ts";
import type { Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  kind = "route.caddy_converge";
  worker = "workflow-1";
  intent: Record<string, unknown> = { reason: "dev proof" };
  effects: {
    key: string;
    transport: string;
    name: string;
    input: Record<string, unknown>;
  }[] = [];
  get preparation() {
    return { key: "prepare", name: this.spec.prepare, input: this.intent };
  }
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  results: Record<string, unknown> = {};
  runs = new Map<string, Record<string, unknown>>();
  prepared = false;
  loseRun = false;
  loseComplete = false;
  receiptState: "succeeded" | "failed" | "running" | "unknown" | null = null;
  cancelled = false;
  refusal: string | null = null;
  refusalStage: string | null = null;
  final: Record<string, unknown> = { result: "published", ready: true };
  get spec() {
    return this.kind === "route.caddy_converge"
      ? {
        prepare: "route.caddy-prepare",
        key: "caddy-publish",
        transport: "route-caddy",
        name: "caddy.publish",
        input: {
          content: "private.example.test {}",
          version: "a".repeat(32),
          listen_addresses: ["10.8.0.2"],
          unserved: false,
        },
      }
      : {
        prepare: "route.private-dns-prepare",
        key: "private-dns-publish",
        transport: "route-dns",
        name: "route.publish-dns",
        input: {
          records: "host-record=private.example.test,10.8.0.2",
          catalog: "{}\n",
        },
      };
  }
  available = true;
  terminal: Record<string, unknown> | null = null;
  http: Http = (method, path, raw) =>
    Promise.resolve().then(() => {
      if (method === "GET") {
        this.calls.push({ path, body: {} });
        if (path === "/api/v1/operations/17") {
          return {
            status: 200,
            body: {
              data: this.terminal ??
                {
                  id: 17,
                  kind: this.kind,
                  status: "running",
                  result: null,
                  error_code: null,
                },
            },
          };
        }
        const key = decodeURIComponent(path.split("/").at(-1)!);
        return {
          status: 200,
          body: {
            data: {
              effect_key: `op-17.${key}`,
              state: this.receiptState ??
                (this.runs.has(key) ? "succeeded" : "unknown"),
              exit_code: 0,
              result: this.runs.get(key) ?? null,
              finished_at: "2026-10-28T21:00:00Z",
            },
          },
        };
      }
      const body = raw as Record<string, unknown>;
      this.calls.push({ path, body });
      if (body.worker !== this.worker) {
        return {
          status: 409,
          body: { error: { code: "operation.lease_lost" } },
        };
      }
      const spec = this.spec;
      let data: unknown;
      if (path.endsWith("/claim") && !this.available) {
        return { status: 200, body: { data: null } };
      }
      if (path.endsWith("/heartbeat") || path.endsWith("/claim")) {
        data = {
          id: 17,
          node_id: 2,
          kind: this.kind,
          status: "running",
          input: this.intent,
          cancel_requested: this.cancelled,
          steps: [],
          step_results: this.results,
          execution_context: {
            descriptors: [
              {
                key: this.preparation.key,
                step: this.preparation.key,
                transport: "gateway",
                name: this.preparation.name,
                input: this.preparation.input,
                resumable: true,
                required: true,
              },
              ...(this.prepared
                ? [
                  ...this.effects.map((d) => ({
                    ...d,
                    step: d.key,
                    resumable: true,
                    required: true,
                  })),
                  {
                    key: spec.key,
                    step: spec.key,
                    transport: spec.transport,
                    name: spec.name,
                    input: spec.input,
                    resumable: true,
                    required: true,
                  },
                ]
                : []),
            ],
          },
        };
      }
      if (path.endsWith("/run")) {
        const key = String(body.key);
        if (!this.runs.has(key)) {
          this.runs.set(
            key,
            this.refusal
              ? {
                error_code: this.refusal,
                ...(this.refusalStage ? { stage: this.refusalStage } : {}),
              }
              : key === this.preparation.key
              ? { prepared: true, version: "a".repeat(32) }
              : this.final,
          );
          if (key === this.preparation.key && !this.refusal) {
            this.prepared = true;
          }
        }
        data = this.runs.get(key);
        if (this.loseRun && key !== this.preparation.key) {
          this.loseRun = false;
          throw new Error("lost publication reply");
        }
      }
      if (path.endsWith("/step") && body.status === "succeeded") {
        this.results[String(body.name)] = body.result;
      }
      if (path.endsWith("/complete")) {
        this.terminal = { id: 17, kind: this.kind, ...body };
      }
      if (path.endsWith("/complete") && this.loseComplete) {
        this.loseComplete = false;
        throw new Error("lost completion reply");
      }
      return { status: 200, body: { data } };
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
  execute(worker = this.worker) {
    return routes(() => this.http).methods.execute.execute({
      kind: this.kind,
      worker,
    }, this.context());
  }
}

Deno.test("Caddy publication prepares and executes exactly the Gateway snapshot on the Operation Node", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "succeeded");
  const runs = f.calls.filter((x) => x.path.endsWith("/run")).map((x) =>
    x.body
  );
  assertEquals(runs.map((x) => x.key), ["prepare", "caddy-publish"]);
  assertEquals(runs[1].input, f.spec.input);
  assertEquals(runs.some((x) => "node_id" in x), false);
});
Deno.test("private DNS uses its own named transport with Gateway-rendered records and catalog", async () => {
  const f = new Fixture();
  f.kind = "route.private_dns_publish";
  f.final = {
    outcome: "published",
    data: { ready: true, digest: "b".repeat(64) },
    ready: true,
  };
  assertEquals((await f.execute()).status, "succeeded");
  const run = f.calls.find((x) => x.body.key === "private-dns-publish")!.body;
  assertEquals(run.transport, "route-dns");
  assertEquals(run.name, "route.publish-dns");
  assertEquals(run.input, f.spec.input);
});
Deno.test("render refusal records failure and never reloads a serving configuration", async () => {
  const f = new Fixture();
  f.refusal = "caddy.render_failed";
  f.refusalStage = "render";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs.size, 1);
  assertEquals(f.calls.at(-1)!.body.error_code, f.refusal);
  assertEquals(f.calls.at(-1)!.body.result, {
    error_code: "caddy.render_failed",
    stage: "render",
  });
});
Deno.test("a recovered worker uses saved step results without rendering again", async () => {
  const f = new Fixture();
  f.prepared = true;
  f.results = {
    prepare: { prepared: true },
    "caddy-publish": { result: "published", ready: true },
  };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs.size, 0);
});
Deno.test("lost publication reply retains its stable key and exact input", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "lost publication reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs.size, 2);
  const runs = f.calls.filter((x) => x.body.key === "caddy-publish");
  assertEquals(runs.length, 1);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/receipts/caddy-publish")).length,
    1,
  );
});
Deno.test("lost completion response replays permanent completion with no new effect", async () => {
  const f = new Fixture();
  f.loseComplete = true;
  await assertRejects(() => f.execute(), Error, "lost completion reply");
  const count = f.calls.length;
  await f.execute();
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.runs.size, 2);
});
Deno.test("expired lease rejects the old worker before publication", async () => {
  const f = new Fixture();
  f.worker = "workflow-2";
  await assertRejects(
    () => f.execute("workflow-1"),
    Error,
    "operation.lease_lost",
  );
  assertEquals(f.runs.size, 0);
  assertEquals((await f.execute()).status, "succeeded");
});
Deno.test("cancellation before publication does no Node work", async () => {
  const f = new Fixture();
  f.cancelled = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs.size, 0);
});
Deno.test("unproven publication never completes successfully", async () => {
  const f = new Fixture();
  f.final = { result: "unknown" };
  await assertRejects(() => f.execute(), Error, "unproven");
  assertEquals(f.calls.some((x) => x.path.endsWith("/complete")), false);
});

Deno.test("a running target receipt stops recovery without replaying or completing the effect", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.receiptState = "running";
  await assertRejects(() => f.execute(), Error, "still running");
  assertEquals(f.calls.filter((c) => c.body.key === "caddy-publish").length, 1);
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
});
Deno.test("only an unknown target receipt permits re-invoking the same effect key", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.receiptState = "unknown";
  assertEquals((await f.execute()).status, "succeeded");
  const runs = f.calls.filter((c) => c.body.key === "caddy-publish");
  assertEquals(runs.length, 2);
  assertEquals(runs[0].body, runs[1].body);
});

Deno.test("Caddy validation failure keeps its proven stage in final and replayed completion", async () => {
  const f = new Fixture();
  f.final = { error_code: "caddy.publication_failed", stage: "validate" };
  f.loseComplete = true;
  await assertRejects(() => f.execute(), Error, "lost completion reply");
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.terminal!.result, {
    error_code: "caddy.publication_failed",
    stage: "validate",
  });
});

Deno.test("a recovered Caddy validation receipt keeps its stage without another Node effect", async () => {
  const f = new Fixture();
  f.final = { error_code: "caddy.publication_failed", stage: "validate" };
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "lost publication reply");
  f.receiptState = "failed";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs.size, 2);
  assertEquals(f.calls.at(-1)!.body.result, {
    error_code: "caddy.publication_failed",
    stage: "validate",
  });
});

Deno.test("empty family claim ends without a heartbeat or effect", async () => {
  const f = new Fixture();
  f.available = false;
  assertEquals((await f.execute()).status, "idle");
  assertEquals(f.calls.map((call) => call.path), [
    "/api/v1/operation-executor/claim",
  ]);
  assertEquals(f.runs.size, 0);
});

Deno.test("retry after committed completion confirms the original result without another claim", async () => {
  const f = new Fixture();
  f.loseComplete = true;
  await assertRejects(() => f.execute());
  const before = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operations/17",
  ]);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/claim")).length,
    1,
  );
});

Deno.test("mismatched terminal result refuses the saved completion without claiming more work", async () => {
  const f = new Fixture();
  f.loseComplete = true;
  await assertRejects(() => f.execute());
  f.terminal!.result = { forged: true };
  const before = f.calls.length;
  await assertRejects(
    () => f.execute(),
    Error,
    "Unproven saved routes completion",
  );
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operations/17",
  ]);
});

Deno.test("resumed family dispatch with a lost lease never claims a replacement Operation", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.worker = "replacement-worker";
  const before = f.calls.length;
  await assertRejects(
    () => f.execute("workflow-1"),
    Error,
    "operation.lease_lost",
  );
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operation-executor/17/heartbeat",
  ]);
});
