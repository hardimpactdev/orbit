import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Fixture } from "./test-fixture.ts";

class NetworkFixture extends Fixture {
  childStarted = false;
  childStatus = "queued";
  constructor() {
    super();
    this.operation.kind = "node.network_converge";
    this.operation.input = {
      roleless_operator: false,
      _network: { hub_id: 3 },
    };
    const base = this.http;
    this.replies.set("network-prepare", { prepared: true });
    this.replies.set("network-key", { public_key: "admitted-public-key" });
    this.replies.set("network-publish", { public_key: "admitted-public-key" });
    this.replies.set("network-private-ssh", { architecture: "x86_64" });
    this.replies.set("network-trust", { matches: true });
    this.replies.set("network-result", {
      network_proven: true,
      retained: true,
      public_key: "admitted-public-key",
    });
    this.http = (async (method, path, raw) => {
      const body = (raw ?? {}) as Record<string, unknown>;
      if (path.endsWith("/heartbeat")) {
        const descriptors: {
          key: string;
          step: string;
          name: string;
          transport: string;
          input: Record<string, unknown>;
          required: boolean;
          resumable: boolean;
        }[] = [];
        const add = (key: string, name: string, transport = "agent") => {
          descriptors.push({
            key,
            step: key,
            name,
            transport,
            input: {},
            required: false,
            resumable: transport === "gateway",
          });
          return this.receipts.has(key);
        };
        if (
          add("network-prepare", "node.prepare-network", "gateway") &&
          add("network-key", "wireguard.prepare-peer-key", "bootstrap-ssh") &&
          this.childStarted
        ) {
          if (this.childStatus === "cancelled") {
            add("network-result", "node.network-result", "gateway");
          } else if (
            this.childStatus === "succeeded" &&
            add("network-publish", "wireguard.publish-peer", "bootstrap-ssh") &&
            add("network-private-ssh", "agent.status") &&
            add("network-trust", "firewall.rules")
          ) {
            add("network-result", "node.network-result", "gateway");
          }
        }
        this.operation.execution_context = { descriptors };
      }
      if (path.endsWith("/children")) {
        this.calls.push({ path, body });
        const child = {
          id: 20,
          key: "network-hub",
          kind: "node.wireguard_hub_converge",
          node_id: 3,
          status: this.childStatus,
          error_code: this.childStatus === "cancelled"
            ? "operation.cancelled"
            : null,
          result: null,
          input: {},
          cancel_requested: false,
          step_results: {},
        };
        if (method === "POST") {
          assertEquals(body, { worker: "worker-1", key: "network-hub" });
          this.childStarted = true;
          this.childAdmissions++;
          return { status: 202, body: { data: child } };
        }
        return {
          status: 200,
          body: { data: this.childStarted ? [child] : [] },
        };
      }
      return await base(method, path, raw);
    }) satisfies Http;
  }
}

Deno.test("network parks after key proof and uses only the recorded hub child", async () => {
  const f = new NetworkFixture();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.runs(), [
    "node.prepare-network",
    "wireguard.prepare-peer-key",
  ]);
  f.childStatus = "succeeded";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.childAdmissions, 1);
  assertEquals(f.runs(), [
    "node.prepare-network",
    "wireguard.prepare-peer-key",
    "wireguard.publish-peer",
    "agent.status",
    "firewall.rules",
    "node.network-result",
  ]);
});

Deno.test("network recovers a lost peer publication reply before private verification", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.childStatus = "succeeded";
  f.lostRun = "network-publish";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.runs().filter((name) => name === "wireguard.publish-peer").length,
    1,
  );
  assertEquals(
    f.calls.some((call) => call.path.endsWith("/receipts/network-publish")),
    true,
  );
});

Deno.test("network replays a lost terminal receipt without another peer mutation", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.childStatus = "succeeded";
  f.lostCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const before = f.runs();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), before);
});

Deno.test("network settles a cancelled final run without successful network proof", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.childStatus = "cancelled";
  f.replies.set("network-result", { error_code: "operation.cancelled" });
  const base = f.http;
  f.http = async (method, path, raw) => {
    if (
      path.endsWith("/run") &&
      (raw as Record<string, unknown>)?.key === "network-result"
    ) {
      f.operation.cancel_requested = true;
    }
    return await base(method, path, raw);
  };
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.calls.at(-1)!.body, {
    worker: "worker-1",
    status: "cancelled",
    result: {},
    error_code: null,
  });
  assertEquals(f.runs().includes("wireguard.publish-peer"), false);
});

Deno.test("network cancels after its pending child settles without restarting a failed step", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.operation.cancel_requested = true;
  const runs = f.runs();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.calls.at(-1)!.body, {
    worker: "worker-1",
    keys: ["network-hub"],
  });
  f.childStatus = "cancelled";
  f.receipts.set("network-result", { error_code: "operation.cancelled" });
  f.operation.steps.push({ name: "network-result", status: "failed" });
  const base = f.http;
  f.http = async (method, path, raw) => {
    if (
      path.endsWith("/step") &&
      (raw as Record<string, unknown>)?.name === "network-result"
    ) {
      throw new Error("operation.step_finished");
    }
    return await base(method, path, raw);
  };
  assertEquals((await f.execute()).status, "cancelled");
  assertEquals(f.runs(), runs);
  assertEquals(f.childAdmissions, 1);
  assertEquals(f.calls.at(-1)!.body.result, {});
});

Deno.test("network still requires successful network proof after peer publication", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.childStatus = "succeeded";
  f.replies.set("network-result", { error_code: "operation.cancelled" });
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.completion_unproven",
  );
  assertEquals(f.calls.some((call) => call.path.endsWith("/complete")), false);
});

Deno.test("network reconciles an already started peer publication before settling cancellation", async () => {
  const f = new NetworkFixture();
  await f.execute();
  f.childStatus = "succeeded";
  f.lostRun = "network-publish";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.operation.cancel_requested = true;
  f.operation.steps.push({ name: "network-publish", status: "running" });
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.runs().filter((name) => name === "wireguard.publish-peer").length,
    1,
  );
  assertEquals(
    f.calls.some((call) => call.path.endsWith("/receipts/network-publish")),
    true,
  );
});
