/** Enrollment trust uses the pinned official SSH transport; no host connection is made here. */
import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
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
        dependsOn?: { job: string }[];
        steps: {
            name: string;
            guard?: string;
            task: {
                globalArgs: Record<string, unknown> & {
                    transport: Record<string, unknown>;
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
