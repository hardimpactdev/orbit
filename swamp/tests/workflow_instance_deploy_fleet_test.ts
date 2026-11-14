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
const fleetSteps = ["Check the inner fleet", "Keep the current seed", "Refresh the inner fleet"];
const restoreSteps = ["Restore the old seed", "Start on the old seed", "Start the restored fleet"];

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

Deno.test("a nested-fleet default checks its running fleet once, keeps its current seed, refreshes the fleet before the last seed, and starts it no second time", () => {
    for (const name of fleetSteps) assertEquals(skips(name, nested, passed), false, name);
    for (const name of restoreSteps) assertEquals(skips(name, nested, { ...passed, "Check the container": "succeeded" }), true, name);
    // The fleet keeps running between releases: one check at the start, which also starts what is stopped, and no start after the
    // checkout or after the seed (docs/cli/instance/container/fleet-refresh.mmd).
    const names = deploy.map((s) => s.name);
    for (const gone of ["Start the inner fleet", "Start the refreshed fleet"]) assert(!names.includes(gone), gone);
    assertEquals(deploy.filter((s) => s.task.inputs?.run?.includes("--tags fleet_start ")).map((s) => s.name), ["Check the inner fleet"]);
    assertEquals(deploy.filter((s) => s.task.inputs?.run?.includes("--tags fleet_resume ")).map((s) => s.name), ["Start the restored fleet"]);
    assertEquals(step("Refresh the inner fleet").dependsOn, [{ step: "Install the Processes", condition: { type: "succeeded" } }]);
    assert(step("Refresh the inner fleet").task.inputs.run!.includes("--tags fleet_refresh "));
    assert(names.indexOf("Install the Processes") < names.indexOf("Refresh the inner fleet"));
    // The run first keeps the default as it is, fleet included, so a restore never goes back past the fleet.
    assertEquals(step("Check the inner fleet").dependsOn, [{ step: "Start the container", condition: { type: "succeeded" } }]);
    assert(step("Check the inner fleet").task.inputs.run!.includes("--tags fleet_start "));
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
        "Refresh the inner fleet", "Take the seed snapshot", ...restoreSteps]) {
        assertEquals(skips(name, nested, statuses), true, name);
    }
});

Deno.test("a nested-fleet deployment that failed after its checkout restores the old seed, starts through start and keeps the failed phase's result", () => {
    for (const failing of ["Refresh the inner fleet", "Install the Processes", "Take the seed snapshot", "Check the container", "Check out in the container"]) {
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
        "Install the Processes", "Refresh the inner fleet", "Take the seed snapshot", "Check the container"];
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

Deno.test("the deployment's one fleet check waits for no Doctor, and the refresh starts again what it stopped for base before the seed", () => {
    // The check runs `fleet_start` (instance-fleet-start.yml ends with instance-fleet-reach.yml, never the Doctor wait); readiness ends
    // the refresh, after its migrate and restart.
    const start = Deno.readTextFileSync(new URL("../ansible/tasks/instance-fleet-start.yml", import.meta.url));
    assert(start.includes("ansible.builtin.import_tasks: instance-fleet-reach.yml"));
    assert(!start.includes("instance-fleet-ready.yml"));
    // A retaken base starts its containers again inside the refresh, so a failed start fails the refresh and restores the old seed.
    const refresh = Deno.readTextFileSync(new URL("../ansible/tasks/instance-fleet-refresh.yml", import.meta.url));
    assert(refresh.includes("ansible.builtin.include_tasks: instance-fleet-resume.yml"));
    for (const name of restoreSteps) assertEquals(skips(name, nested, { ...passed, "Refresh the inner fleet": "failed" }), false, name);
    // The seed's result file stays the run's result: no step after the check runs a fleet phase on a passed run.
    const names = deploy.map((s) => s.name);
    assert(names.indexOf("Take the seed snapshot") < names.indexOf("Check the container"));
    assert(step("Read the container result").dependsOn!.some((d) => d.step === "Start the restored fleet" && d.condition.type === "always"));
    assert(!step("Read the container result").dependsOn!.some((d) => d.step === "Start the refreshed fleet"));
});

Deno.test("after a restore the restored fleet is started and waited for, and the first failure stays the run's result", () => {
    const statuses = { ...passed, "Refresh the inner fleet": "failed", "Restore the old seed": "succeeded", "Start on the old seed": "succeeded" };
    assertEquals(skips("Start the restored fleet", nested, statuses), false);
    assertEquals(step("Start the restored fleet").dependsOn, [{ step: "Start on the old seed", condition: { type: "succeeded" } }]);
    const names = deploy.map((s) => s.name);
    assert(names.indexOf("Start on the old seed") < names.indexOf("Start the restored fleet"));
    assert(step("Start the restored fleet").task.inputs.run!.includes("--tags fleet_resume "));
    assertEquals(skips("Start the restored fleet", container, statuses), true);
});
