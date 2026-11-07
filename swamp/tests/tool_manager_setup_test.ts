/** The Tool Manager setup is a few native reads around one Ansible phase, and the managers of a role run side by side. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../bin/gate-test-support.ts";

type Step = {
    name: string;
    guard?: string;
    concurrency?: number;
    task: { type: string; inputs?: { run?: string } };
};
type Job = {
    name: string;
    dependsOn?: { job: string }[];
    steps: Step[];
};
const load = async (path: string) =>
    parse(await Deno.readTextFile(`${ROOT}/${path}`));
const setup = await load("workflows/workflow-operation-tool-manager-setup.yaml") as {
    jobs: Job[];
};
const steps = setup.jobs.flatMap((job) => job.steps);

Deno.test("the setup keeps its few native reads and one Ansible phase", () => {
    assertEquals(setup.jobs.map((job) => job.name), [
        "prepare",
        "inspect",
        "plan",
        "intent",
        "input",
        "manager",
        "setup",
        "cleanup",
    ]);
    assertEquals(steps.length, 9);
    const phase = steps.filter((step) =>
        step.task.inputs?.run?.includes("ansible-playbook")
    );
    assertEquals(phase.map((step) => step.name), ["Set up the Tool Manager"]);
    assert(
        /^timeout [1-9][0-9]* \/opt\/orbit\/ansible\/venv\/bin\/ansible-playbook .* --tags manager /
            .test(phase[0].task.inputs!.run!),
    );
});

Deno.test("a ready manager runs no Ansible, and the cleanup follows every job", () => {
    const guard = steps.find((step) => step.name === "Plan pinned manager")!
        .guard!;
    for (
        const name of [
            "Write the intent",
            "Write the playbook input",
            "Set up the Tool Manager",
        ]
    ) {
        assertEquals(steps.find((step) => step.name === name)!.guard, guard);
    }
    assert(guard.includes('exitCode == 127'));
    assert(guard.includes("inputs.converge"));
    const cleanup = setup.jobs.find((job) => job.name === "cleanup")!;
    assertEquals(
        cleanup.dependsOn!.map((dependency) => dependency.job).sort(),
        setup.jobs.map((job) => job.name).filter((name) => name !== "cleanup")
            .sort(),
    );
});

Deno.test("the playbook applies the plan in the order the resources need", async () => {
    const [play] = await load("ansible/operation-tool-manager-setup.yml") as {
        hosts: string;
        tags: string[];
        tasks: { name: string }[];
    }[];
    assertEquals([play.hosts, play.tags], ["manager_node", ["manager"]]);
    const names = play.tasks.map((task) => task.name);
    const before = (first: string, second: string) =>
        assert(
            names.indexOf(first) >= 0 &&
                names.indexOf(first) < names.indexOf(second),
            `${first} comes before ${second}`,
        );
    before("Create the manager folders", "Write the manager files");
    before("Write the manager files", "Download the artifacts that have a SHA-256 pin");
    before("Download the artifacts that have a SHA-512 pin", "Require the pinned packages");
    before("Require the pinned packages", "Refresh the pinned APT indexes");
    before("Refresh the pinned APT indexes", "Install the pinned APT set");
    before("Extract the pinned archives", "Publish the pinned trees and binaries");
    before("Publish the pinned trees and binaries", "Link the manager executables");
    before("Link the manager executables", "Install the locked JavaScript packages");
    before("Set the default Node version", "Publish the manager launchers");
    before("Publish the manager launchers", "Check the manager");
});

Deno.test("the managers of a role are set up side by side", async () => {
    const roleAdd = await load("workflows/workflow-operation-node-role-add.yaml") as {
        jobs: Job[];
    };
    const step = roleAdd.jobs.flatMap((job) => job.steps).find((step) =>
        step.name === "Set up Tool Managers"
    )!;
    assertEquals(step.concurrency, undefined);
});
