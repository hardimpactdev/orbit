import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { publication } from "./publication.ts";

const args = {
  operation_id: 17,
  node_id: 9,
  kind: "route.caddy_converge" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
class Fixture {
  operation = {
    id: 17,
    node_id: 9,
    kind: "route.caddy_converge",
    status: "queued",
    result: null as Record<string, unknown> | null,
    cancel_requested: false,
  };
  result: Record<string, unknown> = { result: "published", ready: true };
  calls: { path: string; body: Record<string, unknown> }[] = [];
  loseCompletion = false;
  losePublication = false;
  http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      this.calls.push({ path, body: body as Record<string, unknown> });
      if (method === "GET") {
        return { status: 200, body: { data: this.operation } };
      }
      if (path.endsWith("/publish")) {
        if (this.losePublication) {
          this.losePublication = false;
          throw new Error("lost publication reply");
        }
        return { status: 200, body: { data: this.result } };
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
    return publication(() => this.http).methods.publish.execute(
      { ...args, kind: kind as typeof args.kind },
      ctx,
    );
  }
}

Deno.test("native Route publication uses one stored Node and no claim lease receipt or poll calls", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/route-publications/17?run_id=${args.run_id}`,
    "/api/v1/route-publications/17/result",
    "/api/v1/route-publications/17/publish",
    "/api/v1/route-publications/17/result",
  ]);
  assertEquals(f.calls[2].body, { run_id: args.run_id });
  assertEquals(f.calls[3].body.result, f.result);
});

Deno.test("private DNS publication uses the same native flow without a separate dispatch wait", async () => {
  const f = new Fixture();
  f.operation.kind = "route.private_dns_publish";
  f.result = { outcome: "published", data: { ready: true }, ready: true };
  assertEquals(await f.execute(f.operation.kind), { status: "succeeded" });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/publish")).length,
    1,
  );
});

Deno.test("changed Node or kind refuses before publication", async () => {
  for (const field of ["node_id", "kind"] as const) {
    const f = new Fixture();
    if (field === "node_id") f.operation.node_id = 4;
    else f.operation.kind = "route.private_dns_publish";
    await assertRejects(() => f.execute(), Error, "does not match");
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("render and validation refusals keep their bounded stage and fail the native step", async () => {
  for (const stage of ["render", "validate"]) {
    const f = new Fixture();
    f.result = { error_code: "caddy.publication_failed", stage };
    await assertRejects(() => f.execute(), Error, "caddy.publication_failed");
    assertEquals(f.calls.at(-1)!.body.status, "failed");
    assertEquals(f.calls.at(-1)!.body.result, f.result);
  }
});

Deno.test("native retry after lost terminal projection reads the committed result without another publication", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "lost completion reply");
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/publish")).length,
    1,
  );
});

Deno.test("uncertain publication escapes for native step recovery without inventing a receipt or local retry", async () => {
  const f = new Fixture();
  f.losePublication = true;
  await assertRejects(() => f.execute(), Error, "lost publication reply");
  assertEquals(f.calls.length, 3);
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/publish")).length,
    2,
  );
});

Deno.test("cancelled intent makes no Node call and projects its native outcome", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals(await f.execute(), { status: "cancelled" });
  assertEquals(f.calls.some((call) => call.path.endsWith("/publish")), false);
});

Deno.test("unproven or malformed publication refuses terminal success", async () => {
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
