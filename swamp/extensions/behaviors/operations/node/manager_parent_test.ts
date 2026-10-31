import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parentManagerAction } from "./manager_parent.ts";
import { toolAgent } from "../tools/local.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const parent = {
  operation_id: 91,
  node_id: 9,
  kind: "node.add",
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  agent_sha256: "a".repeat(64),
};
const input = (manager = "composer") => ({
  parent,
  manager: {
    manager,
    platform: "linux",
    architecture: "x86_64",
    user: "orbit",
    materialize: manager === "apt" ? null : { user: "orbit" },
  },
});
function fixture(reply?: Record<string, unknown>) {
  const calls: {
    name: string;
    input: Record<string, unknown>;
    node: unknown;
  }[] = [];
  const resources = new Map<string, unknown>();
  const writes: { spec: string; name: string }[] = [];
  return {
    calls,
    resources,
    writes,
    action: parentManagerAction((name, value, node) => {
      calls.push({ name, input: value, node });
      return Promise.resolve(
        reply ?? (name.endsWith("-version") ? { version: "3.0.0" } : {}),
      );
    }),
    context: {
      readResource: (name: string) =>
        Promise.resolve(resources.get(name) ?? null),
      writeResource: (spec: string, name: string, value: unknown) => {
        writes.push({ spec, name });
        resources.set(name, structuredClone(value));
        return Promise.resolve({ name });
      },
    },
  };
}

Deno.test("parent manager action delegates to canonical policy without another Operation", async () => {
  const f = fixture();
  const result = await f.action(input(), f.context);
  assertEquals(f.calls.map((c) => c.name), [
    "tool.materialize-composer",
    "tool.composer-version",
    "tool.composer-version",
  ]);
  assertEquals(result.outcome, {
    schema_version: 1,
    node_id: 9,
    manager: "composer",
    status: "succeeded",
    result: { manager_version: "3.0.0" },
    error_code: null,
  });
  const node = f.calls[0].node as Record<string, unknown>;
  assertEquals(node.node_id, 9);
  assertEquals(node.agent_sha256, parent.agent_sha256);
  assertEquals(node.package, "");
  assertEquals(node.tool_id, null);
  assertEquals(result.dataHandles.length, 1);
});

Deno.test("two managers in one parent run retain separate native outcomes", async () => {
  const f = fixture();
  await f.action(input("composer"), f.context);
  await f.action(input("apt"), f.context);
  assertEquals(f.resources.size, 2);
  assertEquals(f.writes.every((w) => w.spec === "manager_outcome"), true);
  assertEquals(new Set(f.writes.map((w) => w.name)).size, 2);
});

Deno.test("image-provided manager skips materialization but still proves its version", async () => {
  const f = fixture();
  const args = input("vp");
  args.manager.materialize = null;
  assertEquals((await f.action(args, f.context)).outcome.status, "succeeded");
  assertEquals(f.calls.map((c) => c.name), [
    "tool.vp-version",
    "tool.vp-version",
  ]);
});

Deno.test("parent manager adapter refuses another account, package intent or parent kind", async () => {
  const f = fixture();
  const account = input();
  account.manager.materialize = { user: "another" };
  for (
    const args of [
      account,
      {
        ...input(),
        manager: { ...input().manager, package: "arbitrary/package" },
      },
      { ...input(), parent: { ...parent, kind: "node.remove" } },
      { ...input(), manager: { ...input().manager, manager: "brew" } },
      {
        ...input("apt"),
        manager: { ...input("apt").manager, materialize: { user: "orbit" } },
      },
    ]
  ) {
    await assertRejects(
      () => f.action(args, f.context),
      Error,
      "Invalid parent manager intent",
    );
  }
  assertEquals(f.calls.length, 0);
  assertEquals(f.resources.size, 0);
});

Deno.test("canonical manager refusal remains bounded and contains no diagnostic output", async () => {
  const f = fixture({
    error_code: "tool.composer_scope_absent",
    diagnostic: "private-sentinel",
  });
  const result = await f.action(input(), f.context);
  assertEquals(result.outcome.status, "failed");
  assertEquals(result.outcome.error_code, "tool.manager_unavailable");
  assertEquals(JSON.stringify(result).includes("private-sentinel"), false);
  assertEquals(
    JSON.stringify([...f.resources.values()]).includes("private-sentinel"),
    false,
  );
});

Deno.test("uncertain manager transport writes no terminal outcome", async () => {
  const f = fixture();
  const action = parentManagerAction(() => {
    throw new Error("transport uncertain");
  });
  await assertRejects(
    () => action(input(), f.context),
    Error,
    "transport uncertain",
  );
  assertEquals(f.resources.size, 0);
});

Deno.test("parent manager action uses the canonical local sudo and admitted digest boundary", async () => {
  const f = fixture();
  const calls: NodeCall[] = [];
  const action = parentManagerAction(toolAgent((call) => {
    calls.push(call);
    return Promise.resolve(
      call.name.endsWith("-version") ? { version: "3.0.0" } : {},
    );
  }));
  await action(input(), f.context);
  assertEquals(calls.map((c) => [c.name, c.pin, c.sudo, c.mutates]), [
    ["tool.materialize-composer", parent.agent_sha256, true, true],
    ["tool.composer-version", parent.agent_sha256, false, false],
    ["tool.composer-version", parent.agent_sha256, false, false],
  ]);
});

Deno.test("cancelled parent manager action starts no local command", async () => {
  const f = fixture();
  const controller = new AbortController();
  controller.abort();
  await assertRejects(() =>
    f.action(input(), { ...f.context, signal: controller.signal })
  );
  assertEquals(f.calls.length, 0);
  assertEquals(f.resources.size, 0);
});
