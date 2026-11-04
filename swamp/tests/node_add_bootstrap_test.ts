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
    const approval = workflow.jobs.find((job) => job.name === "phase_1")!;
    assertEquals(approval.steps[0].name, "Require approved host key");
    assertEquals(approval.steps[0].task.type, "assert");
    assertEquals(
        approval.steps[0].task.expr,
        'steps["Read the host key"].outputs.fingerprint == inputs.intent.bootstrap.fingerprint',
    );
    assertEquals(approval.dependsOn, [{
        job: "phase_0",
        condition: { type: "succeeded" },
    }]);
    function requiresApproval(name: string): boolean {
        if (name === "phase_1") return true;
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
    const pin = steps.find((step) => step.name === "Pin the bootstrap host")!;
    const content = String(pin.task.inputs?.stdin).replace(/\s+/g, " ");
    assertEquals(
        content,
        '${{ (inputs.intent.bootstrap.port == 22 ? inputs.intent.bootstrap.host : "["+inputs.intent.bootstrap.host+"]:"+string(inputs.intent.bootstrap.port))+" "+steps["Read the host key"].outputs.publicKey+"\\n"+inputs.intent.address+" "+steps["Read the host key"].outputs.publicKey+"\\n" }}',
    );
    assertEquals(
        pin.task.globalArgs.hosts?.[0].transport?.hostKeys,
        "${{ inputs.intent.control_ssh.target.host_keys }}",
    );
});

Deno.test("bootstrap key guard skips grep success and leaves absence to append", () => {
    // Swamp's truthy guard means already done, documented in execution-semantics.md.
    assertEquals(
        steps.find((step) => step.name === "Authorize the control key")!.guard,
        '${{ steps["Read bootstrap keys-0"].outputs.exitCode == 0 }}',
    );
});

Deno.test("observer starts only after its declared environment group is created", () => {
    const group = steps.find((step) => step.name === "Create observer group");
    assertEquals(
        group?.task.globalArgs.groupname,
        "${{ inputs.intent.agent.environment_group }}",
    );
    assertEquals(group?.task.globalArgs.ensure, "present");
    assertEquals(
        workflow.jobs.find((job) => job.name === "phase_30")?.dependsOn?.map((
            dependency,
        ) => dependency.job),
        ["observer_group"],
    );
});
