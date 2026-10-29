import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { certificates } from "./mod.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "certificate.converge",
    status: "running",
    cancel_requested: false,
    input: {
      scope: "route-3-router",
      domain: "private.example.test",
      version: "op-7",
      root_hash: "a".repeat(64),
      user: "orbit",
      home: "/home/orbit",
    },
    step_results: {},
  });
  result: Record<string, unknown> = {
    state: "request",
    request_base64: btoa("public CSR"),
  };
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  calls: { path: string; body: Record<string, unknown> }[] = [];
  runs = 0;
  loseRun = false;
  loseComplete = false;
  worker = "run-1";
  available = true;
  terminal: Record<string, unknown> | null = null;
  http: Http = (method, path, raw) =>
    Promise.resolve().then(() => {
      if (method === "GET") {
        this.calls.push({ path, body: {} });
        if (path === "/api/v1/operations/7") {
          return {
            status: 200,
            body: {
              data: this.terminal ??
                { ...this.operation, result: null, error_code: null },
            },
          };
        }
        const key = decodeURIComponent(path.split("/").at(-1)!);
        return {
          status: 200,
          body: {
            data: {
              effect_key: `op-7.${key}`,
              state: this.receipts.has(key) ? "succeeded" : "unknown",
              exit_code: 0,
              result: this.receipts.get(key) ?? null,
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
      if (path.endsWith("/claim") && !this.available) {
        return { status: 200, body: { data: null } };
      }
      let data: unknown = this.operation;
      if (path.endsWith("/run")) {
        const key = String(body.key);
        if (!this.receipts.has(key)) {
          const value = key === "certificate-request"
            ? this.result
            : key === "certificate-sign"
            ? {
              signed: true,
              certificate_base64: btoa("leaf"),
              root_certificate_base64: btoa("root"),
            }
            : key === "certificate-inspect"
            ? { present: true }
            : {};
          this.receipts.set(key, value);
          this.runs++;
        }
        if (this.loseRun) {
          this.loseRun = false;
          throw new Error("Lost Node response");
        }
        data = this.receipts.get(key);
      }
      if (path.endsWith("/step") && body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
      if (path.endsWith("/complete")) {
        this.terminal = { id: 7, kind: this.operation.kind, ...body };
      }
      if (path.endsWith("/complete") && this.loseComplete) {
        this.loseComplete = false;
        throw new Error("Lost completion response");
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
    return certificates(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}

Deno.test("new leaf request is signed on Gateway and published by reference before inspection", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "succeeded");
  const runs = f.calls.filter((x) => x.path.endsWith("/run")).map((x) =>
    x.body
  );
  assertEquals(runs.map((x) => x.name), [
    "certificate.request-leaf",
    "certificate.sign-leaf",
    "certificate.publish-leaf",
    "certificate.inspect-leaf",
  ]);
  assertEquals(runs[1].transport, "gateway");
  assertEquals((runs[2].input as Record<string, unknown>).certificate, {
    $secret: "certificate.leaf",
    id: 7,
  });
  assertEquals(runs.some((x) => "node_id" in x), false);
  assertEquals(f.calls.at(-1)!.body.result, { present: true });
});
Deno.test("a current leaf is inspected without generating or signing another key", async () => {
  const f = new Fixture();
  f.result = { state: "current" };
  await f.execute();
  assertEquals(
    f.calls.filter((x) => x.path.endsWith("/run")).map((x) => x.body.name),
    ["certificate.request-leaf", "certificate.inspect-leaf"],
  );
});
Deno.test("remove uses only the recorded scope and home", async () => {
  const f = new Fixture();
  f.operation.kind = "certificate.remove";
  await f.execute();
  assertEquals(f.calls.find((x) => x.path.endsWith("/run"))!.body.input, {
    scope: "route-3-router",
    home: "/home/orbit",
  });
});
Deno.test("certificate refusal closes its failed step and stops publication", async () => {
  const f = new Fixture();
  f.result = { error_code: "certificate.leaf_failed" };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs, 1);
  assertEquals(f.calls.at(-1)!.body.error_code, "certificate.leaf_failed");
});
Deno.test("lost request response reuses its stable effect key, version and recorded receipt", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node response");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs, 4);
  const runs = f.calls.filter((x) =>
    x.body.name === "certificate.request-leaf"
  );
  assertEquals(runs.length, 1);
  assertEquals(
    f.calls.some((c) => c.path.endsWith("/receipts/certificate-request")),
    true,
  );
});
Deno.test("lost completion response replays persisted completion without Node work", async () => {
  const f = new Fixture();
  f.loseComplete = true;
  await assertRejects(() => f.execute());
  const count = f.calls.length;
  await f.execute();
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.runs, 4);
});
Deno.test("lease takeover stops the old worker before key generation", async () => {
  const f = new Fixture();
  f.worker = "run-2";
  await assertRejects(() => f.execute("run-1"), Error, "operation.lease_lost");
  assertEquals(f.runs, 0);
  await f.execute("run-2");
  assertEquals(f.runs, 4);
});
Deno.test("cancellation before a leaf request does no Node work", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs, 0);
});
Deno.test("unknown request evidence never reaches signing or completion", async () => {
  const f = new Fixture();
  f.result = {};
  await assertRejects(() => f.execute(), Error, "Unproven certificate request");
  assertEquals(f.runs, 1);
  assertEquals(f.calls.some((x) => x.path.endsWith("/complete")), false);
});

Deno.test("empty family claim ends without a heartbeat or effect", async () => {
  const f = new Fixture();
  f.available = false;
  assertEquals((await f.execute()).status, "idle");
  assertEquals(f.calls.map((call) => call.path), [
    "/api/v1/operation-executor/claim",
  ]);
  assertEquals(f.runs, 0);
});

Deno.test("retry after committed completion confirms the original result without another claim", async () => {
  const f = new Fixture();
  f.loseComplete = true;
  await assertRejects(() => f.execute());
  const before = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operations/7",
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
    "Unproven saved certificates completion",
  );
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operations/7",
  ]);
});

Deno.test("resumed family dispatch with a lost lease never claims a replacement Operation", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute());
  f.worker = "replacement-worker";
  const before = f.calls.length;
  await assertRejects(() => f.execute("run-1"), Error, "operation.lease_lost");
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operation-executor/7/heartbeat",
  ]);
});
