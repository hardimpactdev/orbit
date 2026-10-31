import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { GatewayOperation } from "./direct.ts";
const identity = {
  operation_id: 17,
  kind: "sample.apply",
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
Deno.test("shared private material callback and output bind to the exact recorded run", async () => {
  const calls: unknown[] = [];
  const gateway = new GatewayOperation((method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({
      status: 200,
      body: {
        data: method === "GET" ? { credential: "private-sentinel" } : {
          stored: { credential: { $secret: "fixture.credential", id: 3 } },
        },
      },
    });
  }, identity);
  assertEquals(
    await gateway.withPrivateMaterial(async (material) => {
      assertEquals(material.credential, "private-sentinel");
      await gateway.storeSecretOutput({ credential: material.credential });
      return;
    }),
    undefined,
  );
  assertEquals(calls, [{
    method: "GET",
    path:
      `/api/v1/direct-operations/17/private-material?run_id=${identity.run_id}`,
    body: undefined,
  }, {
    method: "POST",
    path: "/api/v1/direct-operations/17/secret-output",
    body: {
      run_id: identity.run_id,
      outputs: { credential: "private-sentinel" },
    },
  }]);
});
Deno.test("shared private transport hides schema, callback and HTTP error diagnostics", async () => {
  const gateway = new GatewayOperation(
    () =>
      Promise.resolve({
        status: 200,
        body: { data: { credential: "private-sentinel" } },
      }),
    identity,
  );
  await assertRejects(
    () =>
      gateway.withPrivateMaterial(() =>
        Promise.reject(new Error("private-sentinel"))
      ),
    Error,
    "Private material callback failed",
  );
  const refused = new GatewayOperation(
    () => Promise.reject(new Error("private-sentinel")),
    identity,
  );
  await assertRejects(
    () => refused.storeSecretOutput({ credential: "private-sentinel" }),
    Error,
    "Private output write failed",
  );
});

Deno.test("private material helper ignores direct/nested/private getters and stateful serialization returns", async () => {
  const gateway = new GatewayOperation(
    () =>
      Promise.resolve({
        status: 200,
        body: { data: { credential: "private-sentinel" } },
      }),
    identity,
  );
  let getters = 0;
  let serializations = 0;
  for (
    const make of [
      (material: Record<string, unknown>) => material,
      (material: Record<string, unknown>) => ({ nested: [material] }),
      (_material: Record<string, unknown>) => ({
        get credential() {
          getters++;
          throw new Error("private-sentinel");
        },
      }),
      (_material: Record<string, unknown>) => ({
        toJSON() {
          serializations++;
          if (serializations > 1) throw new Error("private-sentinel");
          return { credential: "private-sentinel" };
        },
      }),
    ]
  ) {
    const result = await gateway.withPrivateMaterial((material) =>
      Promise.resolve(make(material)) as unknown as Promise<void>
    );
    assertEquals(result, undefined);
    assertEquals(JSON.stringify({ result }), "{}");
  }
  assertEquals(getters, 0);
  assertEquals(serializations, 0);
});
