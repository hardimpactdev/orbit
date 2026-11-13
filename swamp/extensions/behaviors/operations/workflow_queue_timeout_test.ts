import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { resolveWorkflowTarget } from "../../../bin/gate-test-support.ts";

Deno.test("workflow targets resolve only anchored double-quoted CEL constants", () => {
    for (
        const target of [
            "route-withdraw",
            '${{ "route-withdraw" }}',
            '${{"route-withdraw"}}',
            '${{  "route-withdraw"  }}',
        ]
    ) {
        assertEquals(resolveWorkflowTarget(target), "route-withdraw");
    }
    assertEquals(
        resolveWorkflowTarget('${{ "workflow_1.name" }}'),
        "workflow_1.name",
    );
    for (
        const target of [
            "${{ 'route-withdraw' }}",
            "${{ inputs.workflow }}",
            '${{ "route-" + inputs.name }}',
            'prefix${{ "route-withdraw" }}',
            '${{ "route-withdraw" }}suffix',
            '${{ "" }}',
        ]
    ) {
        assertEquals(resolveWorkflowTarget(target), target);
    }
});

// F8 fixture roots. Follow their children too: each native workflow has its own defaults.
const roots = [
    "operation-database-read",
    "operation-database-write",
    "operation-database-import",
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
    const pending = [...roots];
    for (const name of pending) {
        if (checked.has(name)) continue;
        checked.add(name);
        const workflow = workflows.get(name);
        assert(workflow, `Missing workflow ${name}`);
        assert(
            Number.isInteger(workflow.queueTimeout) &&
                (workflow.queueTimeout ?? 0) > 0,
            `${name} must declare a positive integer queueTimeout`,
        );
        for (const job of workflow.jobs) {
            for (const { task } of job.steps) {
                if (task.type !== "workflow") continue;
                assert(task.workflowIdOrName, `${name} must name its child`);
                // A two-way choice between literal child names checks both children.
                const choice =
                    /^\$\{\{.*\?\s*"([\w.-]+)"\s*:\s*"([\w.-]+)"\s*\}\}$/.exec(
                        task.workflowIdOrName,
                    );
                pending.push(
                    ...(choice
                        ? [choice[1], choice[2]]
                        : [resolveWorkflowTarget(task.workflowIdOrName)]),
                );
            }
        }
    }
});
