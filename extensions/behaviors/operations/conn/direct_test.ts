import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { connDirect, executeConn, requestT3, type T3 } from "./mod.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const identity = {
  operation_id: 17,
  node_id: 9,
  kind: "conn.pairing_create" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
  const resources = new Map<string, Record<string, unknown>>();
  const projections: unknown[] = [];
  const calls: string[] = [];
  const input = {
    action: "pairing_create",
    environment: {
      id: 1,
      url: "http://t3.test",
      admin_session: "admin-secret-sentinel",
    },
    labels: ["phone via Orbit test"],
    links: ["link-1"],
    pairing_id: 7,
    issue_started: false,
    issued: false,
    pairing_link_id: null as string | null,
    expires_at: null as string | null,
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca" },
    readResource: (key: string) => Promise.resolve(resources.get(key) ?? null),
    writeResource: (
      _spec: string,
      key: string,
      value: Record<string, unknown>,
    ) => {
      resources.set(key, value);
      return Promise.resolve({ name: key });
    },
  };
  const safe = () => {
    const { environment: _environment, ...value } = input;
    return structuredClone(value);
  };
  const http: Http = (method, path, raw) => {
    calls.push(path);
    if (path.includes("/private-material")) {
      return Promise.resolve({
        status: 200,
        body: { data: { environment: structuredClone(input.environment) } },
      });
    }
    if (path.endsWith("/secret-output")) {
      input.issued = true;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            stored: {
              credential: { $secret: "conn.pairing.credential", id: 7 },
            },
          },
        },
      });
    }
    if (method === "POST") {
      projections.push(raw);
      const result = (raw as {
        result?: {
          backend?: string;
          pairing_link_id?: string;
          expires_at?: string;
        };
      }).result;
      if (result?.backend === "issuing") input.issue_started = true;
      if (result?.backend === "issued") {
        input.pairing_link_id = result.pairing_link_id!;
        input.expires_at = result.expires_at!;
      }
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          node_id: 9,
          kind: identity.kind,
          input: safe(),
          status: "queued",
          cancel_requested: false,
          result: null,
        },
      },
    });
  };
  const gateway = new GatewayOperation(http, identity);
  const effects: unknown[] = [];
  const t3: T3 = (_method, path, _input, body) => {
    effects.push({ path, body });
    if (path === "/api/auth/pairing-token") {
      return Promise.resolve({
        id: "link-1",
        credential: "pairing-secret-sentinel",
        expiresAt: "2026-10-30T23:59:00.000Z",
      });
    }
    return Promise.reject(new Error("Unexpected effect"));
  };
  return {
    ctx,
    resources,
    input,
    http,
    gateway,
    safe,
    calls,
    effects,
    t3,
    projections,
  };
}
Deno.test("Conn refuses a recorded issue after its native checkpoint is lost", async () => {
  const f = fixture();
  f.input.issue_started = true;
  assertEquals(await executeConn(identity, f.ctx, f.gateway, f.t3, f.safe()), {
    error_code: "conn.pairing_outcome_unknown",
  });
  assertEquals(f.resources.size, 0);
  assertEquals(f.effects.length, 0);
});

Deno.test("Conn issue uses private input/output and native checkpoint, with only safe projection", async () => {
  const f = fixture();
  assertEquals(
    await connDirect(() => f.http, f.t3).methods.apply.execute(identity, f.ctx),
    { status: "succeeded" },
  );
  assertEquals(f.effects.length, 1);
  assertEquals([...f.resources.values()], [{ started: true }]);
  assertFalse(
    JSON.stringify([f.resources, f.projections, f.calls]).includes(
      "secret-sentinel",
    ),
  );
  assertFalse(f.calls.some((p) => p.includes("operation-executor")));
});
Deno.test("Conn uncertain issue is never repeated on a resumed native checkpoint", async () => {
  const f = fixture();
  const fail: T3 = () =>
    Promise.reject(new Error("admin-secret-sentinel pairing-secret-sentinel"));
  assertEquals(await executeConn(identity, f.ctx, f.gateway, fail, f.safe()), {
    error_code: "conn.response_invalid",
  });
  assertEquals(await executeConn(identity, f.ctx, f.gateway, f.t3, f.safe()), {
    error_code: "conn.pairing_outcome_unknown",
  });
  assertEquals(f.effects.length, 0);
});
Deno.test("Conn stored credential recovers a lost projection without issuing again", async () => {
  const f = fixture();
  const first = await executeConn(identity, f.ctx, f.gateway, f.t3, f.safe());
  assertEquals(
    await executeConn(identity, f.ctx, f.gateway, f.t3, f.safe()),
    first,
  );
  assertEquals(f.effects.length, 1);
});
Deno.test("Conn transport preserves a registered URL prefix and refuses redirects", async () => {
  const f = fixture();
  f.input.environment.url = "http://t3.test/server/prefix/";
  const previous = globalThis.fetch;
  const seen: { url: string; redirect: RequestRedirect | undefined }[] = [];
  globalThis.fetch = (url, init) => {
    seen.push({ url: String(url), redirect: init?.redirect });
    return Promise.resolve(Response.json({ revoked: true }));
  };
  try {
    assertEquals(
      await requestT3("POST", "/api/auth/pairing-token", f.input, {
        sessionId: "owned",
      }),
      { revoked: true },
    );
    assertEquals(seen, [{
      url: "http://t3.test/server/prefix/api/auth/pairing-token",
      redirect: "error",
    }]);
  } finally {
    globalThis.fetch = previous;
  }
});

Deno.test("Conn rejects a pairing secret echoed in public link metadata", async () => {
  const f = fixture();
  assertEquals(
    await executeConn(identity, f.ctx, f.gateway, () =>
      Promise.resolve({
        id: "pairing-secret-sentinel",
        credential: "pairing-secret-sentinel",
        expiresAt: "2026-10-30T23:59:00.000Z",
      }), f.safe()),
    { error_code: "conn.response_invalid" },
  );
  assertFalse(f.input.issued);
});
