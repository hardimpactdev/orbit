import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Probe, verification, verifyLocal } from "./verification.ts";

const args = {
  operation_id: 17,
  kind: "route.workload_verify" as const,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const input = {
  protocol: "tls",
  agent_sha256: "a".repeat(64),
  probe: { address: "192.168.6.18", port: 443, domain: "demo.private.test" },
};
function fixture(change: Record<string, unknown> = {}) {
  const calls: { method: string; path: string; body: unknown }[] = [];
  const seen: unknown[] = [];
  let response = { ready: true } as Record<string, unknown>;
  const http: Http = (method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({
      status: 200,
      body: {
        data: method === "GET"
          ? {
            id: 17,
            kind: args.kind,
            node_id: 9,
            status: "queued",
            input,
            result: null,
            cancel_requested: false,
            ...change,
          }
          : {},
      },
    });
  };
  const action = (value: unknown) => {
    seen.push(value);
    return Promise.resolve(response);
  };
  const run = () =>
    verification(() => http, action).methods.verify.execute(args, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    });
  return {
    calls,
    seen,
    run,
    response: (value: Record<string, unknown>) => {
      response = value;
    },
  };
}

Deno.test("Route verification uses only safe admitted input and shared read/result endpoints", async () => {
  const f = fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.seen, [input]);
  assertEquals(f.calls.map((c) => c.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals((f.calls.at(-1)!.body as Record<string, unknown>).result, {
    ready: true,
  });
});
Deno.test("an unbound first read refuses before a probe or projection", async () => {
  let effects = 0;
  const paths: string[] = [];
  const http: Http = (_method, path) => {
    paths.push(path);
    return Promise.resolve({
      status: 409,
      body: { error: { code: "operation.run_unbound" } },
    });
  };
  const action = () => {
    effects++;
    return Promise.resolve({ ready: true });
  };
  await assertRejects(() =>
    verification(() => http, action).methods.verify.execute(args, {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    })
  );
  assertEquals(effects, 0);
  assertEquals(paths, [`/api/v1/direct-operations/17?run_id=${args.run_id}`]);
});
Deno.test("foreign Node and operation identity never reach the local action", async () => {
  for (
    const change of [{ node_id: 18 }, { id: 18 }, {
      kind: "route.public_verify",
    }]
  ) {
    const f = fixture(change);
    await assertRejects(f.run);
    assertEquals(f.seen, []);
    assertEquals(f.calls.length, 1);
  }
});
Deno.test("terminal and cancelled verification intents do not probe again", async () => {
  for (
    const change of [{ status: "succeeded", result: { ready: true } }, {
      cancel_requested: true,
    }]
  ) {
    const f = fixture(change);
    await f.run();
    assertEquals(f.seen, []);
  }
});
Deno.test("bounded Route refusals are projected without raw diagnostics", async () => {
  const f = fixture();
  f.response({
    error_code: "route.certificate_invalid",
    stderr: "raw diagnostic",
  });
  await assertRejects(f.run, Error, "route.certificate_invalid");
  const body = f.calls.at(-1)!.body as Record<string, unknown>;
  assertEquals(body.status, "failed");
  assertEquals(body.result, { error_code: "route.certificate_invalid" });
});
Deno.test("invalid results and worker-selected inputs refuse without a success projection", async () => {
  for (
    const result of [{ ready: false }, { error_code: "unbounded refusal!" }]
  ) {
    const f = fixture();
    f.response(result);
    await assertRejects(f.run);
    assertEquals(f.calls.length, 2);
  }
  assertEquals(
    Probe.safeParse({ ...input, command: "foreign" }).success,
    false,
  );
  assertEquals(
    Probe.safeParse({
      ...input,
      probe: { ...input.probe, address: "foreign-host" },
    }).success,
    false,
  );
  assertEquals(Probe.safeParse({ ...input, protocol: "skip" }).success, false);
});
Deno.test("colocated Router skip needs no executable or privilege boundary", async () => {
  assertEquals(await verifyLocal({ ...input, protocol: "skip", probe: null }), {
    ready: true,
  });
});
