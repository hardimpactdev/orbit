import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { observation } from "./mod.ts";
import type { Context } from "../operation-runner/mod.ts";
import type { Http } from "../automatic-deploy/gateway.ts";

const runId = "123e4567-e89b-42d3-a456-426614174099";
function fixture() {
  const saved = new Map<string, Record<string, unknown>>();
  const calls: string[] = [];
  const receipts = new Map<string, unknown>();
  const effects: string[] = [];
  let fail: string | null = null;
  let disabled = false;
  const http: Http = async (_method, path) => {
    await Promise.resolve();
    calls.push(path);
    let data: unknown = { status: disabled ? "disabled" : "recorded" };
    if (path.endsWith("/plan")) {
      data = {
        collection: runId,
        enabled: !disabled,
        nodes: [2],
        probes: [{ node_id: 2, family: "node", kind: "Rows", ids: [2] }],
      };
    }
    if (path.includes("/maintenance/")) data = { status: "succeeded" };
    if (!receipts.has(path)) {
      receipts.set(path, data);
      effects.push(path);
    }
    if (path.endsWith(fail ?? "__none__")) {
      fail = null;
      throw new Error("lost reply");
    }
    return { status: 200, body: { data: receipts.get(path) } };
  };
  function context(): Context {
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
      readResource: (name: string) => Promise.resolve(saved.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const result = await write(spec, name, value);
        saved.set(name, structuredClone(value));
        return result;
      },
    });
  }
  return {
    behavior: observation(() => http),
    context,
    calls,
    effects,
    lose: (path: string) => {
      fail = path;
    },
    disable: () => {
      disabled = true;
    },
  };
}
Deno.test("behavior persists progress using native Swamp test resources and resumes a lost read", async () => {
  const f = fixture();
  f.lose("/probes/0");
  await assertRejects(() =>
    f.behavior.methods.collect.execute({ runId }, f.context())
  );
  assertEquals(
    (await f.behavior.methods.collect.execute({ runId }, f.context())).complete,
    true,
  );
  assertEquals(f.calls.filter((path) => path.endsWith("/nodes/2")).length, 1);
  assertEquals(f.calls.filter((path) => path.endsWith("/probes/0")).length, 2);
  assertEquals(
    f.effects.filter((path) => path.endsWith("/probes/0")).length,
    1,
  );
});
Deno.test("a failed housekeeping request leaves independent routines running and resume retries only the missing receipt", async () => {
  const f = fixture();
  f.lose("/activities");
  await assertRejects(
    () => f.behavior.methods.maintain.execute({ runId }, f.context()),
    Error,
    "observation.maintenance_incomplete",
  );
  assertEquals(f.calls.map((path) => path.split("/").at(-1)), [
    "activities",
    "operations",
    "metrics",
  ]);
  await f.behavior.methods.maintain.execute({ runId }, f.context());
  assertEquals(f.calls.map((path) => path.split("/").at(-1)), [
    "activities",
    "operations",
    "metrics",
    "activities",
  ]);
  assertEquals(
    f.effects.filter((path) => path.endsWith("/activities")).length,
    1,
  );
});
Deno.test("Tasks disabled before a collection returns without probes", async () => {
  const f = fixture();
  f.disable();
  assertEquals(
    (await f.behavior.methods.collect.execute({ runId }, f.context())).complete,
    true,
  );
  assertEquals(f.calls.length, 1);
});
Deno.test("Horizon health and retention use narrow routines without commands or secret input", async () => {
  const f = fixture();
  await f.behavior.methods.health.execute({ runId }, f.context());
  await f.behavior.methods.retain.execute({ runId }, f.context());
  assertEquals(f.calls.map((path) => path.split("/").at(-1)), [
    "health",
    "batches",
    "failed",
  ]);
});
