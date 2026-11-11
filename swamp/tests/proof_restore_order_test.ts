import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    guard?: string;
    dependsOn?: {
        step: string;
        condition: { type: string; conditions?: { type: string }[] };
    }[];
    task: { workflowIdOrName?: string; inputs?: { argv?: string[] } };
};
type Workflow = { jobs: { name: string; steps: Step[] }[] };
const read = async (path: string) =>
    parse(await Deno.readTextFile(new URL(path, import.meta.url)));
const workflow = async (name: string) =>
    await read(`../workflows/workflow-${name}.yaml`) as Workflow;
const stepsOf = (flow: Workflow) => flow.jobs.flatMap((job) => job.steps);
const named = (steps: Step[], name: string) => {
    const step = steps.find((step) => step.name === name);
    assert(step, `missing step: ${name}`);
    return step;
};
// Check required success edges, rather than YAML list order: independent steps can run together.
function requiresSuccess(
    steps: Step[],
    after: string,
    before: string,
): boolean {
    return named(steps, after).dependsOn?.some((dependency) =>
        dependency.condition.type === "succeeded" &&
        (dependency.step === before ||
            requiresSuccess(steps, dependency.step, before))
    ) ?? false;
}
const nestedApi = (step: Step) =>
    JSON.stringify(step.task).includes("/api/v1/") ||
    step.task.workflowIdOrName === "task-nested-ready";

Deno.test("restored fleets require checkout migration before every nested Gateway API check", async () => {
    const restore = stepsOf(await workflow("proof-fleet-restore"));
    const apiChecks = restore.filter(nestedApi);
    assert(apiChecks.length > 0);
    for (const check of apiChecks) {
        assert(
            requiresSuccess(restore, check.name, "Apply the checkout"),
            `${check.name} can read the base schema`,
        );
    }
    const order = [
        "Restore the base snapshot",
        "Start the containers",
        "Read the container state",
        "Check the containers run",
        "Wait for cloud-init",
        "Apply the checkout",
    ];
    for (let i = 1; i < order.length; i++) {
        assert(
            requiresSuccess(restore, order[i], order[i - 1]),
            `${order[i]} must follow ${order[i - 1]}`,
        );
    }
    assertEquals(
        named(restore, "Apply the checkout").task.workflowIdOrName,
        "task-nested-apply",
    );
    const apply = stepsOf(await workflow("task-nested-apply"));
    assert(named(apply, "Migrate").task.inputs?.argv?.includes("migrate"));
    assert(requiresSuccess(apply, "Restart the services", "Migrate"));
    assert(
        requiresSuccess(apply, "Check the services", "Restart the services"),
    );
    assert(
        !apply.some(nestedApi),
        "apply must not require an unmigrated Gateway API",
    );
});

Deno.test("renewed base snapshots check readiness only after the apply that migrates", async () => {
    const prepare =
        (await workflow("proof-fleet-verify")).jobs.find((job) =>
            job.name === "prepare"
        )!.steps;
    const apply = named(prepare, "Re-mint the Swamp tokens");
    const ready = named(prepare, "Check readiness");
    const snapshot = named(prepare, "Take base again");
    assertEquals(apply.task.workflowIdOrName, "task-nested-apply");
    assertEquals(
        ready.guard,
        apply.guard,
        "readiness must never run when the apply was skipped",
    );
    assertEquals(snapshot.guard, apply.guard);
    const succeededOrSkipped = {
        type: "or",
        conditions: [{ type: "succeeded" }, { type: "skipped" }],
    };
    assertEquals(
        ready.dependsOn?.find((dependency) => dependency.step === apply.name)
            ?.condition,
        succeededOrSkipped,
    );
    assertEquals(
        snapshot.dependsOn?.find((dependency) => dependency.step === ready.name)
            ?.condition,
        succeededOrSkipped,
    );
    // Initial, batch, replay and final restores all use the fixed child.
    for (const name of ["proof-fleet-verify", "proof-fleet-verify-batch"]) {
        const fleetRestores = stepsOf(await workflow(name)).filter((step) =>
            step.task.workflowIdOrName === "proof-fleet-restore"
        );
        assert(fleetRestores.length > 0, name);
    }
});

type Task = {
    name: string;
    block?: Task[];
    "ansible.builtin.import_tasks"?: string;
    "ansible.builtin.command"?: { argv: string[] };
};
Deno.test("the first fleet build and image snapshots follow migration", async () => {
    const bootstrap = stepsOf(await workflow("gateway-bootstrap"));
    assert(
        requiresSuccess(
            bootstrap,
            "Bootstrap the Gateway",
            "Migrate the database",
        ),
    );
    assert(
        named(bootstrap, "Migrate the database").task.inputs?.argv?.includes(
            "migrate",
        ),
    );
    const build = await read(
        "../ansible/tasks/task-vm-fleet-build.yml",
    ) as Task[];
    const index = (name: string) => {
        const i = build.findIndex((task) => task.name === name);
        assert(i >= 0, name);
        return i;
    };
    assert(index("Prepare the Gateway") < index("Admit the Gateway Node"));
    assert(index("Admit the Gateway Node") < index("Check the nested fleet"));
    assert(
        build[index("Prepare the Gateway")]["ansible.builtin.command"]?.argv
            .includes("gateway-bootstrap"),
    );
    const fleet = await read("../ansible/tasks/task-vm-fleet.yml") as Task[];
    const code = fleet.find((task) => task.name === "Take the new code")!
        .block!;
    const migrate = code.findIndex((task) =>
        task["ansible.builtin.command"]?.argv.includes("migrate")
    );
    const restart = code.findIndex((task) =>
        task.name === "Restart the long-running services"
    );
    assert(migrate >= 0 && restart > migrate);
    const role = await read("../ansible/task-vm-role.yml") as {
        tasks: Task[];
    }[];
    assert(
        role[0].tasks.some((task) =>
            task["ansible.builtin.import_tasks"] === "tasks/task-vm-fleet.yml"
        ),
    );
    assert(
        role[1].tasks.some((task) => task.name === "Take the base snapshots"),
    );
});
