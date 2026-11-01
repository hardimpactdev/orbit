import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { destroyRoot } from "./destroy_root.ts";
import {
  DestroyDatabaseSelection,
  destroyDatabaseWork,
} from "./destroy_database.ts";
import { fixture } from "./fixtures/destroy.ts";
const args = {
  operation_id: 7,
  node_id: 9,
  kind: "instance.destroy" as const,
  run_id: "0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6",
};
export function setup() {
  const local = fixture();
  const node = {
    node_id: 9,
    user: "orbit",
    agent_sha256: "d".repeat(64),
    certificate_scopes: ["app-instance-1"],
    lan_route_ids: [],
  };
  const operation = {
    id: 7,
    kind: args.kind,
    node_id: 9,
    status: "queued",
    cancel_requested: false,
    input: {
      instance_id: 1,
      participant_node_ids: [9, 17, 1],
      removal_id: args.run_id,
      direct_destroy: {
        ...local,
        routes: [{
          schema: "route.withdrawal.v1",
          instance_id: 1,
          route_ids: [],
          nodes: [{ ...node, caddy: {} }],
          dns: [],
        }],
      },
    },
  };
  const calls: { method: string; path: string; body?: unknown }[] = [];
  const resources: Record<string, Record<string, unknown>> = {};
  const effects: unknown[] = [];
  const http: Http = (method, path, body) => {
    calls.push({ method, path, body });
    if (method === "POST") {
      operation.status = String((body as Record<string, unknown>).status);
    }
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  const ctx = {
    extensionFile: (path:string) => path,
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
    writeResource: (
      spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      resources[spec] = data;
      return Promise.resolve({ name });
    },
  };
  const behavior = destroyRoot(
    () => http,
    (stage) => {
      effects.push(stage);
      return Promise.resolve({});
    },
    undefined,
    undefined,
    (work, nodeId, database) => {
      effects.push({ work, nodeId, database });
      return Promise.resolve({ backend: "absent" as const });
    },
    {
      certificates: () => {
        effects.push("certificates");
        return Promise.resolve({ ready: true });
      },
      lan: () => {
        effects.push("lan");
        return Promise.resolve({ ready: true });
      },
    },
    () => Promise.resolve({ user: "orbit", home: "/home/orbit" }),
  );
  return {
    local,
    node,
    operation,
    calls,
    resources,
    effects,
    http,
    ctx,
    behavior,
  };
}
Deno.test("root admission exports one public placed plan without step receipts", async () => {
  const f = setup();
  assertEquals(await f.behavior.methods.admit.execute(args, f.ctx), {
    dataHandles: [{ name: "plan" }],
  });
  assertEquals(f.resources.plan, {
    nodes: [f.node],
    dns_node_ids: [],
    work: destroyDatabaseWork(f.local.database),
  });
  assertEquals(f.effects, []);
  assertEquals(
    f.calls.some((row) => /children|executor|receipt/.test(row.path)),
    false,
  );
});
Deno.test("root refuses unadmitted database participants before exporting work", async () => {
  const f = setup();
  f.operation.input.participant_node_ids = [];
  await assertRejects(() => f.behavior.methods.admit.execute(args, f.ctx));
  assertEquals(f.resources, {});
  assertEquals(f.calls.length, 1);
});
Deno.test("finish projects only bounded success and relies on native dependencies", async () => {
  const f = setup();
  assertEquals(await f.behavior.methods.finish.execute(args, f.ctx), {
    removed: true,
  });
  assertEquals(f.calls.map((r) => r.method), ["POST"]);
  const data = f.calls[0].body as Record<string, unknown>;
  assertEquals(data.status, "succeeded");
  assertEquals(data.result, { removed: true });
  assertEquals(f.effects, []);
});
Deno.test("foreign cancelled and terminal roots never export participant work", async () => {
  for (const change of ["node_id", "cancel_requested", "status"]) {
    const f = setup();
    if (change === "node_id") f.operation.node_id = 209;
    if (change === "cancel_requested") f.operation.cancel_requested = true;
    if (change === "status") f.operation.status = "failed";
    await assertRejects(() => f.behavior.methods.admit.execute(args, f.ctx));
    assertEquals(f.resources, {});
    assertEquals(f.calls.length, 1);
  }
});
Deno.test("native failure callback records the failed phase", async () => {
  const f = setup();
  await f.behavior.methods.fail.execute(
    { ...args, failed_step: "databases" },
    f.ctx,
  );
  assertEquals(f.calls.map((r) => r.method), ["GET", "POST"]);
  assertEquals((f.calls[1].body as Record<string, unknown>).result, {
    error_code: "instance.destroy_failed",
    failed_step: "databases",
  });
});
Deno.test("database phase uses one parent binding and exact placed aggregate", async () => {
  const f = setup(), work = destroyDatabaseWork(f.local.database)[0];
  await f.behavior.methods.database.execute({
    ...args,
    target_node_id: 9,
    work,
  }, f.ctx);
  assertEquals(f.effects, [{
    work,
    nodeId: 9,
    database: DestroyDatabaseSelection.parse(f.local.database),
  }]);
  assertEquals(f.calls.map((r) => r.method), ["GET"]);
});
Deno.test("leaf and LAN cleanup consume the merged admitted inventory only", async () => {
  const f = setup();
  await f.behavior.methods.cleanupNode.execute(
    { ...args, node: f.node },
    f.ctx,
  );
  assertEquals(f.effects, ["certificates", "lan"]);
  await assertRejects(() =>
    f.behavior.methods.cleanupNode.execute({
      ...args,
      node: { ...f.node, certificate_scopes: ["app-instance-999"] },
    }, f.ctx)
  );
  assertEquals(f.effects, ["certificates", "lan"]);
});

Deno.test("cancellation at a native phase records cancellation without record deletion", async () => {
  const f = setup();
  f.operation.cancel_requested = true;
  await f.behavior.methods.fail.execute(
    { ...args, failed_step: "runtime" },
    f.ctx,
  );
  const data = f.calls.at(-1)!.body as Record<string, unknown>;
  assertEquals(data.status, "cancelled");
  assertEquals(data.result, {});
  assertEquals(data.error_code, null);
  assertEquals(f.effects, []);
});
Deno.test("failed public rendering cannot prevent terminal failure reporting", async () => {
  const posts: unknown[] = [];
  const behavior = destroyRoot(() => (method, _path, body) => {
    if (method === "GET") throw new Error("render unavailable");
    posts.push(body);
    return Promise.resolve({ status: 200, body: { data: {} } });
  });
  await behavior.methods.fail.execute({
    ...args,
    failed_step: "capture_sources",
  }, setup().ctx);
  assertEquals(posts.length, 1);
  assertEquals((posts[0] as Record<string, unknown>).status, "failed");
});

Deno.test("default cleanup account lookup returns an actual home without the source helper's narrower profile", async () => {
  const reply = await new Deno.Command("/usr/bin/id", {
    args: ["-un"],
    stdout: "piped",
  }).output();
  const user = new TextDecoder().decode(reply.stdout).trim();
  const f = setup();
  f.node.user = user;
  f.operation.input.direct_destroy.routes[0].nodes[0].user = user;
  const homes: string[] = [];
  const behavior = destroyRoot(
    () => f.http,
    undefined,
    undefined,
    undefined,
    undefined,
    {
      certificates: (raw) => {
        homes.push((raw as { home: string }).home);
        return Promise.resolve({ ready: true });
      },
      lan: () => Promise.resolve({ ready: true }),
    },
  );
  await behavior.methods.cleanupNode.execute({ ...args, node: f.node }, f.ctx);
  assertEquals(homes, [Deno.env.get("HOME")]);
});
