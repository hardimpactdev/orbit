import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { cleanup } from "./cleanup.ts";

const args = {
  operation_id: 17,
  node_id: 209 as const,
  kind: "database.sqlite_cleanup" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
class Fixture {
  operation = {
    id: 17,
    node_id: 209,
    kind: "database.sqlite_cleanup",
    status: "queued",
    result: null as Record<string, unknown> | null,
    cancel_requested: false,
    input: {
      variant: "clone",
      agent_sha256: "a".repeat(64),
      directory: `/tmp/orbit-sqlite-clone-${"b".repeat(32)}`,
    },
  };
  result: Record<string, unknown> = { backend: "absent" };
  calls: { path: string; body: Record<string, unknown> }[] = [];
  loseCompletion = false;
  loseCleanup = false;
  localCalls: unknown[] = [];
  local = async (input: unknown) => {
    const { cleanupPayload } = await import("./cleanup_contract.ts");
    cleanupPayload(input);
    this.localCalls.push(input);
    if (this.loseCleanup) {
      this.loseCleanup = false;
      throw new Error("lost cleanup reply");
    }
    return this.result;
  };
  http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      this.calls.push({ path, body: body as Record<string, unknown> });
      if (method === "GET") {
        return { status: 200, body: { data: this.operation } };
      }
      const value = body as Record<string, unknown>;
      this.operation.status = String(value.status);
      this.operation.result = value.result as Record<string, unknown> ?? null;
      if (this.loseCompletion && value.status !== "running") {
        this.loseCompletion = false;
        throw new Error("lost completion reply");
      }
      return { status: 200, body: { data: this.operation } };
    });
  execute(kind = args.kind as string) {
    return cleanup(() => this.http, this.local).methods.cleanup.execute(
      { ...args, kind: kind as typeof args.kind },
      ctx,
    );
  }
}

Deno.test("native Database cleanup uses one stored Node and no claim lease receipt or poll calls", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals(f.localCalls, [f.operation.input]);
  assertEquals(f.calls[2].body.result, f.result);
});

Deno.test("changed Node or kind refuses before cleanup", async () => {
  for (const field of ["node_id", "kind"] as const) {
    const f = new Fixture();
    if (field === "node_id") f.operation.node_id = 4;
    else f.operation.kind = "database.sqlite_export";
    await assertRejects(() => f.execute());
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("native retry after lost terminal projection reads the committed result without another cleanup", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "lost completion reply");
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.localCalls.length,
    1,
  );
});

Deno.test("uncertain cleanup escapes for native step recovery without inventing a receipt or local retry", async () => {
  const f = new Fixture();
  f.loseCleanup = true;
  await assertRejects(() => f.execute(), Error, "lost cleanup reply");
  assertEquals(f.calls.length, 2);
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.localCalls.length,
    2,
  );
});

Deno.test("cancelled intent makes no Node call and projects its native outcome", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals(await f.execute(), { status: "cancelled" });
  assertEquals(f.localCalls.length, 0);
});

Deno.test("unproven or malformed cleanup refuses terminal success", async () => {
  for (
    const result of [{ result: "unknown" }, {
      error_code: "private diagnostic spaces",
    }]
  ) {
    const f = new Fixture();
    f.result = result;
    await assertRejects(() => f.execute());
    assertEquals(
      f.calls.filter((call) => call.path.endsWith("/result")).length,
      1,
    );
  }
});

Deno.test("fixed cleanup refuses with its bounded code and native run fails", async () => {
  const f = new Fixture();
  f.result = { error_code: "instance.sqlite_copy_refused" };
  await assertRejects(() => f.execute(), Error, "instance.sqlite_copy_refused");
  assertEquals(f.calls.at(-1)!.body.status, "failed");
  assertEquals(f.calls.at(-1)!.body.error_code, "instance.sqlite_copy_refused");
  await assertRejects(() => f.execute(), Error, "instance.sqlite_copy_refused");
  assertEquals(
    f.localCalls.length,
    1,
  );
});

Deno.test("an unbound first read refuses before any local action and native retry sees the binding", async () => {
  const f = new Fixture();
  let bound = false;
  const http: Http = (method, path, body) =>
    !bound
      ? Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      })
      : f.http(method, path, body);
  const run = () =>
    cleanup(() => http, f.local).methods.cleanup.execute(args, ctx);
  await assertRejects(run, Error, "request did not succeed");
  assertEquals(f.localCalls.length, 0);
  assertEquals(f.operation.status, "queued");
  bound = true;
  assertEquals(await run(), { status: "succeeded" });
  assertEquals(f.localCalls.length, 1);
});

Deno.test("malformed admitted input cannot select another local command or account", async () => {
  const f = new Fixture();
  Object.assign(f.operation.input, { owner: "root" });
  await assertRejects(() => f.execute());
  assertEquals(f.localCalls.length, 0);
});
