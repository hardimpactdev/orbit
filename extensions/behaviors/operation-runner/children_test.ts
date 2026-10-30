import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  Executor,
  ExecutorError,
  Operation,
  operationDiscovery,
} from "./mod.ts";
import type { Http } from "../automatic-deploy/gateway.ts";

const operation = (id: number, status = "queued") => ({
  id,
  kind: id === 1 ? "test.parent" : "test.child",
  node_id: null,
  input: [],
  status,
  cancel_requested: false,
  step_results: [],
  parent_operation_id: id === 1 ? null : 1,
  child_key: id === 1 ? null : "apply",
});

Deno.test("child admission sends only the server-recorded key and preserves stamped identity", async () => {
  const calls: unknown[] = [];
  const http: Http = (method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({ status: 202, body: { data: operation(2) } });
  };
  const child = await new Executor(http).child(1, "parent-worker", "apply");
  assertEquals(child.parent_operation_id, 1);
  assertEquals(child.child_key, "apply");
  assertEquals(child.input, {});
  assertEquals(calls, [{
    method: "POST",
    path: "/api/v1/operation-executor/1/children",
    body: { worker: "parent-worker", key: "apply" },
  }]);
});

Deno.test("exact child claim sends operation_id and has no pending-work fallback", async () => {
  const http: Http = (_method, _path, body) => {
    assertEquals(body, {
      kind: "test.child",
      worker: "child-worker",
      operation_id: 2,
    });
    return Promise.resolve({ status: 200, body: { data: null } });
  };
  assertEquals(
    await new Executor(http).claim("test.child", "child-worker", 2),
    null,
  );
});

Deno.test("wait returns a terminal workflow handoff without a polling or effect request", async () => {
  let calls = 0;
  const http: Http = (method, path, body) => {
    calls++;
    assertEquals([method, path, body], [
      "POST",
      "/api/v1/operation-executor/1/wait",
      { worker: "parent-worker", keys: ["apply"] },
    ]);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          ...operation(1, "waiting"),
          waiting_for: [{ key: "apply", id: 2 }],
        },
      },
    });
  };
  const parent = await new Executor(http).wait(1, "parent-worker", ["apply"]);
  assertEquals(parent.status, "waiting");
  assertEquals(parent.waiting_for, [{ key: "apply", id: 2 }]);
  assertEquals(calls, 1);
});

Deno.test("child reads expose terminal failure for the family branch and propagate fixed refusal", async () => {
  const http: Http = (method, path) => {
    assertEquals([method, path], [
      "GET",
      "/api/v1/operation-executor/1/children",
    ]);
    return Promise.resolve({
      status: 200,
      body: {
        data: [{
          id: 2,
          key: "apply",
          kind: "test.child",
          node_id: null,
          status: "failed",
          error_code: "test.failed",
          result: [],
        }],
      },
    });
  };
  assertEquals((await new Executor(http).children(1))[0].result, {});
  const refused = new Executor(() =>
    Promise.resolve({
      status: 409,
      body: { error: { code: "operation.child_unproven" } },
    })
  );
  await assertRejects(
    () => refused.wait(1, "worker", ["foreign"]),
    ExecutorError,
    "operation.child_unproven",
  );
});

Deno.test("waiting parent is dispatched again only when read-only discovery reports ready work", async () => {
  let ready = false;
  const runner = operationDiscovery(
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            kinds: [{
              kind: "test.parent",
              claimable: ready ? 1 : 0,
              oldest_at: null,
            }],
          },
        },
      }),
  );
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "test-ca" },
    readResource: () => Promise.resolve(null),
    writeResource: (
      _spec: string,
      name: string,
      _value: Record<string, unknown>,
    ) => Promise.resolve({ name }),
  };
  assertEquals(
    (await runner.methods.discover.execute({
      family: "test-parent",
      kinds: ["test.parent"],
      worker: "tick-a",
    }, ctx)).work,
    [],
  );
  ready = true;
  assertEquals(
    (await runner.methods.discover.execute({
      family: "test-parent",
      kinds: ["test.parent"],
      worker: "tick-b",
    }, ctx)).work,
    [{
      kind: "test.parent",
      slot: 0,
      worker: "tick-b:test.parent:0",
    }],
  );
  assertEquals(Operation.parse(operation(1)).node_id, null);
});

Deno.test("child and claim accept empty public maps in descriptor and completion context", async () => {
  const value = {
    ...operation(2),
    execution_context: {
      descriptors: [{
        key: "prepare",
        step: "prepare",
        transport: "gateway",
        name: "test.prepare",
        input: [],
        resumable: false,
        required: true,
      }],
      result: [],
    },
  };
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: value } });
  const executor = new Executor(http);
  const child = await executor.child(1, "parent", "apply");
  assertEquals(child.execution_context.descriptors[0].input, {});
  assertEquals(child.execution_context.result, {});
  assertEquals(
    (await executor.claim("test.child", "child", 2))?.execution_context.result,
    {},
  );
});

Deno.test("receipt helper reads the shared keyed effect route without running an effect", async () => {
  const executor = new Executor((method, path) => {
    assertEquals([method, path], [
      "GET",
      "/api/v1/operation-executor/2/receipts/setup.install",
    ]);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          effect_key: "op-2.setup.install",
          state: "unknown",
          exit_code: null,
          result: null,
          finished_at: null,
        },
      },
    });
  });
  assertEquals((await executor.receipt(2, "setup.install")).state, "unknown");
});

Deno.test("typed family claim isolates unavailable or unproven work and preserves authentication errors", async () => {
  for (
    const code of [
      "operation.executor_invalid",
      "operation.kind_unavailable",
      "operation.child_unproven",
    ]
  ) {
    const executor = new Executor((_method, _path, body) =>
      Promise.resolve(
        (body as { kind: string }).kind === "test.unavailable"
          ? { status: 409, body: { error: { code } } }
          : { status: 200, body: { data: operation(2, "running") } },
      )
    );
    assertEquals(await executor.claim("test.unavailable", "worker"), null);
    assertEquals((await executor.claim("test.child", "worker"))?.id, 2);
  }
  const denied = new Executor(() =>
    Promise.resolve({
      status: 403,
      body: { error: { code: "operation.executor_forbidden" } },
    })
  );
  await assertRejects(
    () => denied.claim("test.child", "worker"),
    ExecutorError,
    "operation.executor_forbidden",
  );
});

Deno.test("richer terminal results survive claim child reads and effect receipts unchanged", async () => {
  const result = {
    process_ids: [7, 2],
    warnings: ["[REDACTED]"],
    retained_on_nodes: [{
      node_id: 7,
      node: "old-node",
      steps: ["caddy", "dns"],
    }],
    child_phases: [{ stage: "routes", key: "route-7" }],
    artifacts: [{
      name: "caddy",
      changed: false,
      reason: null,
      status: "unchanged",
    }],
  };
  const executor = new Executor((method, path) => {
    if (method === "GET" && path.endsWith("/children")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: [{
            id: 2,
            key: "apply",
            kind: "test.child",
            node_id: null,
            status: "succeeded",
            error_code: null,
            result,
          }],
        },
      });
    }
    if (method === "GET") {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            effect_key: "op-2.apply",
            state: "succeeded",
            exit_code: 0,
            result,
            finished_at: "2026-10-29T12:00:00Z",
          },
        },
      });
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          ...operation(2, "succeeded"),
          result,
          step_results: { effect: result },
          execution_context: { descriptors: [], result },
        },
      },
    });
  });
  const claimed = await executor.claim("test.child", "worker", 2);
  assertEquals(claimed?.result, result);
  assertEquals(claimed?.step_results.effect, result);
  assertEquals(claimed?.execution_context.result, result);
  assertEquals((await executor.child(1, "parent", "apply")).result, result);
  assertEquals((await executor.children(1))[0].result, result);
  assertEquals((await executor.receipt(2, "apply")).result, result);
});

Deno.test("terminal Operation preserves null and converts an empty PHP result map", () => {
  assertEquals(Operation.parse({ ...operation(2), result: null }).result, null);
  assertEquals(Operation.parse({ ...operation(2), result: [] }).result, {});
});
