import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { agentReply, type NodeCall } from "../_lib/node-agent.ts";
import { peerDns } from "./peer_dns.ts";

const args = {
  operation_id: 7,
  node_id: 2,
  kind: "fleet.peer_dns" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const input = {
  node_id: 2,
  agent_sha256: "a".repeat(64),
  server: "10.44.0.1",
  dns_server: "10.44.0.1",
  public_key: "A".repeat(43) + "=",
  domains: ["orbit", "test"],
};
const healthy = {
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
    node_id: 2,
    kind: args.kind,
    status: "queued",
    input: { ...input },
    result: null as unknown,
    cancel_requested: false,
  };
  observed = { ...healthy };
  calls: NodeCall[] = [];
  projections: Record<string, unknown>[] = [];
  controller = new AbortController();
  reads = 0;
  deny = false;
  cancelAtRead = 0;
  loseFinal = false;
  repairReply: unknown = {};
  inspectReply: unknown;
  afterRepair = () => {};
  beforeInspect = () => {};
  http: Http = async (method, _path, raw) => {
    await Promise.resolve();
    if (this.deny) {
      return {
        status: 403,
        body: { error: { code: "operation.run_mismatch" } },
      };
    }
    if (method === "GET") {
      if (++this.reads === this.cancelAtRead) {
        this.operation.cancel_requested = true;
      }
      return { status: 200, body: { data: this.operation } };
    }
    const body = raw as Record<string, unknown>;
    this.projections.push(body);
    if (this.loseFinal && body.status === "succeeded") {
      this.loseFinal = false;
      throw new Error("Lost projection reply");
    }
    this.operation.status = String(body.status);
    this.operation.result = body.result ?? null;
    return { status: 200, body: { data: this.operation } };
  };
  invoke = (call: NodeCall): Promise<unknown> => {
    this.calls.push(call);
    if (!call.mutates) {
      this.beforeInspect();
      return Promise.resolve(this.inspectReply ?? { ...this.observed });
    }
    this.observed = { ...healthy };
    this.afterRepair();
    return Promise.resolve(this.repairReply);
  };
  run() {
    return peerDns(() => this.http, this.invoke).methods.reconcile.execute(
      args,
      {
        globalArgs: {
          gatewayUrl: "https://gateway.orbit",
          caFile: "/tmp/fixture-ca",
        },
        signal: this.controller.signal,
      },
    );
  }
}

Deno.test("matching peer DNS uses one pinned readonly local inspection and no repair", async () => {
  const f = new Fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.calls, [{
    name: "wireguard.inspect-peer-dns",
    input: { server: input.server },
    pin: input.agent_sha256,
    sudo: true,
    mutates: false,
    deadlineMs: 10000,
  }]);
  assertEquals(f.projections.at(-1)?.result, healthy);
});

Deno.test("each persisted or live mismatch repairs once then verifies admitted intent", async () => {
  for (
    const field of [
      "state",
      "hooks",
      "dns",
      "domains",
      "default_route",
    ] as const
  ) {
    const f = new Fixture();
    f.observed[field] = "drift";
    assertEquals(await f.run(), { status: "succeeded" });
    assertEquals(f.calls.map((call) => call.name), [
      "wireguard.inspect-peer-dns",
      "wireguard.repair-peer-dns",
      "wireguard.inspect-peer-dns",
    ]);
    assertEquals(f.calls[1].input, {
      dns_server: input.server,
      public_key: input.public_key,
      domains: input.domains,
    });
    assertEquals(f.calls[1].sudo, true);
    assertEquals(f.projections.at(-1)?.result, healthy);
  }
});

Deno.test("missing public uplink is a bounded failure without repeating a matching policy", async () => {
  const f = new Fixture();
  f.observed.uplink = false;
  await assertRejects(() => f.run(), Error, "vpn.peer_dns_uplink_missing");
  assertEquals(f.calls.length, 1);
  assertEquals(f.projections.at(-1)?.result, {
    error_code: "vpn.peer_dns_uplink_missing",
  });
});

Deno.test("a known repair refusal is failed and no verification action follows", async () => {
  const f = new Fixture();
  f.observed.state = "drift";
  f.repairReply = { error_code: "vpn.peer_dns_uplink_missing" };
  await assertRejects(() => f.run(), Error, "vpn.peer_dns_uplink_missing");
  assertEquals(f.calls.length, 2);
  assertEquals(f.projections.at(-1)?.status, "failed");
});

Deno.test("actual Rust refusal envelopes project safe VPN codes without diagnostics", async () => {
  for (
    const [stage, code] of [
      ["inspect", "wireguard.peer_dns_unreadable"],
      ["repair", "wireguard.peer_dns_repair_failed"],
      ["verify", "wireguard.peer_dns_unreadable"],
    ]
  ) {
    const f = new Fixture();
    const reply = agentReply(
      new TextEncoder().encode(JSON.stringify({
        ok: false,
        error: {
          code,
          message: "private native diagnostic",
          details: { diagnostic: "private" },
        },
      })),
      false,
    );
    if (stage === "inspect") f.inspectReply = reply;
    else {
      f.observed.state = "drift";
      if (stage === "repair") f.repairReply = reply;
      else f.afterRepair = () => f.inspectReply = reply;
    }
    const safe = code.replace("wireguard.", "vpn.");
    await assertRejects(() => f.run(), Error, safe);
    assertEquals(f.operation.status, "failed");
    assertEquals(f.operation.result, { error_code: safe });
    assertEquals(f.projections.at(-1)?.error_code, safe);
    assertEquals(
      f.calls.length,
      stage === "inspect" ? 1 : stage === "repair" ? 2 : 3,
    );
    assertEquals(JSON.stringify(f.projections).includes("private"), false);
  }
});

Deno.test("unrecognized typed mutation refusals remain uncertain", async () => {
  const f = new Fixture();
  f.observed.state = "drift";
  f.repairReply = agentReply(
    new TextEncoder().encode(JSON.stringify({
      ok: false,
      error: { code: "wireguard.unrecognized", message: "private diagnostic" },
    })),
    false,
  );
  await assertRejects(() => f.run());
  assertEquals(f.projections.map((body) => body.status), ["running"]);
});

Deno.test("an unknown mutation response remains uncertain rather than successful or cancelled", async () => {
  const f = new Fixture();
  f.observed.state = "drift";
  f.repairReply = { unrecognized: true };
  await assertRejects(() => f.run());
  assertEquals(f.projections.map((body) => body.status), ["running"]);
});

Deno.test("lost final projection recovers by inspection without another repair", async () => {
  const f = new Fixture();
  f.observed.state = "drift";
  f.loseFinal = true;
  await assertRejects(() => f.run(), Error, "Lost projection reply");
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.calls.filter((call) => call.mutates).length, 1);
});

Deno.test("cancel reread before repair or readonly completion projects no-effect cancellation", async () => {
  for (const drift of [false, true]) {
    const f = new Fixture();
    if (drift) f.observed.state = "drift";
    f.cancelAtRead = 2;
    assertEquals(await f.run(), { status: "cancelled" });
    assertEquals(f.calls.filter((call) => call.mutates).length, 0);
    assertEquals(f.projections.at(-1)?.result, {});
  }
});

Deno.test("native abort during readonly inspection can confirm no-effect cancellation", async () => {
  const f = new Fixture();
  f.beforeInspect = () => {
    f.controller.abort();
    throw new DOMException("Aborted", "AbortError");
  };
  assertEquals(await f.run(), { status: "cancelled" });
  assertEquals(f.projections.at(-1)?.result, {});
});

Deno.test("late abort after successful repair still verifies and projects the real success", async () => {
  const f = new Fixture();
  f.observed.state = "drift";
  f.afterRepair = () => f.controller.abort();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.calls.length, 3);
});

Deno.test("foreign binding or target causes no local action", async () => {
  for (const fault of ["binding", "target"]) {
    const f = new Fixture();
    if (fault === "binding") f.deny = true;
    else f.operation.input.node_id = 3;
    await assertRejects(() => f.run());
    assertEquals(f.calls.length, 0);
  }
});

Deno.test("an already terminal or cancelled intent performs no local action", async () => {
  for (const state of ["succeeded", "cancelled", "cancel_requested"]) {
    const f = new Fixture();
    if (state === "cancel_requested") f.operation.cancel_requested = true;
    else f.operation.status = state;
    f.operation.result = state === "succeeded" ? healthy : {};
    await f.run();
    assertEquals(f.calls.length, 0);
  }
});
