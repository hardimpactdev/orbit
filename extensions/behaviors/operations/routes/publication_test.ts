import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { stub } from "jsr:@std/testing@1.0.17/mock";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { publication } from "./publication.ts";

const args = {
  operation_id: 17,
  node_id: 9,
  kind: "route.caddy_converge" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const caddy = {
  content: "",
  version: "e3b0c44298fc1c149afbf4c8996fb924",
  listen_addresses: ["10.44.0.9"],
  unserved: false,
};
const payload = {
  node_id: 9,
  user: "nckrtl",
  agent_sha256: "a".repeat(64),
  caddy,
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
for (const preAborted of [true, false]) {
  Deno.test(`default Route HTTP preserves projection when cancellation is ${preAborted ? "before" : "during"} the primitive`, async () => {
    const controller = new AbortController();
    if (preAborted) controller.abort();
    const calls: Record<string, unknown>[] = [];
    let effects = 0;
    const createClient = Deno.createHttpClient;
    using _read = stub(Deno, "readTextFile", () => Promise.resolve(""));
    using _client = stub(Deno, "createHttpClient", () => createClient({}));
    using _fetch = stub(globalThis, "fetch", (url, init) => {
      assertEquals(init?.signal?.aborted, false);
      const path = String(url);
      if (init?.method === "GET") {
        return Promise.resolve(
          Response.json({ data: new Fixture().operation }),
        );
      }
      if (path.endsWith("/primitive")) {
        effects++;
        controller.abort();
        assertEquals(init?.signal?.aborted, false);
        return Promise.resolve(Response.json({ data: { ready: true } }));
      }
      calls.push(JSON.parse(String(init?.body)));
      return Promise.resolve(Response.json({ data: {} }));
    });
    const expected = preAborted ? "cancelled" : "succeeded";
    assertEquals(
      await publication(undefined, () => {
        effects++;
        controller.abort();
        return Promise.resolve({ result: "published" });
      }).methods.convergeCaddy.execute(args, {
        ...ctx,
        signal: controller.signal,
      }),
      { status: expected },
    );
    assertEquals(effects, preAborted ? 0 : 1);
    assertEquals(calls.map((call) => call.status), ["running", expected]);
  });
}
class Fixture {
  operation = {
    id: 17,
    node_id: 9,
    kind: "route.caddy_converge",
    status: "queued",
    result: null as Record<string, unknown> | null,
    cancel_requested: false,
    input: { caddy_publication: payload },
  };
  result: Record<string, unknown> = { result: "published", ready: true };
  calls: { path: string; body: Record<string, unknown> }[] = [];
  loseCompletion = false;
  effects: unknown[] = [];
  losePublication = false;
  http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      this.calls.push({ path, body: body as Record<string, unknown> });
      if (method === "GET") {
        return { status: 200, body: { data: this.operation } };
      }
      if (path.endsWith("/primitive")) {
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
    const model = publication(
      () => this.http,
      (call) =>
        Promise.resolve().then(() => {
          this.effects.push(call);
          if (this.losePublication) {
            this.losePublication = false;
            throw new Error("lost publication reply");
          }
          return this.result;
        }),
    );
    return kind === "route.caddy_converge"
      ? model.methods.convergeCaddy.execute({ ...args, kind }, ctx)
      : model.methods.publish.execute({
        ...args,
        kind: "route.private_dns_publish",
      }, ctx);
  }
}

Deno.test("native Route publication uses one stored Node and no claim lease receipt or poll calls", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals(f.effects.length, 1);
  assertEquals(f.calls[2].body.result, { ...f.result, version: caddy.version });
});

Deno.test("private DNS publication uses the same native flow without a separate dispatch wait", async () => {
  const f = new Fixture();
  f.operation.kind = "route.private_dns_publish";
  f.result = { outcome: "published", data: { ready: true }, ready: true };
  assertEquals(await f.execute(f.operation.kind), { status: "succeeded" });
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/primitive")).length,
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
  for (const stage of ["validate", "reload"]) {
    const f = new Fixture();
    f.result = { error_code: "caddy.publication_failed", stage };
    await assertRejects(() => f.execute(), Error, "caddy.publication_failed");
    assertEquals(f.calls.at(-1)!.body.status, "failed");
    assertEquals(f.calls.at(-1)!.body.result, {
      error_code: "caddy.publication_failed",
      ...(stage === "validate" ? { stage } : {}),
    });
  }
});

Deno.test("native retry after lost terminal projection reads the committed result without another publication", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "lost completion reply");
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.effects.length,
    1,
  );
});

Deno.test("uncertain publication escapes for native step recovery without inventing a receipt or local retry", async () => {
  const f = new Fixture();
  f.losePublication = true;
  await assertRejects(() => f.execute(), Error, "lost publication reply");
  assertEquals(f.calls.length, 2);
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.effects.length,
    2,
  );
});

Deno.test("cancelled intent makes no Node call and projects its native outcome", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals(await f.execute(), { status: "cancelled" });
  assertEquals(f.calls.some((call) => call.path.endsWith("/primitive")), false);
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

Deno.test("same-root Process Caddy step reads current public data without projecting the parent", async () => {
  const f = new Fixture();
  f.operation.kind = "process.start";
  const model = publication(
    () => f.http,
    (call) =>
      Promise.resolve().then(() => {
        f.effects.push(call);
        return { result: "published" };
      }),
  );
  assertEquals(
    await model.methods.publishCaddy.execute(
      { ...args, kind: "process.start" },
      ctx,
    ),
    {
      ready: true,
      result: "published",
      version: caddy.version,
    },
  );
  assertEquals(f.calls.length, 1);
  assertEquals(f.effects.length, 1);
  assertEquals(
    f.calls[0].path,
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
  );
  assertEquals(f.operation.status, "queued");
});

Deno.test("same-root method refuses foreign payload or ended and cancelled parent before local effects", async () => {
  for (const variant of ["payload", "parent", "kind", "cancel", "terminal"]) {
    const f = new Fixture();
    f.operation.kind = "process.restart";
    f.operation.input = { caddy_publication: { ...payload } };
    if (variant === "payload") f.operation.input.caddy_publication.node_id = 4;
    if (variant === "parent") f.operation.node_id = 4;
    if (variant === "kind") f.operation.kind = "process.update";
    if (variant === "cancel") f.operation.cancel_requested = true;
    if (variant === "terminal") f.operation.status = "succeeded";
    let effects = 0;
    const model = publication(() => f.http, () => {
      effects++;
      return Promise.resolve({ result: "published" });
    });
    await assertRejects(() =>
      model.methods.publishCaddy.execute(
        { ...args, kind: "process.restart" },
        ctx,
      )
    );
    assertEquals(effects, 0);
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("each same-root method invocation fetches again rather than caching the earlier render", async () => {
  const f = new Fixture();
  f.operation.kind = "process.update";
  const seen: unknown[] = [];
  const model = publication(
    () => f.http,
    (call) =>
      Promise.resolve().then(() => {
        seen.push(call.input);
        return { result: "unchanged" };
      }),
  );
  await model.methods.publishCaddy.execute(
    { ...args, kind: "process.update" },
    ctx,
  );
  f.operation.input = {
    caddy_publication: {
      ...payload,
      caddy: { ...caddy, unserved: true, listen_addresses: [] },
    },
  };
  await model.methods.publishCaddy.execute(
    { ...args, kind: "process.update" },
    ctx,
  );
  assertEquals(seen, [caddy, f.operation.input.caddy_publication.caddy]);
  assertEquals(f.calls.length, 2);
});
