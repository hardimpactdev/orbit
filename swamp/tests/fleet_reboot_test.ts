import { assertEquals, assertMatch, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// The fleet.reboot pass (ADR 0251): its workflows, and the decisions of its playbook run against real Ansible on this machine.
const ansible = fileURLToPath(new URL("../ansible/", import.meta.url));
const playbook = `${ansible}operation-fleet-reboot.yml`;
type Task = Record<string, unknown>;
type Play = { name: string; tags: string[]; tasks: Task[] };
const plays = parse(await Deno.readTextFile(playbook)) as Play[];
const workflow = (name: string) => parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${name}.yaml`, import.meta.url))) as Record<string, any>;

/** Runs a play on this machine and returns its exit code and output. */
async function play(folder: string, vars: Record<string, unknown>, tasks: unknown[], env: Record<string, string> = {}) {
    const path = `${folder}/play.json`;
    await Deno.writeTextFile(path, JSON.stringify([{ hosts: "localhost", connection: "local", gather_facts: false, vars, tasks }]));
    const result = await new Deno.Command("ansible-playbook", {
        args: ["-i", "localhost,", path], stdout: "piped", stderr: "piped",
        env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_ROLES_PATH: ansible, ...env },
    }).output();
    return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr) };
}

const drainBlock = (plays.find((p) => p.name === "Drain the Node")!.tasks.find((t) => t.name === "Drain the Node")!.block) as Task[];

Deno.test("the pass visits one Node at a time and the Gateway reads its report step", () => {
    const pass = workflow("operation-fleet-reboot");
    const nodes = pass.jobs.find((job: Record<string, unknown>) => job.name === "nodes").steps[0];
    assertEquals([nodes.concurrency, nodes.forEach.in, nodes.task.workflowIdOrName], [1, "${{ inputs.intent.order }}", "fleet-reboot-node"]);
    // After a failed Node the next ones read the run folder and record `skipped`; no Swamp expression carries it.
    assertEquals(Object.keys(nodes.task.inputs), ["run_folder", "assets", "node_id", "position"]);
    const report = pass.jobs.find((job: Record<string, unknown>) => job.name === "pass").steps.find((step: Record<string, unknown>) => step.name === "Report the pass");
    const declared = Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Fleet/direct-operations.php", import.meta.url));
    assertMatch(declared, /'fleet\.reboot' => \[\s*'workflow' => 'operation-fleet-reboot',[\s\S]*?'terminal_result' => \['job' => 'pass', 'step' => 'Report the pass', 'data' => 'fleet-reboot-report'\]/);
    assertEquals(report.task.behaviorName, "fleet-reboot-report");
});

Deno.test("each Node runs drain, reboot and check in order, each phase bounded and the drain longer than its budget", () => {
    const steps = workflow("fleet-reboot-node").jobs[0].steps as Array<Record<string, any>>;
    assertEquals(steps.map((step) => step.name), ["drain", "reboot", "check"]);
    assertEquals(steps.map((step) => step.task.inputs.run.match(/^timeout (\d+) .* --tags (\w+) /).slice(1)), [["2400", "drain"], ["900", "reboot"], ["600", "check"]]);
    assertEquals(steps.slice(1).map((step) => step.dependsOn), [[{ step: "drain", condition: { type: "succeeded" } }], [{ step: "reboot", condition: { type: "succeeded" } }]]);
    // The drain waits up to the 900 s budget for CI jobs and Horizon, then stops up to six task guests (75 s each), plus the reads.
    assertEquals(Number(steps[0].task.inputs.run.split(" ")[1]) > 900 + 6 * 75 + 300, true);
});

Deno.test("a Node waits outside the window or when admission found its task work busy, and an operator's pass ignores the window", async () => {
    const decide = drainBlock.find((t) => t.name === "Decide whether the Node waits")!;
    const folder = await Deno.makeTempDir();
    try {
        const reason = async (clock: string, enforced: boolean, defer: string | null = null) => {
            const vars = { intent: { window: { timezone: "Europe/Amsterdam", start: "03:00", end: "06:00", enforced }, drain_seconds: 900 },
                clock: { stdout: clock }, target: { defer } };
            const result = await play(folder, vars, [decide, { name: "Keep", "ansible.builtin.copy": { dest: `${folder}/reason`, content: "{{ wait_reason }}" } }]);
            assertEquals(result.code, 0, result.output);
            return (await Deno.readTextFile(`${folder}/reason`)).trim();
        };
        assertEquals(await reason("03:10", true), "");
        assertEquals(await reason("02:59", true), "window");
        // 05:15 leaves 45 minutes: the 15-minute drain plus 30 minutes for the guest stops, the reboot and the check.
        assertEquals(await reason("05:16", true), "window");
        assertEquals(await reason("05:15", true), "");
        assertEquals(await reason("14:00", false), "");
        assertEquals(await reason("03:10", true, "tasks_busy"), "tasks_busy");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a Node after a failed one halts from the records in the run folder", async () => {
    const vars = plays.find((p) => p.name === "Drain the Node")! as unknown as { vars: Record<string, string> };
    const folder = await Deno.makeTempDir();
    try {
        const halted = async () => {
            const result = await play(folder, { run: folder, halted: vars.vars.halted },
                [{ name: "Keep", "ansible.builtin.copy": { dest: `${folder}/halted`, content: "{{ halted }}" } }]);
            assertEquals(result.code, 0, result.output);
            return (await Deno.readTextFile(`${folder}/halted`)).trim();
        };
        await Deno.writeTextFile(`${folder}/result-000-4.json`, JSON.stringify({ node_id: 4, outcome: "rebooted" }));
        await Deno.writeTextFile(`${folder}/result-001-9.json`, JSON.stringify({ node_id: 9, kernel_before: "7.0.0-42-generic" }));
        assertEquals(await halted(), "False");
        await Deno.writeTextFile(`${folder}/result-001-9.json`, JSON.stringify({ node_id: 9, outcome: "failed", error_code: "fleet.reboot_lost" }));
        assertEquals(await halted(), "True");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the check waits for the boot to finish before it reads the failed units", () => {
    const check = plays.find((p) => p.name === "Check the Node")!.tasks.find((t) => t.name === "Check the Node")!.block as Task[];
    const names = check.map((t) => t.name);
    assertEquals(names.indexOf("Wait for the boot to finish") < names.indexOf("Read the boot state"), true);
});

Deno.test("the CI drain stops idle runners, keeps a busy one running and never leaves one frozen", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // Stub incus: list two running runners, log every call. Stub pgrep: one Runner.Worker in ci-b's container.
        await Deno.mkdir(`${folder}/bin`);
        await Deno.mkdir(`${folder}/proc/42`, { recursive: true });
        await Deno.writeTextFile(`${folder}/proc/42/cgroup`, "0::/lxc.payload.orbit-ci_ci-b/system.slice\n");
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh\necho "$*" >> '${folder}/calls'\n[ "$1" = list ] && printf 'ci-a,RUNNING\\nci-b,RUNNING\\nci-c,STOPPED\\n'\nexit 0\n`);
        await Deno.writeTextFile(`${folder}/bin/pgrep`, `#!/bin/sh\necho 42\n`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.chmod(`${folder}/bin/pgrep`, 0o755);
        const run = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci"], stdout: "piped",
            env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_PROC: `${folder}/proc` } }).output();
        assertEquals(run.code, 3);
        assertEquals((await Deno.readTextFile(`${folder}/calls`)).trim().split("\n"), [
            "list --project orbit-ci --format csv --columns ns",
            "pause --project orbit-ci ci-a", "stop --project orbit-ci --force ci-a",
            "pause --project orbit-ci ci-b", "start --project orbit-ci ci-b",
        ]);
        // With the job gone, the round stops the last runner and ends at once.
        await Deno.writeTextFile(`${folder}/proc/42/cgroup`, "0::/lxc.payload.orbit-ci_other/\n");
        const done = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci"],
            env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_PROC: `${folder}/proc` } }).output();
        assertEquals(done.code, 0);
        // A job of the host's own runner, outside any container, keeps the host busy.
        await Deno.writeTextFile(`${folder}/proc/42/cgroup`, "0::/system.slice/orbit-proof-runner.service\n");
        const host = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci"],
            env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_PROC: `${folder}/proc` } }).output();
        assertEquals(host.code, 3);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a failed phase records the assert's error code or the phase's own code, keeps the earlier record, and fails", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const record = `${folder}/result-000-7.json`;
        await Deno.writeTextFile(record, JSON.stringify({ node_id: 7, name: "beast", kernel_before: "7.0.0-42-generic", failed_before: ["a.service"] }));
        const vars = { target: { node_id: 7, name: "beast" }, phase: "check", record_path: record,
            record: "{{ lookup('ansible.builtin.file', record_path) | from_json }}", boot: { failed_units: ["a.service", "b.service"] } };
        const run = (failing: Task) => play(folder, vars, [{ name: "Phase", block: [failing], rescue: [{ name: "Rescue", "ansible.builtin.include_tasks": `${ansible}tasks/fleet-reboot-failed.yml` }] }]);
        const asserted = await run({ name: "Check", "ansible.builtin.assert": { that: ["false"], fail_msg: "fleet.reboot_old_kernel" } });
        assertNotEquals(asserted.code, 0);
        assertEquals(JSON.parse(await Deno.readTextFile(record)), { node_id: 7, name: "beast", kernel_before: "7.0.0-42-generic", failed_before: ["a.service"],
            outcome: "failed", step: "check", error_code: "fleet.reboot_old_kernel", failed_units: ["b.service"] });
        await run({ name: "Break", "ansible.builtin.command": { argv: ["false"] } });
        assertEquals(JSON.parse(await Deno.readTextFile(record)).error_code, "fleet.reboot_check_failed");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the report lists every Node record in pass order and reports a cut Node failed", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.writeTextFile(`${folder}/result-001-9.json`, JSON.stringify({ node_id: 9, name: "beast", kernel_before: "7.0.0-42-generic" }));
        await Deno.writeTextFile(`${folder}/result-000-4.json`, JSON.stringify({ node_id: 4, name: "app-prod", outcome: "rebooted" }));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "report", playbook], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ORBIT_RUN: folder },
        }).output();
        assertEquals(result.code, 0, new TextDecoder().decode(result.stdout));
        assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/report.json`)), { nodes: [
            { node_id: 4, name: "app-prod", outcome: "rebooted" },
            { outcome: "failed", error_code: "fleet.reboot_cut", node_id: 9, name: "beast", kernel_before: "7.0.0-42-generic" },
        ] });
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("only the swamp Node schedules its own reboot, behind a path unit that the pass's cleanup releases", () => {
    const reboot = plays.find((p) => p.name === "Reboot the Node")!.tasks.find((t) => t.name === "Reboot the Node")!.block as Task[];
    const schedule = reboot.find((t) => t.name === "Schedule the reboot of the swamp Node after the pass")!;
    assertEquals(schedule.when, "target.kind == 'swamp'");
    assertEquals((schedule["ansible.builtin.command"] as Record<string, string[]>).argv.slice(0, 4),
        ["systemd-run", "--unit=orbit-planned-reboot", "--collect", "--path-property=PathExists={{ run }}.reboot"]);
    assertEquals(reboot.find((t) => t.name === "Reboot and wait for the Node")!["ansible.builtin.reboot"], { reboot_timeout: 600, msg: "Orbit fleet reboot (ADR 0251)" });
    const cleanup = workflow("operation-fleet-reboot").jobs.find((job: Record<string, unknown>) => job.name === "pass").steps.find((step: Record<string, unknown>) => step.name === "cleanup");
    assertEquals(cleanup.task.inputs.run, 'timeout 30 rm -rf -- "$ORBIT_RUN"\nif timeout 10 systemctl is-active --quiet orbit-planned-reboot.path; then timeout 10 touch -- "$ORBIT_RUN.reboot"; fi');
});

Deno.test("a failed drain starts what it stopped, and each task guest is recorded as soon as it stops", async () => {
    const rescue = plays.find((p) => p.name === "Drain the Node")!.tasks.find((t) => t.name === "Drain the Node")!.rescue as Task[];
    assertEquals(rescue.map((t) => t["ansible.builtin.import_tasks"]), ["tasks/fleet-reboot-drain-undo.yml", "tasks/fleet-reboot-failed.yml"]);
    const undo = parse(await Deno.readTextFile(`${ansible}tasks/fleet-reboot-drain-undo.yml`)) as Task[];
    assertEquals(undo.map((t) => t.name), ["Start the stopped runners again", "Start the Gateway scheduler and Horizon again"]);
    const park = parse(await Deno.readTextFile(`${ansible}tasks/fleet-reboot-park.yml`)) as Task[];
    assertEquals(park.map((t) => t.name), ["Stop the task guest", "Record the parked task guest"]);
    assertEquals((park[1].vars as Record<string, Record<string, string>>).record_update.parked, "{{ (record.parked | default([])) + [guest] }}");
});
