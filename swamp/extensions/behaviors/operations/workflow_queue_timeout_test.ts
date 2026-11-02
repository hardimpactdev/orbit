import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

// F8 fixture roots. Follow their children too: each native workflow has its own defaults.
const roots = [
    "operation-database-read",
    "operation-database-write",
    "operation-database-user-create",
    "operation-database-server-close",
    "operation-database-connection-update",
    "operation-database-create",
    "operation-database-server-create",
    "operation-database-server-destroy",
    "operation-database-destroy",
    "operation-instance-create",
    "operation-instance-setup-direct",
    "operation-instance-destroy",
];

Deno.test("Fixture workflows and their children declare queueTimeout", async () => {
    type Workflow = {
        name: string;
        queueTimeout?: number;
        jobs: {
            steps: { task: { type: string; workflowIdOrName?: string } }[];
        }[];
    };
    const directory = new URL("../../../workflows/", import.meta.url);
    const workflows = new Map<string, Workflow>();
    for await (const file of Deno.readDir(directory)) {
        if (!file.name.endsWith(".yaml")) continue;
        const workflow = parse(
            await Deno.readTextFile(new URL(file.name, directory)),
        ) as Workflow;
        workflows.set(workflow.name, workflow);
    }
    const checked = new Set<string>();
    const pending: { name: string; expected?: number }[] = roots.map((
        name,
    ) => ({
        name,
        expected: name === "operation-instance-create"
            ? 10
            : name.startsWith("operation-instance-")
            ? 60
            : 30,
    }));
    for (const { name, expected } of pending) {
        const key = `${name}:${expected ?? "positive"}`;
        if (checked.has(key)) continue;
        checked.add(key);
        const workflow = workflows.get(name);
        assert(workflow, `Missing workflow ${name}`);
        assert(
            Number.isInteger(workflow.queueTimeout) &&
                (workflow.queueTimeout ?? 0) > 0,
            `${name} must declare a positive integer queueTimeout`,
        );
        if (expected !== undefined) {
            assertEquals(
                workflow.queueTimeout,
                expected,
                `${name} queueTimeout`,
            );
        }
        for (const job of workflow.jobs) {
            for (const { task } of job.steps) {
                if (task.type !== "workflow") continue;
                assert(task.workflowIdOrName, `${name} must name its child`);
                pending.push({
                    name: task.workflowIdOrName,
                    // Create children keep their own policies. The shared database
                    // child keeps the database family's limit when called by destroy.
                    expected:
                        task.workflowIdOrName.startsWith("operation-database-")
                            ? 30
                            : expected === 10
                            ? undefined
                            : expected,
                });
            }
        }
    }
});
