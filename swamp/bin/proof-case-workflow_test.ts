import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { generate, NotBuilt } from "./proof-case-workflow.ts";
import { invalidNames, perRunNames } from "./lint-behavior-names.ts";

const cases = await Deno.readTextFile(
    new URL("../proof/firewall/allow.cases.yaml", import.meta.url),
);
type Step = {
    name: string;
    dependsOn?: Array<{ step: string }>;
    task: { type: string; inputs?: { argv?: string[]; okExitCodes?: unknown } };
};
const steps = (yaml: string): Step[] =>
    (parse(yaml) as { jobs: Array<{ steps: Step[] }> }).jobs.flatMap((job) =>
        job.steps
    );

Deno.test("new-restricted-allow becomes native steps with no runner and no verdict layer", () => {
    const yaml = generate(cases, "new-restricted-allow");
    const all = steps(yaml);
    assert(all.length > 20);
    assert(!yaml.includes("runner.py"), "the Python runner is not called");
    assert(
        all.every((step) => /^[A-Za-z0-9_]+$/.test(step.name)),
        "step names are CEL identifiers",
    );
    assertEquals(new Set(all.map((step) => step.name)).size, all.length);
    const kinds = new Set(all.map((step) => step.task.type));
    assertEquals([...kinds].sort(), ["assert", "behavior"]);
});

Deno.test("every dependency names a step of the same workflow", () => {
    const all = steps(generate(cases, "new-restricted-allow"));
    const names = new Set(all.map((step) => step.name));
    for (const step of all) {
        for (const dep of step.dependsOn ?? []) {
            assert(names.has(dep.step), `${step.name} depends on ${dep.step}`);
        }
    }
});

Deno.test("a blocked probe accepts exit 124 and a connecting probe exit 0", () => {
    const all = steps(generate(cases, "new-restricted-allow"));
    const probes = all.filter((step) => step.name.startsWith("probe_"));
    assertEquals(probes.length, 8);
    assert(
        probes.some((step) =>
            JSON.stringify(step.task.inputs?.okExitCodes) === "[124]"
        ),
    );
    assert(
        probes.some((step) =>
            JSON.stringify(step.task.inputs?.okExitCodes) === "[0]"
        ),
    );
});

Deno.test("JSON is read with jq -e in argv, never in CEL", () => {
    const all = steps(generate(cases, "new-restricted-allow"));
    const jq = all.filter((step) => step.task.inputs?.argv?.[0] === "jq");
    assert(jq.length >= 3);
    assert(jq.some((step) => step.task.inputs?.argv?.includes("-e")));
});

Deno.test("--skip-act leaves out the command so the expectations fail", () => {
    const names = steps(generate(cases, "new-restricted-allow", true)).map((
        step,
    ) => step.name);
    assert(!names.includes("cli_firewall_allow"));
    assert(names.includes("check_ufw"));
});

Deno.test("steps outside the built vocabulary stop the generator", () => {
    assertThrows(() => generate(cases, "interrupted-reorder"), NotBuilt);
});

Deno.test("no reference is written as an object", () => {
    const yaml = generate(cases, "new-restricted-allow");
    assert(!yaml.includes("[object Object]"));
    assert(yaml.includes("l.contains((steps.src_gateway.outputs.stdout"));
});

Deno.test("the behavior names pass the repo lint", () => {
    const workflow = parse(generate(cases, "new-restricted-allow"));
    assertEquals(invalidNames(workflow), []);
    assertEquals(perRunNames(workflow), []);
});

Deno.test("steps are spread over four behavior names so they can run in parallel", () => {
    const names = new Set(
        steps(generate(cases, "new-restricted-allow")).flatMap((step) => {
            const name = (step.task as { behaviorName?: string }).behaviorName;
            return name?.includes("proof-verify-ssh") ? [name] : [];
        }),
    );
    assertEquals(names.size, 4);
});
