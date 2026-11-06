import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = { name: string; guard?: unknown };
type Workflow = { jobs?: { steps?: Step[] }[] };

const directory = new URL("../workflows/", import.meta.url);

/**
 * A step guard is an expression. Without the `${{ }}` wrapper Swamp reads it as literal text and the run fails at its end with
 * no failed step (the release steps of #3081 did, op 12376).
 */
Deno.test("every step guard is wrapped in ${{ }}", async () => {
    const bad: string[] = [];
    for await (const entry of Deno.readDir(directory)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const workflow = parse(await Deno.readTextFile(new URL(entry.name, directory))) as Workflow;
        for (const job of workflow.jobs ?? []) {
            for (const step of job.steps ?? []) {
                if (step.guard === undefined || typeof step.guard === "boolean") continue;
                const text = String(step.guard).trim();
                if (!(text.startsWith("${{") && text.endsWith("}}"))) bad.push(`${entry.name}: ${step.name}: ${text.slice(0, 60)}`);
            }
        }
    }
    assert(bad.length === 0, `guards that are not expressions:\n${bad.join("\n")}`);
});
