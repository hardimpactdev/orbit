import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Condition = { type: string; conditions?: Condition[] };
type Step = { name: string; guard?: unknown; dependsOn?: { step?: string; condition: Condition }[] };
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

/**
 * A guarded step that does not run is `skipped`, and Swamp's `completed` accepts only `succeeded` and `failed`. So a step that waits
 * for a guarded step with plain `completed` is skipped as well whenever that guard holds, and an end check skipped that way leaves a
 * run without its result (tasks.publish 20763, #3681). Such a wait must name `skipped` too, unless both steps share one guard (a
 * deliberate chain that skips together).
 */
const vmOnly = new Set(["workflow-operation-task-idle-save.yaml: Confirm the save <- Revoke the token"]); // Task VM path; it goes away.

Deno.test("a step that waits for a guarded step with `completed` also accepts `skipped`", async () => {
    const accepts = (condition: Condition, status: string): boolean =>
        condition.type === "or" ? (condition.conditions ?? []).some((item) => accepts(item, status)) : condition.type === status ||
            (condition.type === "completed" && ["succeeded", "failed"].includes(status)) || condition.type === "always";
    const bad: string[] = [];
    let checked = 0;
    for await (const entry of Deno.readDir(directory)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const workflow = parse(await Deno.readTextFile(new URL(entry.name, directory))) as Workflow;
        const steps = new Map((workflow.jobs ?? []).flatMap((job) => job.steps ?? []).map((step) => [step.name, step]));
        for (const step of steps.values()) {
            for (const dependency of step.dependsOn ?? []) {
                const waited = steps.get(dependency.step ?? "");
                if (!waited || !("guard" in waited) || !accepts(dependency.condition, "succeeded") || !accepts(dependency.condition, "failed")) {
                    continue;
                }
                checked += 1;
                const name = `${entry.name}: ${step.name} <- ${waited.name}`;
                if (!accepts(dependency.condition, "skipped") && waited.guard !== step.guard && !vmOnly.has(name)) bad.push(name);
            }
        }
    }
    assert(bad.length === 0, `steps skipped whenever the guard of a step they wait for holds:\n${bad.join("\n")}`);
    assert(checked >= 300, `only ${checked} waits on guarded steps were checked`);
});
