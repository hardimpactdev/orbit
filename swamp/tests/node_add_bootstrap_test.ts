/** Enrollment trust uses the pinned official SSH transport; no host connection is made here. */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.11";
import { effectiveHosts } from "../.swamp/pulled-extensions/@swamp/ssh/models/_lib/hosts.ts";
import { buildExecArgv } from "../.swamp/pulled-extensions/@swamp/ssh/models/_lib/runner.ts";
import { GlobalArgsSchema } from "../.swamp/pulled-extensions/@swamp/ssh/models/_lib/schemas.ts";

const workflow = parse(
    await Deno.readTextFile(
        new URL(
            "../workflows/workflow-operation-node-add.yaml",
            import.meta.url,
        ),
    ),
) as {
    jobs: {
        name: string;
        dependsOn?: { job: string; condition: { type: string } }[];
        steps: {
            name: string;
            guard?: string;
            writes?: boolean;
            task: {
                type: string;
                expr?: string;
                inputs?: Record<string, unknown>;
                globalArgs: Record<string, unknown> & {
                    transport: Record<string, unknown>;
                    hosts?: {
                        address: string;
                        transport?: { hostKeys?: string };
                    }[];
                };
            };
        }[];
    }[];
};
const steps = workflow.jobs.flatMap((job) => job.steps);

Deno.test("first host-key read bypasses worker pins, while later bootstrap connections require the approved key", () => {
    const scan = steps.find((step) => step.name === "Read the host key")!;
    const globals = GlobalArgsSchema.parse({
        name: "node-add-test",
        transport: {
            ...scan.task.globalArgs.transport,
            user: "root",
            port: 22,
            identityContent: undefined,
        },
        hosts: [{ name: "target", address: "192.0.2.9" }],
    });
    const argv = buildExecArgv(
        effectiveHosts(globals)[0],
        "cat /etc/ssh/ssh_host_ed25519_key.pub",
        {
            binaries: {
                ssh: "ssh",
                scp: "scp",
                rsync: "rsync",
                tailscale: "tailscale",
                sshpass: "sshpass",
            },
            sendEnvKeys: [],
        },
    );

    assertEquals(argv.includes("StrictHostKeyChecking=no"), true);
    assertEquals(argv.includes("UserKnownHostsFile=/dev/null"), true);
    assertFalse(
        steps.filter((step) => step !== scan).some((step) =>
            step.task.globalArgs?.transport?.hostKeyPolicy === "insecure"
        ),
    );
});

Deno.test("approved fingerprint gates bootstrap mutations and pins every enrolled-host connection", () => {
    const approval = workflow.jobs.find((job) => job.name === "approval")!;
    assertEquals(approval.steps[0].name, "Require approved host key");
    assertEquals(approval.steps[0].task.type, "assert");
    assertEquals(
        approval.steps[0].task.expr,
        'steps["Read the host key"].outputs.fingerprint == inputs.intent.bootstrap.fingerprint',
    );
    assertEquals(approval.dependsOn, [{
        job: "host_key",
        condition: { type: "succeeded" },
    }]);
    function requiresApproval(name: string): boolean {
        if (name === "approval") return true;
        const job = workflow.jobs.find((candidate) => candidate.name === name)!;
        return (job.dependsOn ?? []).some((dependency) =>
            dependency.condition.type === "succeeded" &&
            requiresApproval(dependency.job)
        );
    }
    for (
        const job of workflow.jobs.slice(
            2,
            workflow.jobs.findIndex((job) => job.name === "cli"),
        )
    ) {
        assert(
            requiresApproval(job.name),
            `${job.name} must require fingerprint approval`,
        );
    }
    let pinned = 0;
    for (
        const step of steps.filter((step) => step.name !== "Read the host key")
    ) {
        for (const host of step.task.globalArgs?.hosts ?? []) {
            if (
                [
                    "${{ inputs.intent.bootstrap.host }}",
                    "${{ inputs.intent.address }}",
                ].includes(host.address.replace(/\s+/g, " "))
            ) {
                assertEquals(
                    host.transport?.hostKeys,
                    '${{ [steps["Read the host key"].outputs.publicKey] }}',
                    step.name,
                );
                pinned++;
            }
        }
    }
    assert(pinned > 0, "enrollment must pin its SSH hosts");
});

Deno.test("every Ansible phase is a bounded playbook run with pinned host keys and the shared environment", () => {
    const phases = steps.filter((step) =>
        String(step.task.inputs?.run ?? "").includes("ansible-playbook")
    );
    assertEquals(
        phases.map((step) => step.name),
        [
            "Set up the machine",
            "Set up the tunnel",
            "Add the peer",
            "Install the Node agent",
            "Configure DNS backend",
            "Check the Node state",
        ],
    );
    for (const step of phases) {
        assert(
            /^timeout [1-9][0-9]* \/opt\/orbit\/ansible\/venv\/bin\/ansible-playbook /.test(
                String(step.task.inputs?.run),
            ),
            `${step.name} must run under a literal timeout`,
        );
        assertEquals(step.guard !== undefined, true, `${step.name} needs a guard`);
        const env = step.task.inputs?.env as Record<string, string>;
        assert(
            env.ANSIBLE_SSH_ARGS.includes("UserKnownHostsFile=/tmp/orbit-ansible-"),
            `${step.name} must use the per-run pins`,
        );
        assertFalse(
            JSON.stringify(env).includes("accept-new") ||
                JSON.stringify(env).includes("StrictHostKeyChecking=no"),
        );
    }
});

Deno.test("the Gateway reads the end check and the cleanup removes the private files", () => {
    const node = workflow.jobs.find((job) => job.name === "node")!;
    assertEquals(node.steps.map((step) => step.name), ["Check the Node"]);
    assertEquals(node.steps[0].task.type, "assert");
    const cleanup = workflow.jobs.find((job) => job.name === "cleanup")!;
    assertEquals(cleanup.steps.map((step) => step.name), ["Remove the playbook input"]);
    assertEquals(
        cleanup.dependsOn?.map((dependency) => dependency.job).sort(),
        workflow.jobs.map((job) => job.name).filter((name) => name !== "cleanup").sort(),
    );
});
