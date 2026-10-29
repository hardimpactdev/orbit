import {
  assertEquals,
  assertMatch,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { integrations } from "./mod.ts";
import { maintainConn } from "./conn.ts";

/** The fixture returns only Gateway-authorized targets; the real workflow executes each effect. */
class ConnFixture {
  operation = Operation.parse({
    id: 12,
    node_id: 2,
    kind: "conn.pairing_revoke",
    status: "running",
    cancel_requested: false,
    input: { environment: { $secret: "conn.environment", id: 8 } },
    step_results: {},
  });
  sessions = ["phone-old-1", "phone-old-2"];
  links = ["phone-link"];
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  receipts = new Map<string, Record<string, unknown>>();
  revoked: string[] = [];
  loseRun: string | null = null;
  loseCompletion = false;
  cancelAfter: string | null = null;
  fail: string | null = null;
  result: Record<string, unknown> | null = null;
  error: string | null = null;
  http: Http = async (_method, path, raw) => {
    await Promise.resolve();
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body: structuredClone(body) });
    let data: unknown = this.current();
    if (path.startsWith("/api/v1/operations/")) {
      data = { ...this.current(), result: this.result, error_code: this.error };
    }
    if (path.endsWith("/run")) {
      const key = String(body.key);
      const input = body.input as { action: string; id: string };
      if (!this.receipts.has(key)) {
        const result = this.fail === key
          ? { error_code: "conn.request_refused" }
          : input.action === "inspect"
          ? { backend: "inspected", sessions: this.sessions, links: this.links }
          : input.action === "receipt"
          ? { backend: "revoked", revoked_sessions: this.sessions.length }
          : { backend: "revoked", revoked: input.action === "session" };
        this.receipts.set(key, result);
        if (
          (input.action === "session" || input.action === "link") &&
          this.fail !== key
        ) this.revoked.push(input.id);
      }
      if (this.cancelAfter === key) this.operation.cancel_requested = true;
      if (this.loseRun === key) {
        this.loseRun = null;
        throw new Error("Lost Conn reply");
      }
      data = this.receipts.get(key);
    }
    if (path.endsWith("/step")) {
      const name = String(body.name);
      const existing = this.operation.steps.find((step) => step.name === name);
      if (existing) existing.status = String(body.status);
      else this.operation.steps.push({ name, status: String(body.status) });
      if (body.status === "succeeded") {
        this.operation.step_results[name] = body.result;
      }
    }
    if (path.endsWith("/complete")) {
      this.operation.status = String(body.status);
      this.result = body.result as Record<string, unknown>;
      this.error = body.error_code as string | null;
      data = this.current();
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost Conn completion");
      }
    }
    return { status: 200, body: { data } };
  };
  current() {
    const descriptor = (key: string, action: string, id = "") => ({
      key,
      step: key,
      transport: "gateway",
      name: "conn.revocation",
      input: { environment: this.operation.input.environment, action, id },
      resumable: true,
      required: true,
    });
    const descriptors = [descriptor("revocation-inspect", "inspect")];
    if (this.operation.step_results["revocation-inspect"]) {
      descriptors.push(
        ...this.sessions.map((id, index) =>
          descriptor(`session-${index}`, "session", id)
        ),
        ...this.links.map((id, index) =>
          descriptor(`link-${index}`, "link", id)
        ),
      );
      if (descriptors.every((item) => this.operation.step_results[item.step])) {
        descriptors.push(descriptor(this.finalStep(), "receipt"));
      }
    }
    this.operation.execution_context = { descriptors };
    return this.operation;
  }
  finalStep() {
    return this.operation.kind === "conn.pairing_revoke"
      ? "pairing-revoke"
      : "gateway-sessions-revoke";
  }
  async execute() {
    this.current();
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const ctx = Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: (
        _type: string,
        name: string,
        data: Record<string, unknown>,
      ) => {
        this.resources.set(name, structuredClone(data));
        return Promise.resolve({ name });
      },
    }) as Context;
    return await integrations(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker: "conn-proof",
    }, ctx);
  }
}

Deno.test("Conn revokes each admitted device session and link once, without touching other or current clients", async () => {
  const f = new ConnFixture();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.revoked, ["phone-old-1", "phone-old-2", "phone-link"]);
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).map((call) =>
      call.body.key
    ),
    [
      "revocation-inspect",
      "session-0",
      "session-1",
      "link-0",
      "pairing-revoke",
    ],
  );
  assertEquals(f.result, { backend: "revoked", revoked_sessions: 2 });
});

for (const key of ["revocation-inspect", "session-0", "link-0"]) {
  Deno.test(`Conn restart after lost ${key} reply reuses the exact effect key`, async () => {
    const f = new ConnFixture();
    f.loseRun = key;
    await assertRejects(() => f.execute(), Error, "Lost Conn reply");
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.revoked, ["phone-old-1", "phone-old-2", "phone-link"]);
    assertEquals(f.receipts.size, 5);
  });
}

Deno.test("Conn lost final completion and workflow re-entry cannot issue another revoke", async () => {
  const f = new ConnFixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost Conn completion");
  const count = f.calls.filter((call) => call.path.endsWith("/run")).length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((call) => call.path.endsWith("/run")).length,
    count,
  );
});

Deno.test("Conn refuses success after a failed effect and stops later revocations", async () => {
  const f = new ConnFixture();
  f.fail = "session-0";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.error, "conn.request_refused");
  assertEquals(f.revoked, []);
  assertEquals(
    f.calls.some((call) => call.body.key === "pairing-revoke"),
    false,
  );
});

Deno.test("Conn cancellation stops before the next revoke and never reports a cleanup receipt", async () => {
  const f = new ConnFixture();
  f.cancelAfter = "session-0";
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.revoked, ["phone-old-1"]);
  assertEquals(f.result, {});
});

Deno.test("Conn registration cleanup handles repeated intermediate sessions and empty snapshots", async () => {
  for (const sessions of [["first-old", "intermediate"], []]) {
    const f = new ConnFixture();
    f.operation.kind = "conn.gateway_sessions_revoke";
    f.sessions = sessions;
    f.links = [];
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.revoked, sessions);
    assertEquals(f.result, {
      backend: "revoked",
      revoked_sessions: sessions.length,
    });
    assertEquals(
      f.calls.some((call) => call.body.key === "pairing-revoke"),
      false,
    );
  }
});

Deno.test("Conn maintenance admits pending registered environments, keeps active cleanup, and uses a stable bounded key", async () => {
  const keys: string[] = [];
  const paths: string[] = [];
  const http: Http = async (method, path, _body, key) => {
    await Promise.resolve();
    paths.push(path);
    if (method === "GET") {
      return {
        status: 200,
        body: {
          data: [
            {
              environment_id: "e".repeat(255),
              status: "registered",
              cleanup_pending: true,
            },
            {
              environment_id: "busy",
              status: "registered",
              cleanup_pending: true,
            },
            {
              environment_id: "expired",
              status: "session_expired",
              cleanup_pending: true,
            },
            {
              environment_id: "clean",
              status: "registered",
              cleanup_pending: false,
            },
          ],
        },
      };
    }
    keys.push(key!);
    return path.includes("/busy/")
      ? { status: 409, body: { error: { code: "operation.target_busy" } } }
      : {
        status: 202,
        body: { data: { id: 91, kind: "conn.gateway_sessions_revoke" } },
      };
  };
  assertEquals(await maintainConn(http, "tick-1"), { admitted: [91], busy: 1 });
  assertEquals(await maintainConn(http, "tick-1"), { admitted: [91], busy: 1 });
  assertEquals(keys[0], keys[2]);
  assertMatch(keys[0], /^conn\.cleanup:[a-f0-9]{64}$/);
  assertEquals(
    paths.some((path) =>
      path.includes("/expired/") || path.includes("/clean/")
    ),
    false,
  );
});

Deno.test("Conn maintenance observes an already clean revision and refuses admission errors", async () => {
  const list = {
    status: 200,
    body: {
      data: [{
        environment_id: "proof",
        status: "registered",
        cleanup_pending: true,
      }],
    },
  };
  assertEquals(
    await maintainConn(
      (method) =>
        Promise.resolve(method === "GET" ? list : { status: 204, body: null }),
      "tick",
    ),
    { admitted: [], busy: 0 },
  );
  await assertRejects(
    () =>
      maintainConn(
        (method) =>
          Promise.resolve(
            method === "GET" ? list : {
              status: 403,
              body: { error: { code: "node_access.required" } },
            },
          ),
        "tick",
      ),
    Error,
    "conn.cleanup_admission_failed",
  );
  assertEquals(
    await maintainConn(
      () =>
        Promise.resolve({
          status: 403,
          body: { error: { code: "extension.disabled" } },
        }),
      "tick",
    ),
    { admitted: [], busy: 0 },
  );
});
