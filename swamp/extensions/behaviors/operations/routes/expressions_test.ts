import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";

/**
 * Swamp's expression engine rejects `has()` on a path that indexes a map or list (`has(a[b].c)`) when the run starts, so
 * the workflow fails in its first step although it validates. Test for the key with `"c" in a[b]`.
 */
Deno.test("no workflow calls has() on an indexed path", async () => {
    const found: string[] = [];
    for await (const entry of Deno.readDir(`${ROOT}/workflows`)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const lines =
            (await Deno.readTextFile(`${ROOT}/workflows/${entry.name}`))
                .split("\n");
        lines.forEach((line, index) => {
            if (/\bhas\([^)]*\[/.test(line)) {
                found.push(`${entry.name}:${index + 1}`);
            }
        });
    }
    assertEquals(found, []);
});
