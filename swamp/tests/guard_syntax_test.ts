import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = { name: string; guard?: unknown };
type Workflow = { jobs?: { steps?: Step[] }[] };

const directory = new URL("../workflows/", import.meta.url);

/**
 * A step guard is one expression. Without the `${{ }}` wrapper Swamp reads it as literal text and the run fails at its end with
 * no failed step (the release steps of #3081 did, op 12376). Swamp also refuses two expressions in one guard, an empty one, leading
 * whitespace, and a guard that is not a string.
 */
const expression = /^\$\{\{\s*\S[\s\S]*\}\}$/;

Deno.test("every step guard is one ${{ }} expression", async () => {
    const bad: string[] = [];
    let checked = 0;
    for await (const entry of Deno.readDir(directory)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const workflow = parse(await Deno.readTextFile(new URL(entry.name, directory))) as Workflow;
        for (const job of workflow.jobs ?? []) {
            for (const step of job.steps ?? []) {
                if (!("guard" in step)) continue;
                checked += 1;
                const text = typeof step.guard === "string" ? step.guard.trimEnd() : `(${typeof step.guard}) ${String(step.guard)}`;
                if (!expression.test(text) || text.split("${{").length !== 2) bad.push(`${entry.name}: ${step.name}: ${text.slice(0, 60)}`);
            }
        }
    }
    assert(bad.length === 0, `guards that are not one expression:\n${bad.join("\n")}`);
    // A renamed key or an empty directory must not pass with nothing checked.
    assert(checked >= 100, `only ${checked} guards were checked`);
});
