import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Condition = { type: string; conditions?: Condition[] };
type Edge = { step: string; condition?: Condition };
type Step = { name: string; guard?: string; allowFailure?: boolean; writes?: boolean; dependsOn?: Edge[]; task?: { type?: string; expr?: string } };
type Workflow = { jobs: { name: string; steps: Step[] }[] };

const workflows = new URL("../workflows/", import.meta.url);

async function steps(file: string): Promise<Step[]> {
    const workflow = parse(await Deno.readTextFile(new URL(file, workflows))) as Workflow;
    return workflow.jobs.flatMap((job) => job.steps);
}

type Status = "succeeded" | "failed" | "skipped";

function holds(condition: Condition | undefined, dependency: Status): boolean {
    switch (condition?.type ?? "succeeded") {
        case "succeeded": return dependency === "succeeded";
        case "failed": return dependency === "failed";
        case "skipped": return dependency === "skipped";
        case "always": return true;
        case "or": return (condition?.conditions ?? []).some((inner) => holds(inner, dependency));
        case "and": return (condition?.conditions ?? []).every((inner) => holds(inner, dependency));
        default: throw new Error(`unknown condition ${condition?.type}`);
    }
}

/**
 * A run of one job by the rules of the dependency edges: a step runs when every edge holds for the final status of its dependency, and is skipped otherwise.
 * `guarded` names the steps whose guard is false; `failing` names the steps that fail when they run.
 */
function run(job: Step[], failing: string[], guarded: string[]): Map<string, Status> {
    const status = new Map<string, Status>();
    for (let progress = true; progress && status.size < job.length;) {
        progress = false;
        for (const step of job) {
            if (status.has(step.name)) continue;
            const edges = step.dependsOn ?? [];
            if (!edges.every((edge) => status.has(edge.step))) continue;
            const ready = edges.every((edge) => holds(edge.condition, status.get(edge.step)!));
            status.set(step.name, !ready || guarded.includes(step.name) ? "skipped" : failing.includes(step.name) ? "failed" : "succeeded");
            progress = true;
        }
    }
    assertEquals(status.size, job.length, "every step must be reachable by its edges");
    return status;
}

const failedJob = (job: Step[], status: Map<string, Status>) => job.some((step) => status.get(step.name) === "failed" && !step.allowFailure);

/** The steps of the source job that a run without a Route, a profile refusal or a Project setup leaves out. */
const SOURCE_GUARDED = ["access-${{ self._index }}", "composer", "deps_mint", "deps_clear", "deps_write_composer", "deps_write_npm", "setup-${{ self._index }}", "proof_club_directory", "proof_club_previous", "proof_club"];
/** The steps that report in the receipt and may fail without ending the source job; every other step ends it. */
const REPORTED = ["profile", "deps_mint", "deps_clear", "deps_write_composer", "deps_write_npm", "setup-${{ self._index }}", "deps_remove_files", "deps_revoke", "setup_copy", "setup_output", "proof_club_directory", "proof_club_previous", "proof_club", "cleanup", "cleanup_remote"];

Deno.test("a failed step of the checkout ends the source job; only the reporting steps are allowFailure", async () => {
    const source = await steps("workflow-task-workspace-source.yaml");
    assertEquals(source.filter((step) => step.allowFailure).map((step) => step.name).sort(), [...REPORTED].sort());
    for (const failing of ["guest", "directory", "control", "mint_clone", "clone", "bundle", "prepare_remote", "copy_bundle", "import", "origin", "branch", "identity", "inspect"]) {
        const status = run(source, [failing], SOURCE_GUARDED);
        assertEquals(status.get(failing), "failed");
        assert(failedJob(source, status), `${failing} must fail the source job`);
        // Nothing that needs the checkout starts after it, and the receipt and the cleanups still run.
        assertEquals(status.get("profile"), "skipped", `${failing}: the profile must not run on a missing checkout`);
        assertEquals(status.get("result"), "succeeded");
        assertEquals(status.get("cleanup"), "succeeded");
    }
});

Deno.test("a failed import ends the provision before the converge and the fleet start", async () => {
    const source = await steps("workflow-task-workspace-source.yaml");
    const provision = await steps("workflow-operation-task-provision.yaml");
    // The source job fails, so its step in the provision fails: the run is the child's.
    const child = run(source, ["import"], SOURCE_GUARDED);
    assert(failedJob(source, child));
    const slotGuards = ["Start the slot", "Wait for the slot agent", "Wait for the slot boot", "Hold the Route Nodes", "Route the workspace", "Release the Route Nodes", "Create, start Processes"];
    const status = run(provision, ["Set up the workspace"], slotGuards);
    assertEquals(status.get("Set up the workspace"), "failed");
    for (const name of ["Accept the source", "Pin the converge", "Converge once", "Find the fleet guest", "Look for ready copies", "Start the nested fleet", "Bind the workspace keys"]) {
        assertEquals(status.get(name), "skipped", `${name} must not run after a failed source`);
    }
    // The receipt is still printed and the run still fails.
    assertEquals(status.get("Check the workspace"), "succeeded");
    assertEquals(status.get("Accept the workspace"), "succeeded");
});

Deno.test("a receipt with an error code ends the provision at the gate, before the converge and the fleet start", async () => {
    const provision = await steps("workflow-operation-task-provision.yaml");
    const slotGuards = ["Start the slot", "Wait for the slot agent", "Wait for the slot boot", "Hold the Route Nodes", "Route the workspace", "Release the Route Nodes", "Create, start Processes"];
    // The reporting steps (setup, profile) end the source job as succeeded; the gate fails on the error code of the receipt.
    const status = run(provision, ["Accept the source"], slotGuards);
    assertEquals(status.get("Set up the workspace"), "succeeded");
    for (const name of ["Pin the converge", "Converge once", "Find the fleet guest", "Look for ready copies", "Start the nested fleet", "Bind the workspace keys"]) {
        assertEquals(status.get(name), "skipped", `${name} must not run after a refused receipt`);
    }
    // The control run: a good source reaches the fleet start.
    const good = run(provision, [], slotGuards);
    assertEquals(good.get("Start the nested fleet"), "succeeded");
});

Deno.test("only the gate waits for the source, and it fails on the error code of the receipt", async () => {
    const provision = await steps("workflow-operation-task-provision.yaml");
    const gate = provision.find((step) => step.name === "Accept the source");
    assert(gate, "the provision needs the step Accept the source");
    assertEquals(gate.task?.type, "assert");
    assert(gate.task?.expr?.includes('contains("\\"error_code\\"")'), "the gate reads the error code of the receipt");
    assertEquals((gate.dependsOn ?? []).map((edge) => [edge.step, edge.condition?.type]), [["Set up the workspace", "succeeded"]]);
    // No other step may wait for the source except to report on it: a plain edge to it would start work on a refused workspace.
    for (const step of provision) {
        if (step.name === "Accept the source") continue;
        for (const edge of step.dependsOn ?? []) {
            if (edge.step !== "Set up the workspace") continue;
            assertEquals(edge.condition?.type, "always", `${step.name} waits for the source without the gate`);
            assertEquals(step.name, "Check the workspace");
        }
    }
    // The steps that start work on the workspace wait for the gate.
    for (const name of ["Pin the converge", "Bind the workspace keys", "Hold the Route Nodes", "Find the fleet guest", "Start the nested fleet"]) {
        const edges = provision.find((step) => step.name === name)!.dependsOn ?? [];
        assert(edges.some((edge) => edge.step === "Accept the source" && edge.condition?.type === "succeeded"), `${name} must wait for Accept the source`);
    }
});

Deno.test("the receipt names a failed source and not the VM", async () => {
    const text = await Deno.readTextFile(new URL("workflow-operation-task-provision.yaml", workflows));
    assert(text.includes('steps["Set up the workspace"].status == "failed"'), "a failed source step needs its own failed_step");
    assert(text.includes('failed_step\\":\\"source\\"'));
});

Deno.test("the steps that cannot run twice declare writes, so a dispatch that a worker loses is not run again", async () => {
    // Swamp re-runs a dispatch lost to a worker disconnect only when the step does not write (try 13: the import ran twice and the second git clone exited 128).
    const source = await steps("workflow-task-workspace-source.yaml");
    for (const name of ["clone", "import", "branch", "setup-${{ self._index }}"]) {
        assertEquals(source.find((step) => step.name === name)?.writes, true, `${name} must declare writes: true`);
    }
});

Deno.test("a failed checkout step still removes and revokes the dependency token", async () => {
    const source = await steps("workflow-task-workspace-source.yaml");
    // A Project with a setup step: the token is minted and cleared before the checkout, and the guards of the dependency steps are false only for the writes.
    const guarded = SOURCE_GUARDED.filter((name) => !["deps_mint", "deps_clear", "setup-${{ self._index }}"].includes(name));
    for (const failing of ["clone", "import", "branch", "access-${{ self._index }}", "inspect"]) {
        const withRows = guarded.filter((name) => name !== "access-${{ self._index }}");
        const status = run(source, [failing], withRows);
        assertEquals(status.get(failing), "failed");
        assert(failedJob(source, status));
        for (const name of ["deps_remove_files", "deps_revoke", "result", "cleanup", "cleanup_remote"]) {
            assertEquals(status.get(name), "succeeded", `${failing}: ${name} must still run`);
        }
        // The Project setup does not start on a checkout that failed.
        assertEquals(status.get("setup-${{ self._index }}"), "skipped");
    }
});
