import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, ExecutorError } from "../../operation-runner/mod.ts";
import { fleet } from "./mod.ts";
import { matchesPolicy } from "./contract.ts";

export const input = {
  server: "10.44.0.1",
  dns_server: "10.44.0.1",
  public_key: "A".repeat(43) + "=",
  domains: ["orbit", "test"],
};
export const healthy = {
  state: "orbit\n10.44.0.1\norbit\ntest",
  hooks:
    "PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit \\~test",
  dns: "Link 2 (orbit): 10.44.0.1",
  domains: "Link 2 (orbit): ~orbit ~test",
  default_route: "Link 2 (orbit): no",
  uplink: true,
};
class Fixture {
  operation = {
    id: 7,
    kind: "fleet.peer_dns",
    node_id: 2,
    status: "running",
    result: null as Record<string, unknown> | null,
    error_code: null as string | null,
    input,
    cancel_requested: false,
    steps: [] as { name: string; status: string; error_code?: string | null }[],
    step_results: {} as Record<string, unknown>,
  };
  calls: { path: string; body: Record<string, unknown> }[] = [];
  observed = { ...healthy };
  repaired = 0;
  loseRunReply = false;
  loseCompletion = false;
  loseStepReply = false;
  stale = false;
  idle = false;
  refusal: string | null = null;
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  http: Http = async (_method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    if (this.stale) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = path.endsWith("/claim") && this.idle
      ? null
      : this.operation;
    if (path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/receipts/")[1]);
      data = {
        effect_key: `op-7.${key}`,
        state: this.receipts.has(key) ? "succeeded" : "unknown",
        exit_code: this.receipts.has(key) ? 0 : null,
        result: this.receipts.get(key) ?? null,
        finished_at: null,
      };
    }
    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        if (body.name === "wireguard.repair-peer-dns") {
          this.repaired++;
          this.observed = { ...healthy };
          this.receipts.set(
            key,
            this.refusal ? { error_code: this.refusal } : {},
          );
        } else this.receipts.set(key, { ...this.observed });
      }
      data = this.receipts.get(key);
      if (this.loseRunReply && body.name === "wireguard.repair-peer-dns") {
        this.loseRunReply = false;
        throw new Error("Lost effect reply");
      }
    }
    if (path.endsWith("/step")) {
      const step = {
        name: String(body.name),
        status: String(body.status),
        error_code: body.error_code as string | null,
      };
      this.operation.steps = [step];
      if (body.status === "succeeded") {
        this.operation.step_results[step.name] = body.result;
      }
      if (this.loseStepReply && body.status !== "running") {
        this.loseStepReply = false;
        throw new Error("Lost step reply");
      }
    }
    if (path.endsWith("/complete")) {
      this.operation.status = String(body.status);
      this.operation.result = body.result as Record<string, unknown>;
      this.operation.error_code = body.error_code as string | null;
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion reply");
      }
    }
    return { status: 200, body: { data } };
  };
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
  execute(worker = "run-1") {
    return fleet(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}
Deno.test("matching persisted and live policy skips repair and DNS queries", async () => {
  const f = new Fixture();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.repaired, 0);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/run")).map((c) => c.body.name),
    ["wireguard.inspect-peer-dns"],
  );
});
Deno.test("repairs each policy mismatch and verifies the exact admitted policy", async () => {
  for (
    const [field, value] of Object.entries({
      state: "old",
      hooks: "route-all",
      dns: "Link 2 (orbit): 1.1.1.1",
      domains: "Link 2 (orbit): ~orbit",
      default_route: "Link 2 (orbit): yes",
    })
  ) {
    const f = new Fixture();
    Object.assign(f.observed, { [field]: value });
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.repaired, 1);
    const calls = f.calls.filter((c) => c.path.endsWith("/run"));
    assertEquals(calls.map((c) => c.body.key), [
      "peer-dns-inspect",
      "peer-dns-repair",
      "peer-dns-verify",
    ]);
    assertEquals(calls[1].body.input, {
      dns_server: input.server,
      public_key: input.public_key,
      domains: input.domains,
    });
    assertEquals(calls.some((c) => "node_id" in c.body), false);
  }
});
Deno.test("missing uplink refuses without repair when policy already matches", async () => {
  const f = new Fixture();
  f.observed.uplink = false;
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.repaired, 0);
  assertEquals(f.calls.at(-1)!.body.error_code, "vpn.peer_dns_uplink_missing");
});
Deno.test("repair refusal is a failed receipt and cancellation closes a refused effect", async () => {
  for (
    const code of ["wireguard.peer_recovery_pending", "operation.cancelled"]
  ) {
    const f = new Fixture();
    f.observed.state = "old";
    f.refusal = code;
    assertEquals(
      (await f.execute()).status,
      code === "operation.cancelled" ? "cancelled" : "failed",
    );
    assertEquals(f.calls.at(-1)!.body.error_code, code);
  }
});
Deno.test("restart after admission starts the same permanent Operation", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.calls.some((c) => c.path.endsWith("/run")), false);
});
Deno.test("lost effect reply resumes the same key without a second repair", async () => {
  const f = new Fixture();
  f.observed.state = "old";
  f.loseRunReply = true;
  await assertRejects(() => f.execute(), Error, "Lost effect reply");
  assertEquals((await f.execute("run-2")).status, "succeeded");
  assertEquals(f.repaired, 1);
  assertEquals(
    f.calls.filter((c) => c.body?.name === "wireguard.repair-peer-dns").map((
      c,
    ) => c.body.key),
    ["peer-dns-repair"],
  );
});
Deno.test("lost completion reply replays saved intent without another Node command", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  await f.execute();
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.calls.at(-1)!.path === "/api/v1/operations/7", true);
});
Deno.test("restart after success or refusal step does not run the effect again", async () => {
  for (const failed of [false, true]) {
    const f = new Fixture();
    f.loseStepReply = true;
    f.observed.uplink = !failed;
    await assertRejects(() => f.execute(), Error, "Lost step reply");
    const runs = f.calls.filter((c) => c.path.endsWith("/run")).length;
    assertEquals((await f.execute()).status, failed ? "failed" : "succeeded");
    assertEquals(f.calls.filter((c) => c.path.endsWith("/run")).length, runs);
  }
});
Deno.test("expired lease stops the stale worker without any Node work", async () => {
  const f = new Fixture();
  f.stale = true;
  await assertRejects(() => f.execute(), ExecutorError, "operation.lease_lost");
  assertEquals(f.calls.length, 1);
});
Deno.test("policy comparison accepts domain order and refuses route-all", () => {
  assertEquals(
    matchesPolicy(input, {
      ...healthy,
      domains: "Link 2 (orbit): ~test ~orbit",
    }),
    true,
  );
  assertEquals(
    matchesPolicy(input, { ...healthy, domains: "Link 2 (orbit): ~." }),
    false,
  );
});

Deno.test("an empty family claim returns without recording intent or running effects", async () => {
  const f = new Fixture();
  f.idle = true;
  assertEquals((await f.execute()).status, "idle");
  assertEquals(f.calls.length, 1);
  assertEquals(f.calls[0].path, "/api/v1/operation-executor/claim");
  assertEquals(f.resources.size, 0);
});
Deno.test("a dispatch retry remains bound to its first assignment and checks terminal proof", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  f.operation.result = { uplink: false };
  await assertRejects(
    () => f.execute(),
    Error,
    "Unproven saved fleet completion",
  );
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  assertEquals(f.repaired, 0);
});
Deno.test("a retry cannot execute a different Operation returned by heartbeat", async () => {
  const f = new Fixture();
  f.observed.state = "old";
  f.loseRunReply = true;
  await assertRejects(() => f.execute(), Error, "Lost effect reply");
  f.operation.id = 8;
  await assertRejects(() => f.execute(), Error, "Wrong fleet assignment");
  assertEquals(f.calls.filter((c) => c.path.endsWith("/claim")).length, 1);
  assertEquals(f.repaired, 1);
});
