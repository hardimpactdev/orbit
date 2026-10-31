import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { clusterRouter } from "./cluster-router.ts";
import { Globals } from "../_lib/direct.ts";

const args = {
  operation_id: 17,
  kind: "cluster.router_set",
  node_id: 9,
  run_id: "b1cce664-5447-4dbf-b1c8-33a935ba771c",
  target_node_id: 18,
  node: {
    node_id: 18,
    user: "router_user",
    agent_sha256: "a".repeat(64),
    prerequisites: { packages: ["git"], apps_directory: false },
    certificate_scopes: ["route-7-router"],
  },
};
const ctx = {
  globalArgs: Globals.parse({}),
  writeResource: () => Promise.resolve({}),
};
const account = {
  user: "router_user",
  group: "staff",
  home: "/srv/router-user",
};

Deno.test("Router prerequisites resolve the admitted account on the effect Node", async () => {
  const calls: unknown[] = [];
  const behavior = clusterRouter(
    undefined,
    (_program, input) => {
      calls.push(input);
      return Promise.resolve({});
    },
    undefined,
    (user) => {
      calls.push(user);
      return Promise.resolve(account);
    },
  );
  assertEquals(await behavior.methods.installPrerequisites.execute(args, ctx), {
    ready: true,
  });
  assertEquals(calls, ["router_user", {
    name: "role.install-prerequisites",
    input: { ...args.node.prerequisites, ...account },
  }]);
});

Deno.test("Router retirement resolves home locally before removing and inspecting old scopes", async () => {
  const calls: unknown[] = [];
  const behavior = clusterRouter(
    undefined,
    (_program, raw) => {
      const call = raw as { name: string; input: Record<string, unknown> };
      calls.push(call);
      return Promise.resolve(
        call.name === "certificate.inspect-leaf" ? { present: false } : {},
      );
    },
    undefined,
    () => Promise.resolve(account),
  );
  assertEquals(await behavior.methods.certificates.execute(args, ctx), {
    ready: true,
  });
  assertEquals(calls, [
    {
      name: "certificate.remove-leaf",
      input: {
        scope: "route-7-router",
        home: account.home,
        user: account.user,
      },
    },
    {
      name: "certificate.inspect-leaf",
      input: {
        scope: "route-7-router",
        home: account.home,
        user: account.user,
      },
    },
  ]);
});

Deno.test("Router refuses missing accounts and supplied-home mismatches before effects", async () => {
  const calls: unknown[] = [];
  for (const resolved of [null, account]) {
    const behavior = clusterRouter(
      undefined,
      (_program, input) => {
        calls.push(input);
        return Promise.resolve({});
      },
      undefined,
      () => Promise.resolve(resolved),
    );
    const input = { ...args, node: { ...args.node, home: "/foreign" } };
    await assertRejects(() =>
      behavior.methods.installPrerequisites.execute(input, ctx)
    );
    await assertRejects(() =>
      behavior.methods.certificates.execute(input, ctx)
    );
  }
  assertEquals(calls, []);
});

Deno.test("Router account lookup refuses a different effect Node before local reads", async () => {
  const calls: string[] = [];
  const behavior = clusterRouter(undefined, undefined, undefined, (user) => {
    calls.push(user);
    return Promise.resolve(account);
  });
  const input = { ...args, target_node_id: 19 };
  await assertRejects(() =>
    behavior.methods.installPrerequisites.execute(input, ctx)
  );
  await assertRejects(() => behavior.methods.certificates.execute(input, ctx));
  assertEquals(calls, []);
});
