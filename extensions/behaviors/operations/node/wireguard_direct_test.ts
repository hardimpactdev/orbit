import { fakeNetworkSystem } from "./network_test_support.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nodeWireGuard } from "./wireguard_direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const args = {
  operation_id: 7,
  kind: "node.wireguard_hub_converge",
  node_id: 1,
  run_id: "ff3b7992-7376-4ac7-a49f-ed45bedc8de5",
};
const snapshot = {
  schema_version: 1 as const,
  executor_node_id: 1,
  hub_id: 1,
  context_node_id: 13,
  excluded_node_id: null,
  agent_sha256: "a".repeat(64),
};
const privateKey = "A".repeat(43) + "=";
const material = {
  snapshot,
  address: "10.44.0.1/24",
  port: 51820,
  private_key: privateKey,
  peers: [],
};
function fixture(cancel_requested = false) {
  const posts: unknown[] = [];
  const resources: unknown[] = [];
  const http: Http = (method, path, body) => {
    if (method === "POST") posts.push(body);
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? { configuration: material }
          : {
            id: 7,
            node_id: 1,
            kind: args.kind,
            status: "queued",
            cancel_requested,
            input: snapshot,
          },
      },
    });
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
    writeResource: (_s: string, _n: string, value: unknown) => {
      resources.push(value);
      return Promise.resolve({});
    },
  };
  return { posts, resources, http, ctx };
}
Deno.test("hub root cancellation settles before private read or effect", async () => {
  const f = fixture(true);
  let calls = 0;
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem(() => {
      calls++;
      return Promise.resolve({});
    }),
  );
  const admission = await behavior.methods.admit.execute(args, f.ctx);
  assertEquals(admission.ready, false);
  assertEquals(calls, 0);
  assertEquals((f.posts[0] as { status: string }).status, "cancelled");
});
Deno.test("hub root refuses wrong root placement before projecting", async () => {
  const f = fixture();
  const behavior = nodeWireGuard(
    undefined,
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 7,
            node_id: 14,
            kind: args.kind,
            status: "queued",
            cancel_requested: false,
            input: snapshot,
          },
        },
      }),
  );
  await assertRejects(
    () => behavior.methods.admit.execute({ ...args, node_id: 14 }, f.ctx),
    Error,
    "admitted Node",
  );
  assertEquals(f.posts.length, 0);
});
Deno.test("hub publication keeps existing private material callback local", async () => {
  const f = fixture();
  let effects = 0;
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem((call) => {
      effects++;
      assertEquals(call.name, "wireguard.project-hub");
      assertEquals(
        call.input.config,
        `[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = ${privateKey}\n`,
      );
      return Promise.resolve({});
    }),
  );
  const admission = await behavior.methods.admit.execute(args, f.ctx);
  const outcome = await behavior.methods.publish.execute(
    { ...args, snapshot },
    f.ctx,
  );
  await behavior.methods.project.execute(
    { ...args, admission, outcome },
    f.ctx,
  );
  assertEquals(effects, 1);
  assertEquals(outcome.status, "succeeded");
  assertEquals(JSON.stringify(f.resources).includes(privateKey), false);
  assertEquals(JSON.stringify(f.posts).includes(privateKey), false);
});
Deno.test("hub private transport failures remain sanitized", async () => {
  const f = fixture();
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem(() => Promise.reject(new Error(privateKey))),
  );
  const error = await assertRejects(
    () => behavior.methods.publish.execute({ ...args, snapshot }, f.ctx),
    Error,
    "Private material callback failed",
  );
  assertEquals(error.message.includes(privateKey), false);
  assertEquals(f.resources.length, 0);
});
