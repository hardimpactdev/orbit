import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../bin/gate-test-support.ts";
Deno.test("full-intent workflows accept execution_secrets; manager setup takes prepared SSH", async () => {
  const refused: string[] = [];
  for await (const file of Deno.readDir(`${ROOT}/workflows`)) {
    if (!/^workflow-operation-.*\.yaml$/.test(file.name)) continue;
    const { inputs = {} } = parse(await Deno.readTextFile(`${ROOT}/workflows/${file.name}`)) as {
      inputs?: { properties?: Record<string, { type?: string }>; additionalProperties?: boolean; required?: string[] };
    };
    const properties = inputs.properties ?? {};
    // Shared child, not a Gateway entry point: callers pass prepared SSH and selected inputs.
    if (file.name === "workflow-operation-tool-manager-setup.yaml") {
      assertEquals(properties.ssh?.type, "object");
      assertEquals(inputs.required?.includes("ssh"), true);
      continue;
    }
    if ("intent" in properties && inputs.additionalProperties === false && properties.execution_secrets?.type !== "object") {
      refused.push(file.name);
    }
  }
  assertEquals(refused.sort(), []);
});
