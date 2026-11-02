import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { footprintWorkflow } from "./footprint_workflows.ts";
import { SwampWorkers, SwampWorkersRemoval } from "./role_swamp_workers.ts";

type Step = {
    name: string;
    writes?: boolean;
    guard?: string;
    forEach?: unknown;
    task: {
        type: string;
        behaviorType?: string;
        methodName?: string;
        globalArgs?: Record<string, unknown>;
        inputs?: Record<string, unknown>;
        expr?: string;
    };
};
type Job = {
    name: string;
    dependsOn?: { job: string; condition: { type: string } }[];
    steps: Step[];
};

const SWAMP_WORKERS_PROGRAM = await Deno.readTextFile(
    new URL("../fleet-recipe/scripts/swamp_workers.py", import.meta.url),
);
const ssh = {
    control_node_id: 209,
    identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
    public_key: "ssh-ed25519 AAAA",
    target: {
        name: "target",
        host: "10.44.0.209",
        user: "orbit",
        port: 22,
        host_keys: ["ssh-ed25519 BBBB"],
    },
};
const intent = {
    role: "swamp",
    node_id: 209,
    agent_sha256: "a".repeat(64),
    assignment_id: 244,
    user: "orbit",
    workers: ["a", "b"],
    units: { serve: "[Unit]\n", worker: "[Unit]\n" },
    ssh_execution: ssh,
};
const jobs = (workflow: { jobs: unknown[] }) => workflow.jobs as Job[];
const steps = (all: Job[], prefix: string) =>
    all.flatMap((job) => job.steps).filter((step) =>
        step.name.startsWith(prefix)
    );

Deno.test("the swamp runtime intent carries the rendered units and SSH input, and no tuning fields", () => {
    assertEquals(SwampWorkers.parse(intent).units.serve, "[Unit]\n");
    assertThrows(() => SwampWorkers.parse({ ...intent, concurrency: 8 }));
    assertThrows(() => SwampWorkers.parse({ ...intent, workers: ["a", "a"] }));
    assertThrows(() => SwampWorkers.parse({ ...intent, workers: ["../x"] }));
    assertThrows(() =>
        SwampWorkers.parse({
            ...intent,
            ssh_execution: { ...ssh, identity_file: "/tmp/key" },
        })
    );
    const { assignment_id: _a, user: _u, units: _units, ...removal } = intent;
    assertThrows(() =>
        SwampWorkersRemoval.parse({ ...removal, purge_data: false })
    );
    const { ssh_execution: _s, ...plain } = removal;
    assertEquals(
        SwampWorkersRemoval.parse({ ...plain, purge_data: false }).workers,
        ["a", "b"],
    );
});

for (
    const [label, workflow] of [["add and converge", footprintWorkflow(true)], [
        "relocation",
        footprintWorkflow(true, true),
    ]] as const
) {
    Deno.test(`${label} applies the swamp runtime with official behaviors before the runtime stage and never restarts`, () => {
        const all = jobs(workflow);
        const swamp = steps(all, "swamp_");
        assertEquals(swamp.map((step) => step.name), [
            "swamp_identity",
            "swamp_serve",
            "swamp_worker_unit",
            "swamp_worker_${{ self.worker }}",
            "swamp_check_serve",
            "swamp_check_worker_unit",
            "swamp_check_worker_${{ self.worker }}",
            "swamp_check_identity",
            "swamp_proven",
        ]);
        // No shipped script and no restart: serve and running workers only start when stopped.
        assertEquals(
            swamp.some((step) =>
                step.task.methodName === "restart" ||
                step.task.behaviorType?.startsWith("@nckrtl/")
            ),
            false,
        );
        assertEquals(
            swamp.filter((step) =>
                step.task.methodName === "apply" ||
                step.task.methodName === "exec"
            ).every((step) => step.writes),
            true,
        );
        const serve = swamp.find((step) => step.name === "swamp_serve")!.task;
        assertEquals([
            serve.behaviorType,
            serve.methodName,
            serve.globalArgs?.ensure,
            serve.globalArgs?.enabled,
        ], [
            "@swamp/cfgmgmt/systemd",
            "apply",
            "running",
            true,
        ]);
        const template = swamp.find((step) =>
            step.name === "swamp_worker_unit"
        )!.task.globalArgs!;
        assertEquals([template.service, template.ensure, template.enabled], [
            "orbit-swamp-worker@.service",
            undefined,
            undefined,
        ]);
        const identity = swamp.find((step) => step.name === "swamp_identity")!
            .task.inputs!.argv as string[];
        // `-C` compares first, so a matching key is never rewritten under live SSH.
        assertEquals(identity.slice(0, 5), [
            "/usr/bin/sudo",
            "-n",
            "--",
            "/usr/bin/install",
            "-C",
        ]);
        assertEquals(identity.slice(-2), [
            "/etc/orbit/node-proxy/id_ed25519",
            "/etc/orbit/swamp-role-worker/ssh-identity",
        ]);
        // The behavior's check is the end check, read by one native assert.
        assertEquals(
            swamp.filter((step) => step.name.startsWith("swamp_check_")).every((
                step,
            ) => step.task.methodName === "check"),
            true,
        );
        assertEquals(swamp.at(-1)!.task.type, "assert");
        const runtime = all.find((job) => job.name === "runtime_job")!;
        assertEquals(runtime.dependsOn?.at(-1), {
            job: "swamp_proven_job",
            condition: { type: "always" },
        });
        const failure = String(
            runtime.steps.find((step) => step.name === "runtime")!.task.inputs!
                .swamp_failure,
        );
        for (
            const code of [
                "swamp.ssh_identity_failed",
                "swamp.serve_unit_failed",
                "swamp.worker_unit_failed",
                "swamp.runtime_check_failed",
            ]
        ) {
            assertEquals(failure.includes(code), true, code);
        }
    });
}

Deno.test("grants mode renders the Gateway manifest and serve's other grants from the repository", async () => {
    const repo = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${repo}/config`);
        const manifest = new URL(
            "../../../../config/gateway-workflow-grants.json",
            import.meta.url,
        );
        await Deno.copyFile(
            manifest,
            `${repo}/config/gateway-workflow-grants.json`,
        );
        const serve = new URL(
            "../../../../config/serve-grants.json",
            import.meta.url,
        );
        await Deno.copyFile(serve, `${repo}/config/serve-grants.json`);
        const result = await new Deno.Command("python3", {
            args: [
                "-I",
                new URL(
                    "../fleet-recipe/scripts/swamp_workers.py",
                    import.meta.url,
                ).pathname,
                "grants",
                repo,
            ],
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        const source = JSON.parse(await Deno.readTextFile(manifest));
        const grants = JSON.parse(
            await Deno.readTextFile(`${repo}/grants/orbit-gateway.yaml`),
        ).grants;
        assertEquals(
            grants.length,
            source.vaults.length + source.workflows.length,
        );
        assertEquals(
            grants.every((grant: { subject: string }) =>
                grant.subject === "user:orbit-gateway"
            ),
            true,
        );
        assertEquals(
            grants.find((grant: { resource: string }) =>
                grant.resource === "workflow:schedule-*"
            ).actions,
            ["run", "read", "write"],
        );
        assertEquals(
            grants.some((grant: { condition?: string }) =>
                grant.condition?.includes("^schedule-command-")
            ),
            true,
        );
        const others = JSON.parse(
            await Deno.readTextFile(`${repo}/grants/orbit-serve.yaml`),
        ).grants;
        assertEquals(others, JSON.parse(await Deno.readTextFile(serve)).grants);
        // Native resource outputs have generated keys, separate from Gateway execution-input copies.
        const outputs = others.filter((grant: { subject: string }) =>
            grant.subject === "user:orbit-gateway"
        );
        assertEquals(outputs.length, 1);
        const output = outputs[0];
        assertEquals(output.resource, "vault:orbit-control-inputs");
        assertEquals(output.effect, "allow");
        assertEquals(output.actions, ["read", "write"]);
        const expression = /^key\.matches\((.+)\)$/.exec(output.condition);
        if (!expression) {
            throw new Error(
                "Native output grants must be scoped to their generated keys",
            );
        }
        const pattern = new RegExp(JSON.parse(expression[1]));
        const prefix =
            "swamp-cfgmgmt-docker_container-75e257b4-7510-425b-867e-a25f62829390-";
        for (const method of ["apply", "check"]) {
            assertEquals(
                pattern.test(
                    prefix + method + "-state-10.231.0.10-current.env",
                ),
                true,
            );
        }
        for (
            const key of [
                "process-create-operation-17",
                "schedule-command-c1bb62f0-a2d9-48ae-92b1-740fa3619933",
                prefix + "restart-state-10.231.0.10-current.env",
                prefix + "check-state-10.231.0.10-current.volumes",
                "swamp-cfgmgmt-file-75e257b4-7510-425b-867e-a25f62829390-check-state-10.231.0.10-current.env",
            ]
        ) assertEquals(pattern.test(key), false, key);
    } finally {
        await Deno.remove(repo, { recursive: true });
    }
});

Deno.test("the release restarts serve only for a shipped file serve did not start with (Lab #4282)", async () => {
    const repo = await Deno.makeTempDir();
    const git = async (...args: string[]) => {
        const result = await new Deno.Command("git", {
            args,
            cwd: repo,
            stdout: "piped",
            stderr: "piped",
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        return new TextDecoder().decode(result.stdout).trim();
    };
    const manifest = async (dir: string, files: string[]) => {
        await Deno.mkdir(`${repo}/swamp/extensions/${dir}`, {
            recursive: true,
        });
        await Deno.writeTextFile(
            `${repo}/swamp/extensions/${dir}/manifest.yaml`,
            `name: ${dir}\nadditionalFiles:\n${
                files.map((f) => `  - ${f}\n`).join("")
            }`,
        );
    };
    const commit = async () => {
        await git("add", "-A");
        await git(
            "-c",
            "user.name=t",
            "-c",
            "user.email=t@t",
            "commit",
            "-qm",
            "c",
        );
        return await git("rev-parse", "HEAD");
    };
    try {
        await git("init", "-q");
        await manifest("a", ["scripts/one.py"]);
        await manifest("b", ["scripts/two.py"]);
        const served = await commit();
        await Deno.writeTextFile(`${repo}/swamp/extensions/b/other.ts`, "");
        const same = await commit();
        await manifest("a", ["scripts/one.py", "scripts/three.py"]);
        const changed = await commit();
        // A removed entry needs no restart: serve serves a listed file that is gone as missing.
        await manifest("b", []);
        const removed = await commit();
        const program = SWAMP_WORKERS_PROGRAM.split(
            "if __name__ == '__main__':",
        )[0];
        const result = await new Deno.Command("python3", {
            args: [
                "-I",
                "-c",
                `${program}\nprint(json.dumps([added(additional_files(sys.argv[1]), additional_files(c)) for c in sys.argv[2:]]))`,
                served,
                same,
                changed,
                removed,
            ],
            cwd: repo,
        }).output();
        assertEquals(
            result.success,
            true,
            new TextDecoder().decode(result.stderr),
        );
        assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), [
            [],
            ["swamp/extensions/a/manifest.yaml"],
            ["swamp/extensions/a/manifest.yaml"],
        ]);
    } finally {
        await Deno.remove(repo, { recursive: true });
    }
    for (
        const part of [
            "'HEAD@{' + started + '}'",
            "'ExecMainStartTimestamp'",
            "'--on-active=5s'",
            "https://swamp-club.com/lab/4282",
        ]
    ) assertEquals(SWAMP_WORKERS_PROGRAM.includes(part), true, part);
});
