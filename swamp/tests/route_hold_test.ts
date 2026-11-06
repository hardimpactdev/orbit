import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Edge = { step: string; condition?: { type?: string } };
type Step = {
    name: string;
    guard?: string;
    dependsOn?: Edge[];
    task?: { behaviorType?: string; inputs?: { run?: string; env?: Record<string, string> } };
};
type Workflow = { jobs: { steps: Step[] }[] };

const workflows = new URL("../workflows/", import.meta.url);

/** The parents that hold the Route-serving Nodes for their Route-publish step only, with the name of each window. */
const windows: Record<string, { hold: string; route: string; release: string }[]> = {
    "task-provision": [{ hold: "Hold the Route Nodes", route: "Route the workspace", release: "Release the Route Nodes" }],
    "task-cancel": [{ hold: "Hold the Route Nodes", route: "Withdraw the Route", release: "Release the Route Nodes" }],
    "instance-register": [{ hold: "Hold the Route Nodes", route: "Create the Route", release: "Release the Route Nodes" }],
    "cluster-router": [
        { hold: "Hold the Route Nodes", route: "Install certs, publish", release: "Release the Route Nodes" },
        { hold: "Hold the Nodes to retire", route: "Retire the old Router", release: "Release the retired Nodes" },
    ],
    "cluster-router-unset": [{ hold: "Hold the Route Nodes", route: "Withdraw the Router sites", release: "Release the Route Nodes" }],
};

async function steps(name: string): Promise<Step[]> {
    const workflow = parse(await Deno.readTextFile(new URL(`workflow-operation-${name}.yaml`, workflows))) as Workflow;
    return workflow.jobs.flatMap((job) => job.steps);
}
const parents = (step: Step) => (step.dependsOn ?? []).map((edge) => edge.step);

Deno.test("every Route-publish step is wrapped in the same hold and release calls, and runs only after its hold succeeded", async () => {
    const holdRuns = new Set<string>();
    const releaseRuns = new Set<string>();
    for (const [name, list] of Object.entries(windows)) {
        const all = await steps(name);
        const byName = new Map(all.map((step) => [step.name, step]));
        for (const { hold, route, release } of list) {
            const [holdStep, routeStep, releaseStep] = [byName.get(hold), byName.get(route), byName.get(release)];
            assert(holdStep && routeStep && releaseStep, `${name}: needs ${hold}, ${route} and ${release}`);
            assertEquals(holdStep.task?.behaviorType, "command/shell");
            holdRuns.add(String(holdStep.task?.inputs?.run));
            releaseRuns.add(String(releaseStep.task?.inputs?.run));
            assert(String(holdStep.task?.inputs?.env?.ORBIT_HOLD_URL).includes("/route-hold"), `${name}/${hold}: URL`);
            assert(String(releaseStep.task?.inputs?.env?.ORBIT_HOLD_URL).includes("/route-release"), `${name}/${release}: URL`);
            // The Route step waits for a granted hold; a skipped hold skips it too.
            const edge = (routeStep.dependsOn ?? []).find((candidate) => candidate.step === hold);
            assertEquals(edge?.condition?.type ?? null, "succeeded", `${name}/${route} must depend on ${hold} succeeding`);
            // The release runs whatever the Route step did, and only when the hold was asked for.
            const back = (releaseStep.dependsOn ?? []).find((candidate) => candidate.step === route);
            assertEquals(back?.condition?.type, "always", `${name}/${release} must follow ${route} in every case`);
            assert(String(releaseStep.guard).includes(`"${hold}" in steps`), `${name}/${release} must be skipped with its hold`);
        }
    }
    assertEquals(holdRuns.size, 1, "every hold step runs the same command");
    assertEquals(releaseRuns.size, 1, "every release step runs the same command");
});

Deno.test("the hold command asks again while the Gateway answers busy, and sends only the run", async () => {
    const all = await steps("task-provision");
    const hold = String(all.find((step) => step.name === "Hold the Route Nodes")!.task?.inputs?.run);
    const release = String(all.find((step) => step.name === "Release the Route Nodes")!.task?.inputs?.run);
    // curl retries a 423 only with --retry-all-errors, and fails the step when the Gateway never grants the hold.
    for (const flag of ["--fail", "--proto =https", "--retry 5", "--retry-all-errors", '--data "$ORBIT_HOLD_REQUEST"']) {
        assert(hold.includes(flag), `the hold command must have ${flag}`);
    }
    assert(release.includes("--retry 2") && release.includes("--fail"), "the release command retries twice");
    const env = all.find((step) => step.name === "Hold the Route Nodes")!.task?.inputs?.env ?? {};
    assertEquals(Object.keys(env).sort(), ["ORBIT_HOLD_REQUEST", "ORBIT_HOLD_URL"]);
    assert(String(env.ORBIT_HOLD_REQUEST).includes("run.id"), "the call carries the run ID");
});

Deno.test("a failed provision hold is reported as a failed Route, and its Processes do not start", async () => {
    const all = await steps("task-provision");
    const processes = all.find((step) => step.name === "Create, start Processes");
    assert(parents(processes!).includes("Hold the Route Nodes"), "Processes must wait for the hold");
    const check = all.find((step) => step.name === "Check the workspace")!;
    assert(String(check.task?.inputs?.run).includes('ORBIT_HOLD'), "the receipt must read a failed hold");
    assert(parents(check).includes("Hold the Route Nodes") && parents(check).includes("Release the Route Nodes"));
});

Deno.test("a failed cancel hold stops the end before any removal", async () => {
    const all = await steps("task-cancel");
    for (const name of ["Run teardown steps", "Destroy the task VM"]) {
        assert(parents(all.find((step) => step.name === name)!).includes("Hold the Route Nodes"), `${name} must wait for the hold`);
    }
});
