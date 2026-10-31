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
  Deno.test(`default Route HTTP preserves projection when cancellation is ${preAborted ? "before" : "during"} the local publication`, async () => {
    const controller = new AbortController();
    if (preAborted) controller.abort();
    const calls: Record<string, unknown>[] = [];
    let effects = 0;
    const createClient = Deno.createHttpClient;
    using _read = stub(Deno, "readTextFile", () => Promise.resolve(""));
    using _client = stub(Deno, "createHttpClient", () => createClient({}));
    using _fetch = stub(globalThis, "fetch", (url, init) => {
      assertEquals(init?.signal?.aborted, false);
      assertEquals(String(url).includes("/direct-operations/17"), true);
      if (init?.method === "GET") {
        return Promise.resolve(
          Response.json({ data: new Fixture().operation }),
        );
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
            throw new Error("operation.native_outcome_unknown");
          }
          return this.result;
        }),
      (input) => {
        this.effects.push(input);
        return Promise.resolve(this.result);
      },
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
  const catalog = "{}";
  const digest =
    "44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a";
  Object.assign(f.operation.input, {
    private_dns_publication: { node_id: 9, records: "", catalog, digest },
  });
  f.result = { outcome: "published", data: { ready: true, digest } };
  assertEquals(await f.execute(f.operation.kind), { status: "succeeded" });
  assertEquals(f.effects.length, 1);
  assertEquals(
    f.calls.some((call) => /primitive|claim|children/.test(call.path)),
    false,
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

Deno.test("uncertain publication records a safe failure and never repeats a terminal effect", async () => {
  const f = new Fixture();
  f.losePublication = true;
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.native_outcome_unknown",
  );
  assertEquals(f.calls.length, 3);
  assertEquals(f.operation.status, "failed");
  assertEquals(f.operation.result, {
    error_code: "operation.native_outcome_unknown",
  });
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.native_outcome_unknown",
  );
  assertEquals(
    f.effects.length,
    1,
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
      2,
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

Deno.test("participant method refuses foreign payload or ended and cancelled parent before local effects", async () => {
  for (
    const variant of ["payload", "operation", "kind", "cancel", "terminal"]
  ) {
    const f = new Fixture();
    f.operation.kind = "process.restart";
    f.operation.input = { caddy_publication: { ...payload } };
    if (variant === "payload") f.operation.input.caddy_publication.node_id = 4;
    if (variant === "operation") f.operation.id = 18;
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

Deno.test("participant Caddy keeps the truthful root Node and publishes only the placed Node's fresh artifact", async () => {
  for (const explicitTarget of [true]) {
    const f = new Fixture();
    f.operation.kind = "metrics.reconcile";
    f.operation.node_id = 17;
    f.operation.input = { caddy_publication: { ...payload, node_id: 13 } };
    const model = publication(() => f.http, (call) => {
      f.effects.push(call);
      return Promise.resolve({ result: "published" });
    });
    assertEquals(
      await model.methods.publishCaddy.execute({
        ...args,
        kind: "metrics.reconcile",
        node_id: explicitTarget ? 17 : 13,
        ...(explicitTarget ? { target_node_id: 13 } : {}),
      }, ctx),
      { ready: true, result: "published", version: caddy.version },
    );
    assertEquals(f.operation.node_id, 17);
    assertEquals(f.calls.length, 1);
    assertEquals(
      f.calls[0].path,
      `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    );
    assertEquals(f.effects.length, 1);
  }
});

Deno.test("each same-root method invocation fetches again rather than caching the earlier render", async () => {
  const f = new Fixture();
  f.operation.kind = "process.update";
  const seen: unknown[] = [];
  const model = publication(
    () => f.http,
    (_program, input) =>
      Promise.resolve().then(() => {
        seen.push(input);
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

Deno.test("participant PHP fresh read preserves whole-Node survivors and never posts the parent", async () => {
  let generation = 0;
  const calls: string[] = [];
  const effects: unknown[] = [];
  const model = publication(
    () => (_method, path) => {
      calls.push(path);
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            kind: "instance.destroy",
            node_id: 17,
            status: "running",
            cancel_requested: false,
            input: {
              php_publication: {
                node_id: 9,
                agent_sha256: "a".repeat(64),
                configurations: { "8.5": `survivors-${++generation}` },
              },
            },
          },
        },
      });
    },
    undefined,
    undefined,
    (_program, input) => {
      effects.push(input);
      return Promise.resolve({ changed: true, missing_pools: [] });
    },
  );
  const input = {
    ...args,
    kind: "instance.destroy",
    node_id: 17,
    target_node_id: 9,
  };
  await model.methods.publishPhp.execute(input, ctx);
  await model.methods.publishPhp.execute(input, ctx);
  assertEquals(effects, [{ configurations: { "8.5": "survivors-1" } }, {
    configurations: { "8.5": "survivors-2" },
  }]);
  assertEquals(calls, [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
  ]);
});

Deno.test("participant DNS reads fresh owner artifact and rejects a different effect Node", async () => {
  const catalog = "{}\n";
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(catalog)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  let effects = 0;
  const model = publication(
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            kind: "node.remove",
            node_id: 9,
            status: "running",
            cancel_requested: false,
            input: {
              private_dns_publication: {
                node_id: 1,
                catalog,
                digest,
                records: "# fresh\n",
              },
            },
          },
        },
      }),
    undefined,
    () => {
      effects++;
      return Promise.resolve({
        outcome: "published",
        data: { ready: true, digest },
      });
    },
  );
  const input = { ...args, kind: "node.remove", target_node_id: 1 };
  assertEquals(
    (await model.methods.publishDns.execute(input, ctx)).ready,
    true,
  );
  await assertRejects(() =>
    model.methods.publishDns.execute({ ...input, target_node_id: 18 }, ctx)
  );
  assertEquals(effects, 1);
});

Deno.test("participant firewall uses a fresh exact Node artifact under the publication model without parent mutation", async () => {
  let node = 13, cancelled = false, effects = 0, reads = 0;
  const model = publication(
    () => () => {
      reads++;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            kind: "analytics.enable",
            node_id: 17,
            status: "running",
            cancel_requested: cancelled,
            input: {
              caddy_publication: { node_id: node },
              ingress_firewall: { desired: [], remove: [], enable: false },
            },
          },
        },
      });
    },
    undefined,
    undefined,
    undefined,
    undefined,
    (_program, input) => {
      effects++;
      assertEquals(input, {
        mode: "rules",
        desired: [],
        remove: [],
        enable: false,
      });
      return Promise.resolve({ matches: true });
    },
  );
  const input = {
    ...args,
    kind: "analytics.enable",
    node_id: 17,
    target_node_id: 13,
  };
  assertEquals(await model.methods.publishFirewall.execute(input, ctx), {
    matches: true,
  });
  node = 14;
  await assertRejects(() => model.methods.publishFirewall.execute(input, ctx));
  node = 13;
  cancelled = true;
  await assertRejects(() => model.methods.publishFirewall.execute(input, ctx));
  assertEquals(effects, 1);
  assertEquals(reads, 3);
});

Deno.test("restoration requires explicit recovery and keeps exact root, placement and unfinished bounds", async () => {
  const f = new Fixture();
  f.operation.kind = "instance.transfer_rollback";
  f.operation.cancel_requested = true;
  let effects = 0;
  const model = publication(() => f.http, () => {
    effects++;
    return Promise.resolve({ result: "published" });
  });
  const input = { ...args, kind: f.operation.kind, target_node_id: 9 };
  const controller = new AbortController();
  controller.abort();
  await assertRejects(() =>
    model.methods.publishCaddy.execute(input, {
      ...ctx,
      signal: controller.signal,
    })
  );
  await model.methods.publishCaddy.execute({ ...input, recovery: true }, {
    ...ctx,
    signal: controller.signal,
  });
  assertEquals(effects, 1);
  await assertRejects(() =>
    model.methods.publishCaddy.execute(
      { ...input, node_id: 4, recovery: true },
      ctx,
    )
  );
  await assertRejects(() =>
    model.methods.publishCaddy.execute({
      ...input,
      target_node_id: 4,
      recovery: true,
    }, ctx)
  );
  f.operation.status = "cancelled";
  await assertRejects(() =>
    model.methods.publishCaddy.execute({ ...input, recovery: true }, ctx)
  );
  assertEquals(effects, 1);
});

Deno.test("Instance private PHP publication stays under the canonical publication model and never projects the parent", async () => {
  const paths: string[] = [];
  let effectNode = 0;
  const behavior = publication(
    () => (method, path) => {
      paths.push(path);
      assertEquals(method, "GET");
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            kind: "instance.destroy",
            node_id: 9,
            status: "running",
            cancel_requested: false,
            input: {},
          },
        },
      });
    },
    undefined,
    undefined,
    undefined,
    undefined,
    undefined,
    (nodeId) => {
      effectNode = nodeId;
      return Promise.resolve({ ready: true });
    },
  );
  assertEquals(
    await behavior.methods.withdrawInstancePhp.execute({
      ...args,
      kind: "instance.destroy",
    }, ctx),
    { ready: true },
  );
  assertEquals(effectNode, 9);
  assertEquals(paths.length, 1);
});
Deno.test("Instance DNS withdrawal reads the selected participant's fresh catalog and rejects conflicting renders", async () => {
  const catalog = "{}\n";
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(catalog)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  const row = { node_id: 1, records: "# current", catalog, digest };
  const dns = [row];
  let effects = 0;
  const behavior = publication(
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            kind: "instance.destroy",
            node_id: 9,
            status: "running",
            cancel_requested: false,
            input: { withdrawal_publications: [{ dns }] },
          },
        },
      }),
    undefined,
    (input) => {
      effects++;
      assertEquals(input, row);
      return Promise.resolve({
        outcome: "published",
        data: { ready: true, digest },
      });
    },
  );
  await behavior.methods.withdrawInstanceDns.execute({
    ...args,
    kind: "instance.destroy",
    target_node_id: 1,
  }, ctx);
  dns.push({ ...row, records: "# changed" });
  await assertRejects(
    () =>
      behavior.methods.withdrawInstanceDns.execute({
        ...args,
        kind: "instance.destroy",
        target_node_id: 1,
      }, ctx),
    Error,
    "route.withdrawal_invalid",
  );
  assertEquals(effects, 1);
});
