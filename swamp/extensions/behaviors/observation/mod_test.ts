import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { observation } from "./mod.ts";
import type { Http } from "../automatic-deploy/gateway.ts";
const runId = "123e4567-e89b-42d3-a456-426614174099";
const context = {
  globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
};
function fixture() {
  const calls: { path: string; body: unknown }[] = [];
  let disabled = false;
  let failed = false;
  const http: Http = (_method, path, body) => {
    calls.push({ path, body });
    if (failed) {
      return Promise.resolve({
        status: 503,
        body: { error: { code: "swamp.start_refused" } },
      });
    }
    return Promise.resolve({
      status: 202,
      body: { data: disabled ? { status: "disabled" } : { id: 77 } },
    });
  };
  return {
    behavior: observation(() => http),
    calls,
    disable: () => {
      disabled = true;
    },
    fail: () => {
      failed = true;
    },
  };
}
Deno.test("scheduled collection admits one native Doctor and never sends resource or command input", async () => {
  const f = fixture();
  assertEquals(await f.behavior.methods.collect.execute({ runId }, context), {
    operation_id: 77,
  });
  assertEquals(f.calls, [{
    path: `/api/v1/observation/collections/${runId}/plan`,
    body: {},
  }]);
});
Deno.test("maintenance and retention admit native parents without Gateway receipts or routine execution", async () => {
  const f = fixture();
  assertEquals(await f.behavior.methods.maintain.execute({ runId }, context), {
    operation_id: 77,
  });
  assertEquals(await f.behavior.methods.retain.execute({ runId }, context), {
    operation_id: 77,
  });
  assertEquals(f.calls.map((c) => c.path), [
    "/api/v1/observation/maintenance/maintenance",
    "/api/v1/observation/maintenance/retention",
  ]);
  assertEquals(f.calls.map((c) => c.body), [{}, {}]);
});
Deno.test("disabled collection and Horizon checks admit no child or maintenance work", async () => {
  const f = fixture();
  f.disable();
  assertEquals(await f.behavior.methods.collect.execute({ runId }, context), {
    status: "disabled",
  });
  assertEquals(await f.behavior.methods.health.execute({ runId }, context), {
    status: "disabled",
  });
  assertEquals(f.calls.length, 2);
});
Deno.test("refused admission is surfaced once and never retried by the family", async () => {
  const f = fixture();
  f.fail();
  await assertRejects(
    () => f.behavior.methods.maintain.execute({ runId }, context),
    Error,
    "swamp.start_refused",
  );
  assertEquals(f.calls.length, 1);
});
