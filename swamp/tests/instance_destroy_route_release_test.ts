import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    dependsOn?: { step: string; condition: { type: string } }[];
    task: { behaviorType?: string; inputs: { run?: string; env?: Record<string, string> } };
};
const flow = parse(
    Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-destroy.yaml", import.meta.url)),
) as { jobs: { steps: Step[] }[] };
const steps = flow.jobs.flatMap((j) => j.steps);
const step = (name: string) => steps.find((s) => s.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const evaluate = (expr: string, intent: Record<string, unknown>, statuses: Record<string, unknown> = {}) =>
    cel.evaluate(expr.slice(3, -2).trim(), { inputs: { operation_id: 4242, intent }, steps: statuses, run: { id: "0b6f3c1e-1d2a-4c55-9a43-5d0e2f7b8c91" } });

// A normal removal of an Instance with a Route, as admission records it for a clone of a fixture Project.
const routed = {
    members: [{ instance_id: 7, route_id: 31 }],
    route_mutations: { forward: { publications: [{ node_id: 9, serves_routes: true }] }, restore: { routes: [] } },
};
const withdrawn = (ready: boolean) => ({ "Withdraw the Route": { status: "succeeded", outputs: { result: { ready } } } });

Deno.test("a normal Route removal releases the Route Nodes right after its withdraw step holds, with the run's own identity", () => {
    const release = step("Release the Route Nodes");
    // Skip guard: false runs the step.
    assertEquals(evaluate(release.guard!, routed, withdrawn(true)), false);
    assertEquals(release.dependsOn, [{ step: "Withdraw the Route", condition: { type: "always" } }]);
    assertEquals(release.task.behaviorType, "command/shell");
    assertEquals(cel.evaluate(release.task.inputs.env!.ORBIT_HOLD_URL.slice(3, -2).trim(), { inputs: { operation_id: 4242 } }),
        "https://gateway.orbit/api/v1/direct-operations/4242/route-release");
    assertEquals(cel.evaluate(release.task.inputs.env!.ORBIT_HOLD_REQUEST.slice(3, -2).trim(), { run: { id: "0b6f3c1e-1d2a-4c55-9a43-5d0e2f7b8c91" } }),
        '{"run_id":"0b6f3c1e-1d2a-4c55-9a43-5d0e2f7b8c91"}');
    // The withdraw itself runs for that removal: the release can only follow it.
    assertEquals(evaluate(step("Withdraw the Route").guard!, routed, {}), false);
});

Deno.test("no release while the Route is not withdrawn: a withdraw that did not hold, failed, or never ran keeps the hold to the end", () => {
    const guard = step("Release the Route Nodes").guard!;
    assertEquals(evaluate(guard, routed, withdrawn(false)), true);
    assertEquals(evaluate(guard, routed, { "Withdraw the Route": { status: "failed", outputs: {} } }), true);
    assertEquals(evaluate(guard, routed, { "Withdraw the Route": { status: "skipped", outputs: {} } }), true);
    assertEquals(evaluate(guard, { members: [{ instance_id: 7, route_id: null }] }, {}), true);
});

Deno.test("a retry of a removal whose Route an earlier run withdrew skips the withdraw; an intent without the mark still runs it", () => {
    const guard = step("Withdraw the Route").guard!;
    assertEquals(evaluate(guard, { ...routed, route_withdrawn: true }, {}), true);
    assertEquals(evaluate(guard, { ...routed, route_withdrawn: false }, {}), false);
    assertEquals(evaluate(guard, routed, {}), false);
    // The later steps still run after a skipped withdraw.
    for (const name of ["Remove hibernation files", "Delete the container", "Drop owned databases"]) {
        assertEquals(evaluate(step(name).guard!, { ...routed, route_withdrawn: true, container_destroy: {} }, { "Withdraw the Route": { status: "skipped" } }), false, name);
    }
});

/** Runs the shipped command with a stand-in curl that answers with `code` (or fails when `code` is "fail"). */
async function release(code: string): Promise<{ success: boolean; stdout: string; args: string }> {
    const dir = await Deno.makeTempDir();
    const log = `${dir}/args`;
    const curl = code === "fail"
        ? `#!/bin/sh\necho "$@" > ${log}\nprintf 000\necho 'curl: (7) Failed to connect' >&2\nexit 7\n`
        : `#!/bin/sh\necho "$@" > ${log}\nprintf ${code}\n`;
    await Deno.writeTextFile(`${dir}/curl`, curl, { mode: 0o755 });
    const out = await new Deno.Command("/bin/sh", {
        args: ["-c", step("Release the Route Nodes").task.inputs.run!],
        env: { PATH: `${dir}:/usr/bin:/bin`, ORBIT_HOLD_URL: "https://gateway.orbit/api/v1/direct-operations/4242/route-release", ORBIT_HOLD_REQUEST: '{"run_id":"r"}' },
        stdout: "piped",
        stderr: "null",
    }).output();
    const args = await Deno.readTextFile(log);
    await Deno.remove(dir, { recursive: true });
    return { success: out.success, stdout: new TextDecoder().decode(out.stdout), args };
}

Deno.test("the release says whether it happened, with the Gateway's status, and a refused or failed call never fails the run", async () => {
    const granted = await release("200");
    assert(granted.success);
    assertEquals(JSON.parse(granted.stdout), { route_hold_released: true, http_status: "200" });
    for (const flag of ["--proto =https", "--retry 2", "--retry-all-errors", "--max-time 30", "--data {\"run_id\":\"r\"}"]) {
        assert(granted.args.includes(flag), `curl needs ${flag}`);
    }
    const refused = await release("403");
    assert(refused.success);
    assertEquals(JSON.parse(refused.stdout), { route_hold_released: false, http_status: "403" });
    const unreachable = await release("fail");
    assert(unreachable.success);
    assertEquals(JSON.parse(unreachable.stdout), { route_hold_released: false, http_status: "000" });
});
