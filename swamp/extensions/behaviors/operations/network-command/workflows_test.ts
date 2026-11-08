import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { workflow } from "./workflows.ts";

Deno.test("peer DNS uses one Ansible phase and preserves the native terminal selector", () => {
    const steps = workflow(false).jobs[0].steps;
    assertEquals(steps.map((s) => s.name), [
        "Write the intent", "Apply member DNS policy", "Check resolution", "Remove the run folder",
    ]);
    const phase = steps[1] as { task: { inputs: { run: string } } };
    assert(phase.task.inputs.run.includes('--tags member'));
    assert(phase.task.inputs.run.includes('/peer-dns.yml'));
});

Deno.test("network convergence applies the same member policy after its network check", () => {
    const steps = workflow(true).jobs[0].steps as { name: string; task: { workflowIdOrName?: string } }[];
    assertEquals(steps.at(-1)?.name, "Apply member DNS policy");
    assertEquals(steps.at(-1)?.task.workflowIdOrName, "operation-fleet-peer-dns");
});
