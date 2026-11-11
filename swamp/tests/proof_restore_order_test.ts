import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = {
    name: string;
    guard?: string;
    forEach?: { item: string; in: string };
    dependsOn?: {
        step: string;
        condition: { type: string; conditions?: { type: string }[] };
    }[];
    task: {
        workflowIdOrName?: string;
        inputs?: { argv?: string[]; timeoutSec?: number };
    };
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
        "Wait for systemd boot",
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

for (
    const [label, flow, job, call] of [
        [
            "initial",
            "proof-fleet-verify",
            "prepare",
            "Restore the base snapshot",
        ],
        ["batch", "proof-fleet-verify-batch", "start", "restore"],
        [
            "replay",
            "proof-fleet-verify",
            "can-fail",
            "Restore the base snapshot",
        ],
        ["final", "proof-fleet-verify", "finish", "Restore the clean fleet"],
    ]
) {
    Deno.test(`${label} restore calls the shared proof-fleet-restore workflow`, async () => {
        const parent = await workflow(flow);
        const steps = parent.jobs.find((candidate) => candidate.name === job)
            ?.steps;
        assert(steps, `missing job: ${job}`);
        assertEquals(
            named(steps, call).task.workflowIdOrName,
            "proof-fleet-restore",
        );
    });
}

Deno.test("systemd boot and every required state mount gate apply", async () => {
    const restore = stepsOf(await workflow("proof-fleet-restore"));
    const wait = named(restore, "Wait for systemd boot");
    assert(requiresSuccess(restore, "Apply the checkout", wait.name));
    assert(requiresSuccess(restore, wait.name, "Wait for cloud-init"));
    assertEquals(wait.forEach, named(restore, "Wait for cloud-init").forEach);
    const args = wait.task.inputs!.argv!;
    assertEquals(args.slice(0, 4), ["timeout", "135", "incus", "exec"]);
    assertEquals(wait.task.inputs!.timeoutSec, 145);
    const apply = named(
        stepsOf(await workflow("task-nested-apply")),
        "Check the state",
    ).task.inputs!.argv!;
    assertEquals(
        args.slice(11),
        apply.slice(10),
        "wait checks exactly the mounts apply needs",
    );
});

// Execute only the guest shell with a fixture systemctl. No Incus or real systemd call runs.
for (const role of ["gateway", "app-dev"]) {
    Deno.test(`late ${role} state mounts pass after the boot wait and fail without it`, async () => {
        const wait = named(
            stepsOf(await workflow("proof-fleet-restore")),
            "Wait for systemd boot",
        ).task.inputs!.argv!;
        const apply = named(
            stepsOf(await workflow("task-nested-apply")),
            "Check the state",
        ).task.inputs!.argv!;
        const dir = await Deno.makeTempDir();
        let child: Deno.ChildProcess | undefined;
        try {
            await Deno.writeTextFile(
                `${dir}/systemctl`,
                `#!/bin/sh
case "$1" in
is-system-running)
  [ "$2" = --wait ] || { echo starting; exit 1; }
  echo waiting-for-boot >&2
  IFS= read -r state || exit 1
  printf '%s\n' "$state"
  [ "$state" = running ] ;;
is-active) test -f "$FIXTURE_MOUNTS/$3" ;;
*) exit 2 ;;
esac
`,
            );
            await Deno.chmod(`${dir}/systemctl`, 0o755);
            const env = {
                PATH: `${dir}:${Deno.env.get("PATH")}`,
                FIXTURE_MOUNTS: dir,
            };
            const check = () =>
                new Deno.Command("sh", { args: apply.slice(7), env }).output();
            child = new Deno.Command("sh", {
                args: ["-c", wait[8], "sh", role, ...wait.slice(11)],
                env,
                stdin: "piped",
                stdout: "piped",
                stderr: "piped",
            }).spawn();
            const reader = child.stderr.getReader();
            const signal = await reader.read();
            reader.releaseLock();
            assert(
                new TextDecoder().decode(signal.value).includes(
                    "waiting-for-boot",
                ),
            );
            assertEquals(
                (await check()).success,
                false,
                "one-shot apply fails while mounts are still starting",
            );
            for (const unit of wait.slice(11)) {
                await Deno.writeTextFile(`${dir}/${unit}`, "active");
            }
            const writer = child.stdin.getWriter();
            // A degraded boot is settled too, provided every required mount is active.
            await writer.write(
                new TextEncoder().encode(
                    role === "gateway" ? "running\n" : "degraded\n",
                ),
            );
            await writer.close();
            const result = await child.output();
            assert(result.success, new TextDecoder().decode(result.stderr));
            assertEquals((await check()).success, true);
        } finally {
            try {
                child?.kill("SIGKILL");
            } catch { /* already exited */ }
            await Deno.remove(dir, { recursive: true });
        }
    });
}

Deno.test("boot wait refuses an unsettled boot, a timeout and an inactive required mount", async () => {
    const wait = named(
        stepsOf(await workflow("proof-fleet-restore")),
        "Wait for systemd boot",
    ).task.inputs!.argv!;
    const dir = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(
            `${dir}/systemctl`,
            '#!/bin/sh\nif [ "$1" = is-system-running ]; then echo "$FIXTURE_STATE"; [ "$FIXTURE_STATE" = running ]; else exit 1; fi\n',
        );
        await Deno.chmod(`${dir}/systemctl`, 0o755);
        const check = (state: string) =>
            new Deno.Command("sh", {
                args: ["-c", wait[8], "sh", "gateway", ...wait.slice(11)],
                env: {
                    PATH: `${dir}:${Deno.env.get("PATH")}`,
                    FIXTURE_STATE: state,
                },
            }).output();
        const unsettled = await check("maintenance");
        assert(!unsettled.success);
        assert(
            new TextDecoder().decode(unsettled.stderr).includes(
                "state=maintenance",
            ),
        );
        const missing = await check("running");
        assert(!missing.success);
        assert(
            new TextDecoder().decode(missing.stderr).includes(
                `Required state mount ${wait[11]} is not active`,
            ),
        );
        // The timeout boundary returns its real status without waiting 120 seconds in this fixture.
        await Deno.writeTextFile(`${dir}/timeout`, "#!/bin/sh\nexit 124\n");
        await Deno.chmod(`${dir}/timeout`, 0o755);
        const expired = await check("running");
        assert(!expired.success);
        assert(new TextDecoder().decode(expired.stderr).endsWith("\n"));
        assert(
            new TextDecoder().decode(expired.stderr).includes(
                "systemd boot did not settle within 120s (state=, exit=124)",
            ),
        );
    } finally {
        await Deno.remove(dir, { recursive: true });
    }
});
