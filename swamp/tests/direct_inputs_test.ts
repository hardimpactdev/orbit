import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.20/expand-glob";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "./_support/native_harness.ts";

Deno.test("Gateway-started full-intent workflows accept execution_secrets", async () => {
  const names = new Set<string>();
  for await (const entry of expandGlob("*/direct-operations.php", { root: `${ROOT}/../apps/gateway/app/Domain` })) {
    const source = await Deno.readTextFile(entry.path);
    for (const match of source.matchAll(/'workflow'\s*=>\s*'([^']+)'/g)) names.add(match[1]);
  }
  assert(names.size > 0, "The DirectOperation registry must identify Gateway workflow starts");
  const refused: string[] = [];
  for (const name of [...names].sort()) {
    const { inputs = {} } = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
      inputs?: { additionalProperties?: boolean; properties?: Record<string, { type?: string }> };
    };
    const properties = inputs.properties ?? {};
    if (properties.intent && inputs.additionalProperties === false && properties.execution_secrets?.type !== "object") {
      refused.push(name);
    }
  }
  console.log(`Checked ${names.size} Gateway-started workflow schemas`);
  assertEquals(refused, []);
});
