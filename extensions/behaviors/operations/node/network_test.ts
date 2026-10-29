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
          this.childStatus === "succeeded" && this.childStarted
        ) {
          if (
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
