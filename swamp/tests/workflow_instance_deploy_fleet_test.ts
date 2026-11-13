/**
 * The nested-fleet steps of a container default's deployment (docs/cli/instance/container/fleet-refresh.mmd), with the pinned CEL
 * compiler: only an intent with `fleet` runs them, a normal deployment runs the same steps as before, and a failure of a
 * nested-fleet deployment restores the old seed and starts the container again, keeping the failed phase's result.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Condition = { type: string; conditions?: Condition[] };
type Step = {
    name: string;
    guard?: string;
    dependsOn?: { step: string; condition: Condition }[];
    task: { inputs: { run?: string } };
};
const flow = parse(
    Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-deploy-direct.yaml", import.meta.url)),
) as { jobs: { name: string; steps: Step[] }[] };
const deploy = flow.jobs.find((job) => job.name === "deploy")!.steps;
const step = (name: string) => deploy.find((s) => s.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
/** A guard that is true skips its step. */
const skips = (name: string, intent: Record<string, unknown>, statuses: Record<string, string>) =>
    cel.evaluate(step(name).guard!.slice(3, -2).trim(), {
        inputs: { intent },
        steps: Object.fromEntries(Object.entries(statuses).map(([key, status]) => [key, { status }])),
    });

const container = { deployment: { variant: "container" } };
const nested = { ...container, fleet: { seed: "e".repeat(40) } };
const passed = {
    "Write the container intent": "succeeded",
    "Start the container": "succeeded",
    "Fetch into the mirror": "succeeded",
    "Check out in the container": "succeeded",
    "Install the Processes": "succeeded",
};
const failed = { ...passed, "Check out in the container": "failed" };
const fleetSteps = ["Check the inner fleet", "Keep the current seed", "Start the inner fleet", "Refresh the inner fleet"];
const restoreSteps = ["Restore the old seed", "Start on the old seed"];

Deno.test("a normal container deployment skips every fleet step, after success and after a failure", () => {
    for (const intent of [container, { deployment: { variant: "development" } }]) {
        for (const statuses of [passed, failed]) {
            for (const name of [...fleetSteps, ...restoreSteps]) assertEquals(skips(name, intent, statuses), true, name);
        }
    }
    // The seed still runs after the Processes when the refresh was skipped.
    assertEquals(skips("Take the seed snapshot", container, { ...passed, "Refresh the inner fleet": "skipped" }), false);
    assertEquals(step("Take the seed snapshot").dependsOn, [
        { step: "Install the Processes", condition: { type: "succeeded" } },
        { step: "Refresh the inner fleet", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
    ]);
});

Deno.test("a nested-fleet default first keeps its current seed, refreshes its fleet before the last seed, and restores nothing when every step passes", () => {
    for (const name of fleetSteps) assertEquals(skips(name, nested, passed), false, name);
    for (const name of restoreSteps) assertEquals(skips(name, nested, { ...passed, "Check the container": "succeeded" }), true, name);
    assertEquals(step("Start the inner fleet").dependsOn, [{ step: "Install the Processes", condition: { type: "succeeded" } }]);
    assertEquals(step("Refresh the inner fleet").dependsOn, [{ step: "Start the inner fleet", condition: { type: "succeeded" } }]);
    assert(step("Start the inner fleet").task.inputs.run!.includes("--tags fleet_start "));
    assert(step("Refresh the inner fleet").task.inputs.run!.includes("--tags fleet_refresh "));
    const names = deploy.map((s) => s.name);
    assert(names.indexOf("Install the Processes") < names.indexOf("Start the inner fleet"));
    // The run first keeps the default as it is, fleet included, so a restore never goes back past the fleet.
    assertEquals(step("Check the inner fleet").dependsOn, [{ step: "Start the container", condition: { type: "succeeded" } }]);
    assert(step("Check the inner fleet").task.inputs.run!.includes("--tags fleet_check "));
    assertEquals(step("Keep the current seed").dependsOn, [
        { step: "Start the container", condition: { type: "succeeded" } },
        { step: "Check the inner fleet", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
    ]);
    assert(names.indexOf("Start the container") < names.indexOf("Check the inner fleet"));
    assert(names.indexOf("Check the inner fleet") < names.indexOf("Keep the current seed"));
    assert(step("Keep the current seed").task.inputs.run!.includes("--tags seed "));
    assertEquals(step("Fetch into the mirror").dependsOn, [
        { step: "Start the container", condition: { type: "succeeded" } },
        { step: "Keep the current seed", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
    ]);
    assert(names.indexOf("Keep the current seed") < names.indexOf("Fetch into the mirror"));
    assert(names.indexOf("Refresh the inner fleet") < names.indexOf("Take the seed snapshot"));
});

Deno.test("a fleet that the check refuses changes nothing: every later step skips and no seed is restored", () => {
    const statuses = { "Write the container intent": "succeeded", "Start the container": "succeeded", "Check the inner fleet": "failed" };
    for (const name of ["Keep the current seed", "Fetch into the mirror", "Check out in the container", "Install the Processes",
        "Start the inner fleet", "Refresh the inner fleet", "Take the seed snapshot", ...restoreSteps]) {
        assertEquals(skips(name, nested, statuses), true, name);
    }
});

Deno.test("a nested-fleet deployment that failed after its checkout restores the old seed, starts through start and keeps the failed phase's result", () => {
    for (const failing of ["Refresh the inner fleet", "Start the inner fleet", "Take the seed snapshot", "Check out in the container"]) {
        const statuses = { ...passed, [failing]: "failed" };
        for (const name of fleetSteps.filter((n) => n !== failing)) assertEquals(skips(name, nested, statuses), true, name);
        for (const name of restoreSteps) assertEquals(skips(name, nested, statuses), false, `${name} after ${failing}`);
    }
    // Without the intent file there is nothing to run the restore with; a failed start or fetch changed nothing in the container.
    assertEquals(skips("Restore the old seed", nested, { "Write the container intent": "failed" }), true);
    for (const unchanged of ["Start the container", "Check the inner fleet", "Keep the current seed", "Fetch into the mirror"]) {
        assertEquals(skips("Restore the old seed", nested, { ...passed, [unchanged]: "failed" }), true, unchanged);
    }
    assertEquals(skips("Restore the old seed", nested, { ...passed, "Run dev deploy steps-0": "failed" }), false);
    const before = ["Start the container", "Check the inner fleet", "Keep the current seed", "Fetch into the mirror", "Check out in the container", "Run dev deploy steps",
        "Install the Processes", "Start the inner fleet", "Refresh the inner fleet", "Take the seed snapshot", "Check the container"];
    assertEquals(step("Restore the old seed").dependsOn, before.map((name) => ({ step: name, condition: { type: "always" } })));
    assertEquals(step("Start on the old seed").dependsOn, [{ step: "Restore the old seed", condition: { type: "succeeded" } }]);
    assert(step("Read the container result").dependsOn!.some((d) => d.step === "Start on the old seed" && d.condition.type === "always"));
    const restore = step("Restore the old seed").task.inputs.run!;
    assert(restore.includes("--tags fleet_restore "));
    assert(restore.startsWith('k="$ORBIT_RUN/failed.json"; cp -- "$ORBIT_RUN/result.json" "$k"'));
    const restart = step("Start on the old seed").task.inputs.run!;
    assert(restart.includes("--tags start "));
    assert(restart.includes('cp -- "$k" "$ORBIT_RUN/result.json"'));
});
