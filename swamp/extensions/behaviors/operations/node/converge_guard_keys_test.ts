import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";

Deno.test("guarded node converge outputs are read only after a key check", async () => {
  const flow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-operation-node-converge-direct.yaml`)) as {
    jobs: { steps: { name: string; guard?: unknown; [key: string]: unknown }[] }[];
  };
  const steps = flow.jobs.flatMap((j) => j.steps);
  const guarded = new Set(steps.filter((s) => "guard" in s).map((s) => s.name));
  assert(guarded.has("Install Caddy"));
  for (const { name, ...body } of steps) {
    const text = JSON.stringify(body).replaceAll('\\"', '"');
    for (const match of text.matchAll(/steps\["([^"]+)"\]/g)) {
      if (guarded.has(match[1])) assert(text.includes(`"${match[1]}" in steps`), `${name} reads ${match[1]} unguarded`);
    }
  }
});
