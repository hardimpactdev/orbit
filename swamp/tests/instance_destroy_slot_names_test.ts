/**
 * The step names of a container removal come from a bounded pool per Node: `<family>-<node>-<instance_id % 64>`. Swamp keeps one
 * catalog row per name for good, so a name per Instance grows the catalog with every clone; one name per Node makes removals at once
 * wait on each other, and a wait over 60 s fails the step (Operations 23368 and 23496). The Gateway keeps two removals of one slot on a
 * Node apart (RoutePublicationHolds::DestroySlots), so a removal never waits on another one's name.
 */
import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; guard?: string; forEach?: { in: string }; task: { type: string; behaviorName?: string } };
const steps = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-destroy.yaml", import.meta.url))) as {
    jobs: { steps: Step[] }[];
}).jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const evaluate = (expr: string, context: Record<string, unknown>) => cel.evaluate(expr.slice(3, -2).trim(), context);
const Slots = 64;

const intent = (instanceId: number) => ({
    instance_id: instanceId, container_destroy: { instance_id: instanceId }, members: [{ instance_id: instanceId, route_id: 7 }],
    direct_destroy: { hibernation: [0, 1, 2].map((i) => ({ instance_id: instanceId, path: `/h/${i}` })) },
});
// A routed removal whose withdrawal succeeded: every step of the container path runs, the Route Node release too.
const statuses = { "Withdraw the Route": { status: "succeeded", outputs: { result: { ready: true } } } };

/** The container-path behavior steps and the names they render for one removal. */
function names(nodeId: number, instanceId: number, flow: Step[] = steps): Map<string, string> {
    const context = { inputs: { node_id: nodeId, intent: intent(instanceId) }, steps: statuses, run: { id: "run" } };
    const rendered = new Map<string, string>();
    for (const step of flow) {
        if (step.task.type !== "behavior" || (step.guard && evaluate(step.guard, context) === true)) continue;
        const items = step.forEach ? evaluate(step.forEach.in, context) as unknown[] : [undefined];
        items.forEach((item, index) => {
            const self = { file: item, _index: index };
            rendered.set(`${step.name}#${index}`, String(evaluate(step.task.behaviorName!, { ...context, self })));
        });
    }
    return rendered;
}

/** Every name is `<family>-<node>-<slot>`, and 1000 Instances on a Node give at most `Slots` names per step. */
function assertPooled(flow: Step[]) {
    const perStep = new Map<string, Set<string>>();
    for (let instanceId = 1; instanceId <= 1000; instanceId++) {
        for (const nodeId of [9, 12]) {
            for (const [step, name] of names(nodeId, instanceId, flow)) {
                assertMatch(name, new RegExp(`^[a-z][a-z-]*-${nodeId}-${instanceId % Slots}$`), `${step}: ${name}`);
                const key = `${nodeId} ${step.split("#")[0]}`;
                perStep.set(key, (perStep.get(key) ?? new Set()).add(name));
            }
        }
    }
    for (const [key, set] of perStep) assert(set.size <= Slots, `${key}: ${set.size} names`);
    return perStep;
}

Deno.test("a container removal names each step from 64 slots per Node, never per Instance", () => {
    const perStep = assertPooled(steps);
    assertEquals([...perStep.keys()].filter((key) => key.startsWith("9 ")).sort(), [
        "9 Clean container files", "9 Delete the container", "9 Prepare container removal", "9 Read container removal",
        "9 Release the Route Nodes", "9 Remove hibernation files",
    ]);
    // Six families of 64 names per Node: 384 names at most, however many Instances a Node has had.
    assertEquals([...perStep].filter(([key]) => key.startsWith("9 ")).reduce((sum, [, set]) => sum + set.size, 0), 6 * Slots);
    // The three hibernation files share their removal's name and go one after the other.
    assertEquals(new Set([...names(9, 3206)].filter(([step]) => step.startsWith("Remove hibernation files")).map(([, name]) => name)),
        new Set(["instance-destroy-hibernation-9-6"]));
    assertEquals((steps.find((step) => step.name === "Remove hibernation files") as Step & { concurrency?: number }).concurrency, 1);
});

Deno.test("a name off the pool fails the check: one per Instance, or one per Node", () => {
    for (
        const behaviorName of [
            '${{ ("instance-destroy-hibernation-" + string(self.file.instance_id) + "-" + string(self._index)).split(".").join("-") }}',
            '${{ "id-delete-the-container-" + string(inputs.node_id) }}',
        ]
    ) {
        const flow = steps.map((step) => step.name === "Delete the container" || step.name === "Remove hibernation files"
            ? { ...step, task: { ...step.task, behaviorName } } : step);
        let failed = false;
        try {
            assertPooled(flow);
        } catch {
            failed = true;
        }
        assert(failed, behaviorName);
    }
});
