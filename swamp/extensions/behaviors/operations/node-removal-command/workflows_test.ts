import { assertEquals } from "jsr:@std/assert@1.0.19";
import { generatedRemovalWorkflows } from "./workflows.ts";
import { analyzeYaml } from "../../../../bin/check-swamp-shape.ts";

Deno.test("native Node removal definitions match their source and ship no programs", async () => {
    for (const file of generatedRemovalWorkflows()) {
        assertEquals(await Deno.readTextFile(file.path), file.text);
        const findings = analyzeYaml(file.path.pathname, file.text).findings;
        assertEquals(findings, []);
        assertEquals(file.text.includes("methodName: script"), false);
        assertEquals(file.text.includes("command/shell"), false);
        assertEquals(file.text.includes("worker token"), false);
    }
});
