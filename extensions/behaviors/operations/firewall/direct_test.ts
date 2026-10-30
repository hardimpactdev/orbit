import { localRule } from "./local.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { directFirewall } from "./direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const args = {
  operation_id: 17,
  node_id: 9,
  kind: "firewall.allow" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/dev/null" },
};
class Fixture {
  operation = {
    id: 17,
    node_id: 9,
    kind: "firewall.allow",
    input: {
      node_id: 9,
      agent_sha256: "a".repeat(64),
      name: "canary",
      action: "allow",
      source: "192.0.2.100",
      protocol: "tcp",
      port: "45678",
    },
    status: "queued",
    result: null as unknown,
    cancel_requested: false,
  };
  calls: string[] = [];
  effects = 0;
  bound = true;
  loseProjection = false;
  loseNode = false;
  backend = "active";
  refusal: string | null = null;
  http: Http = (method, path, raw) =>
    Promise.resolve().then(() => {
      this.calls.push(path);
      if (!this.bound) {
        return {
          status: 409,
          body: { error: { code: "operation.run_mismatch" } },
        };
      }
      if (method !== "GET") {
        const body = raw as Record<string, unknown>;
        this.operation.status = String(body.status);
        this.operation.result = body.result ?? null;
        if (this.loseProjection && body.status === "succeeded") {
          this.loseProjection = false;
          throw new Error("lost projection");
        }
      }
      return { status: 200, body: { data: this.operation } };
    });
  run = (input: Record<string, unknown>) =>
    Promise.resolve().then(() => {
      this.effects++;
      assertEquals(input.node_id, 9);
      if (this.loseNode) {
        this.loseNode = false;
        throw new Error("uncertain Node response");
      }
      return this.refusal
        ? { error_code: this.refusal }
        : { backend: input.operation === "remove" ? "absent" : this.backend };
    });
  execute(signal?: AbortSignal) {
    return directFirewall(() => this.http, this.run).methods.apply.execute({
      ...args,
      kind: this.operation.kind as typeof args.kind,
    }, { ...ctx, signal });
  }
}
Deno.test("shared direct protocol executes each admitted Firewall kind without executor or primitive APIs", async () => {
  for (const kind of ["firewall.allow", "firewall.deny", "firewall.remove"]) {
    const f = new Fixture();
    f.operation.kind = kind;
    f.operation.input.action = kind === "firewall.deny" ? "deny" : "allow";
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.effects, 1);
    assertEquals(f.calls.length, 3);
    assertEquals(
      f.calls.every((p) => p.startsWith("/api/v1/direct-operations/17")),
      true,
    );
  }
});
Deno.test("unbound or mismatched first reads refuse before any local action", async () => {
  const f = new Fixture();
  f.bound = false;
  await assertRejects(() => f.execute());
  assertEquals(f.effects, 0);
  f.bound = true;
  f.operation.node_id = 10;
  await assertRejects(() => f.execute());
  assertEquals(f.effects, 0);
});
Deno.test("terminal and cancelled intent has no local effect", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.effects, 0);
  const terminal = new Fixture();
  terminal.operation.status = "succeeded";
  terminal.operation.result = { backend: "active" };
  assertEquals((await terminal.execute()).status, "succeeded");
  assertEquals(terminal.effects, 0);
});
Deno.test("lost terminal projection reads completion without repeating the action", async () => {
  const f = new Fixture();
  f.loseProjection = true;
  await assertRejects(() => f.execute());
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects, 1);
});
Deno.test("uncertain action escapes to native recovery and convergent repetition", async () => {
  const f = new Fixture();
  f.loseNode = true;
  await assertRejects(() => f.execute());
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.effects, 2);
});
Deno.test("bounded refusal and inactive backend fail the native run after projection", async () => {
  for (const code of ["firewall.apply_failed", "firewall.backend_inactive"]) {
    const f = new Fixture();
    if (code.endsWith("inactive")) f.backend = "inactive";
    else f.refusal = code;
    await assertRejects(() => f.execute(), Error, code);
    assertEquals(f.operation.status, "failed");
  }
});

Deno.test("production adapter refuses without the admitted agent digest", async () => {
  await assertRejects(
    () => localRule({}),
    Error,
    "admitted agent digest",
  );
});
