import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { expandGlob } from "jsr:@std/fs@1.0.19/expand-glob";
import { ROOT } from "../bin/gate-test-support.ts";
Deno.test("operation workflows never schedule Gateway claims", async () => {
  for await (const path of expandGlob(`${ROOT}/workflows/workflow-operation-*.yaml`)) {
    const source = await Deno.readTextFile(path.path);
    const flow = parse(source) as { trigger?: { schedule?: unknown } };
    assertFalse(Boolean(flow.trigger?.schedule), path.path);
    assertFalse(source.includes("@nckrtl/operation-discovery"), path.path);
    assertFalse(source.includes("self.work.slot"), path.path);
  }
});
Deno.test("Gateway discovery and slot models cannot claim work", async () => {
  for (const pattern of ["operation-discovery/*.yaml", "operation-*/*-slot-*.yaml"]) {
    for await (const entry of expandGlob(`${ROOT}/models/@nckrtl/${pattern}`)) assertFalse(Boolean(entry), entry.path);
  }
});
Deno.test("native retention keeps history and current data", () => {
  const marker = parse(Deno.readTextFileSync(`${ROOT}/.swamp.yaml`)) as Record<string, unknown>;
  assert(marker.autoGc);
  assertEquals(marker.garbageCollection, { automatic: true, retention: "30d", workflowRuns: "6h", outputs: "1h", bookkeeping: "2h" });
});
