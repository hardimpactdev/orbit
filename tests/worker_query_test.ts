import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { queryWorkerData } from "../extensions/behaviors/_lib/worker_query.ts";

Deno.test("worker projection uses only permitted query records and no select", async () => {
  const ctx = {
    queryData: (predicate: string, select?: string) => {
      assertEquals(predicate.includes('modelName == "fixture"'), true);
      assertEquals(select, undefined);
      return Promise.resolve([{
        attributes: { workItem: "tg-7" },
        content: "report",
      }]);
    },
  };
  assertEquals(
    await queryWorkerData(ctx, 'modelName == "fixture"', "attributes"),
    [{ workItem: "tg-7" }],
  );
  assertEquals(
    await queryWorkerData(ctx, 'modelName == "fixture"', "attributes.workItem"),
    ["tg-7"],
  );
  assertEquals(
    await queryWorkerData(ctx, 'modelName == "fixture"', "content"),
    ["report"],
  );
});
Deno.test("worker capability refusal propagates without local data fallback", async () => {
  await assertRejects(
    () =>
      queryWorkerData(
        { queryData: () => Promise.reject(new Error("access refused")) },
        "true",
        "attributes",
      ),
    Error,
    "access refused",
  );
});
