// Render real Gateway inputs through the complete instantiated workflow graph.
// Machine behavior outputs are inert fixtures; no behavior or external command runs.
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Row = Record<string, any>;
const cel = new Environment({
    unlistedVariablesAreDyn: true,
    homogeneousAggregateLiterals: false,
})
    .registerFunction("vaultget(dyn, dyn): string", () => "fixture");
const root = new URL("../../../../swamp/workflows/", import.meta.url);
let expressions = 0;
const files = new Set<string>();
const trace: string[] = [];
let publicationStarted = false, siteRemoved = false, dnsRemoved = false;

function evaluate(expression: string, context: Row): any {
    expressions++;
    return cel.evaluate(
        expression.replaceAll("vault.get(", "vaultget("),
        context,
    );
}
function render(value: any, context: Row): any {
    if (typeof value === "string") {
        const whole = /^\$\{\{([\s\S]*)\}\}$/.exec(value);
        if (whole) return evaluate(whole[1].trim(), context);
        return value.replace(
            /\$\{\{([\s\S]*?)\}\}/g,
            (_: string, expr: string) => String(evaluate(expr.trim(), context)),
        );
    }
    if (Array.isArray(value)) return value.map((item) => render(item, context));
    if (value && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map((
                [key, item],
            ) => [key, render(item, context)]),
        );
    }
    return value;
}
function allows(condition: Row, status: string): boolean {
    if (condition.type === "always") return true;
    if (condition.type === "or") {
        return condition.conditions.some((part: Row) => allows(part, status));
    }
    return condition.type === status;
}
function workflow(name: string, inputs: Row, failPublication: boolean): Row {
    files.add(name);
    const flow = parse(
        Deno.readTextFileSync(new URL("workflow-" + name + ".yaml", root)),
    ) as Row;
    const steps: Row = {}, jobs: Row = {};
    const context: Row = {
        inputs,
        steps,
        run: { id: "00000000-0000-4000-8000-000000000001" },
        self: {},
    };
    for (const job of flow.jobs) {
        const jobAllowed = (job.dependsOn ?? []).every((dep: Row) =>
            allows(dep.condition, jobs[dep.job] ?? "skipped")
        );
        const statuses: string[] = [];
        for (const step of job.steps) {
            const items = step.forEach
                ? render(step.forEach.in, context)
                : [null];
            for (const [index, item] of items.entries()) {
                const label = step.name + (step.forEach ? "-" + index : "");
                const local = {
                    ...context,
                    self: step.forEach
                        ? { [step.forEach.item]: item, _index: index }
                        : {},
                };
                const guard = step.guard ? render(step.guard, local) : false;
                // All task expressions use actual inputs; a missing key fails here.
                const task = render(step.task, local);
                const allowed = jobAllowed &&
                    (step.dependsOn ?? []).every((dep: Row) =>
                        Object.entries(steps).filter(([key]) =>
                            key === dep.step || key.startsWith(dep.step + "-")
                        )
                            .every(([, value]) =>
                                allows(dep.condition, (value as Row).status)
                            )
                    );
                let status = guard || !allowed ? "skipped" : "succeeded";
                let outputs: Row = {
                    status: "compliant",
                    stdout: "orbit:x:1000:1000::/home/orbit:/bin/sh",
                    assets: "/fixture/assets",
                };
                if (status !== "skipped") {
                    if (task.type === "workflow") {
                        const child = workflow(
                            task.workflowIdOrName,
                            task.inputs,
                            failPublication,
                        );
                        status = child.status;
                        outputs = child.outputs;
                    } else if (task.type === "assert") {
                        if (!evaluate(task.expr, local)) status = "failed";
                    } else if (
                        name === "route-create-site" && step.name === "site"
                    ) {
                        publicationStarted = true;
                        if (failPublication) status = "failed";
                        else siteRemoved = true;
                    } else if (
                        name === "route-create-dns" && step.name === "records"
                    ) {
                        dnsRemoved = true;
                    }
                    trace.push(name + "/" + label + ":" + status);
                }
                steps[label] = { status, outputs };
                statuses.push(status);
            }
        }
        jobs[job.name] = statuses.includes("failed")
            ? "failed"
            : statuses.every((value) => value === "skipped")
            ? "skipped"
            : "succeeded";
    }
    return {
        status: Object.values(jobs).includes("failed") ? "failed" : "succeeded",
        outputs: {},
        steps,
    };
}
const input = JSON.parse(await new Response(Deno.stdin.readable).text());
const result = workflow(
    "operation-route-removal",
    input.inputs,
    input.fail_publication ?? false,
);
console.log(
    JSON.stringify({
        ...result,
        expressions,
        files: [...files],
        trace,
        publicationStarted,
        siteRemoved,
        dnsRemoved,
    }),
);
