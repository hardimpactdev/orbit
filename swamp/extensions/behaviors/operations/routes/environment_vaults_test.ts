import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../bin/gate-test-support.ts";
type Flow = {
    name: string;
    vaults?: string[];
    jobs?: {
        steps: { task?: { type: string; workflowIdOrName?: string } }[];
    }[];
};
Deno.test("every environment ancestor declares its nested child vault", async () => {
    const workflows = new Map<string, Flow>();
    for await (const entry of Deno.readDir(`${ROOT}/workflows`)) {
        if (entry.name.endsWith(".yaml")) {
            const flow = parse(
                await Deno.readTextFile(`${ROOT}/workflows/${entry.name}`),
            ) as Flow;
            if (flow?.name) workflows.set(flow.name, flow);
        }
    }
    function reaches(name: string, seen = new Set<string>()): boolean {
        if (seen.has(name)) return false;
        if (name === "route-environment-write") return true;
        const next = new Set([...seen, name]);
        return (workflows.get(name)?.jobs ?? []).some((job) =>
            job.steps.some(({ task }) =>
                task?.type === "workflow" &&
                workflows.has(task.workflowIdOrName ?? "") &&
                reaches(task.workflowIdOrName!, next)
            )
        );
    }
    assertEquals(
        [...workflows].filter(([name, flow]) =>
            reaches(name) && !flow.vaults?.includes("orbit-control-inputs")
        ).map((
            [name],
        ) => name),
        [],
    );
});
