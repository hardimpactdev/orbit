import { assertEquals, assertMatch, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

// The fleet.reboot pass (ADR 0253): its workflows, and the decisions of its playbook run against real Ansible on this machine.
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
    assertEquals(Object.keys(nodes.task.inputs), ["run_folder", "assets", "node_id", "position", "operation_id", "root_run", "ci"]);
    const report = pass.jobs.find((job: Record<string, unknown>) => job.name === "pass").steps.find((step: Record<string, unknown>) => step.name === "Report the pass");
    const declared = Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Fleet/direct-operations.php", import.meta.url));
    assertMatch(declared, /'fleet\.reboot' => \[\s*'workflow' => 'operation-fleet-reboot',[\s\S]*?'terminal_result' => \['job' => 'pass', 'step' => 'Report the pass', 'data' => 'fleet-reboot-report'\]/);
    assertEquals(report.task.behaviorName, "fleet-reboot-report");
});

Deno.test("each Node runs drain, reboot and check in order, each phase bounded and the drain longer than its budget", () => {
    const all = workflow("fleet-reboot-node").jobs[0].steps as Array<Record<string, any>>;
    assertEquals(all.map((step) => step.name), ["drain", "reboot", "check", "mint", "rerun"]);
    const steps = all.slice(0, 3);
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

Deno.test("a Node's play reads the clock and writes its record on the Swamp Node without SSH to localhost", async () => {
    const folder = await Deno.makeTempDir();
    try {
        // As in the pass: `-i localhost,` makes localhost a real inventory host, and a task delegated to it from the Node's play
        // uses its connection, not the local one of the addressing play. SSH to the Swamp Node itself fails host key checking.
        const clock = drainBlock.find((t) => t.name === "Read the time in the window's zone")!;
        const path = `${folder}/play.json`;
        await Deno.writeTextFile(path, JSON.stringify([
            { hosts: "localhost", connection: "local", gather_facts: false, tasks: [{ name: "Address the Node", "ansible.builtin.add_host": {
                name: "node", groups: ["reboot_node"], ansible_connection: "local", ansible_python_interpreter: "{{ ansible_playbook_python }}" } }] },
            { hosts: "reboot_node", gather_facts: false,
                vars: { intent: { window: { timezone: "Europe/Amsterdam" } }, target: { node_id: 18, name: "sabre" }, record_path: `${folder}/result-000-18.json` },
                tasks: [clock,
                    { name: "Record", "ansible.builtin.include_tasks": `${ansible}tasks/fleet-reboot-record.yml`, vars: { record_update: { node_id: 18, outcome: "not_due" } } }] },
        ]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", path], stdout: "piped", stderr: "piped",
            env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_HOST_KEY_CHECKING: "True",
                ANSIBLE_SSH_ARGS: "-o BatchMode=yes -o ConnectTimeout=3 -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile=/dev/null" },
        }).output();
        const output = new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr);
        assertEquals(result.code, 0, output);
        assertEquals(JSON.parse(await Deno.readTextFile(`${folder}/result-000-18.json`)), { node_id: 18, outcome: "not_due" });
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
        const run = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci", `${folder}/stops`], stdout: "piped",
            env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_PROC: `${folder}/proc` } }).output();
        assertEquals(run.code, 3);
        assertEquals((await Deno.readTextFile(`${folder}/calls`)).trim().split("\n"), [
            "list --project orbit-ci --format csv --columns ns",
            "pause --project orbit-ci ci-a", "stop --project orbit-ci --force ci-a",
            "pause --project orbit-ci ci-b", "start --project orbit-ci ci-b",
        ]);
        // Each stopped runner is recorded with the time of its freeze, for the rerun phase.
        const stop = JSON.parse((await Deno.readTextFile(`${folder}/stops`)).trim());
        assertEquals([stop.name, /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(stop.frozen_at)], ["ci-a", true]);
        // With the job gone, the round stops the last runner and ends at once.
        await Deno.writeTextFile(`${folder}/proc/42/cgroup`, "0::/lxc.payload.orbit-ci_other/\n");
        const done = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci", `${folder}/stops`],
            env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_PROC: `${folder}/proc` } }).output();
        assertEquals(done.code, 0);
        // A job of the host's own runner, outside any container, keeps the host busy.
        await Deno.writeTextFile(`${folder}/proc/42/cgroup`, "0::/system.slice/orbit-proof-runner.service\n");
        const host = await new Deno.Command("sh", { args: [`${ansible}files/fleet-reboot-ci-drain.sh`, "orbit-ci", `${folder}/stops`],
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
    assertEquals(reboot.find((t) => t.name === "Reboot and wait for the Node")!["ansible.builtin.reboot"], { reboot_timeout: 600, msg: "Orbit fleet reboot (ADR 0253)" });
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

const rerunTasks = parse(await Deno.readTextFile(`${ansible}tasks/fleet-reboot-rerun.yml`)) as Task[];
const rerunTask = (name: string) => rerunTasks.find((t) => t.name === name)!;

Deno.test("the rerun picks only failed jobs still running on a stopped runner at its freeze, in runs still at that attempt", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const run = (id: number, updated: string, conclusion: string | null) =>
            ({ id, updated_at: updated, status: conclusion === null ? "in_progress" : "completed", conclusion, run_attempt: 1 });
        const job = (name: string, runner: string, started: string, completed: string, conclusion = "failure") =>
            ({ name, runner_name: runner, started_at: started, completed_at: completed, status: "completed", conclusion });
        const repo = "hardimpactdev/orbit";
        const ended = (id: number, json: Record<string, unknown> | null) => json === null
            ? { item: { repository: repo, run_id: id }, failed: true, msg: "retries exhausted" }
            : { item: { repository: repo, run_id: id }, json };
        const vars = {
            stopped: [{ name: "ci-a", frozen_at: "2026-11-10T03:10:00Z" }],
            rerun_runs: { results: [{ item: { key: repo }, json: { workflow_runs: [
                run(1, "2026-11-10T03:15:00Z", "failure"), run(2, "2026-11-10T03:15:00Z", "success"), run(3, "2026-11-10T02:00:00Z", "failure"),
                // A run a newer push cancelled is never re-run, even when one of its jobs ran on a stopped runner.
                run(6, "2026-11-10T03:15:00Z", "cancelled"), run(4, "2026-11-10T03:30:00Z", "failure"), run(5, "2026-11-10T03:16:00Z", "failure"),
                run(7, "2026-11-10T03:16:00Z", null), run(8, "2026-11-10T03:16:00Z", null),
            ] } }] },
            rerun_ended: { results: [
                ended(1, { status: "completed", conclusion: "failure", run_attempt: 1 }), ended(4, { status: "completed", conclusion: "failure", run_attempt: 2 }),
                ended(5, { status: "completed", conclusion: "failure", run_attempt: 1 }),
                // Run 7 is still busy with other jobs after the wait, and the wait for run 8 failed: both are left pending.
                ended(7, { status: "in_progress", conclusion: null, run_attempt: 1 }), ended(8, null),
            ] },
            rerun_jobs: { results: [
                // Run 1: ci-a still ran `php` at its freeze and lost it. `lint` failed for real on ci-a before the freeze;
                // `web` failed on a runner the drain did not stop.
                { item: { repository: repo, run_id: 1, attempt: 1 }, json: { jobs: [
                    job("php", "ci-a", "2026-11-10T03:09:30Z", "2026-11-10T03:14:00Z"), job("lint", "ci-a", "2026-11-10T03:09:00Z", "2026-11-10T03:09:50Z"),
                    job("web", "ci-b", "2026-11-10T03:09:40Z", "2026-11-10T03:14:00Z")] } },
                // Run 4: ci-a ran `new` after its boot, when the runner was back.
                { item: { repository: repo, run_id: 4, attempt: 2 }, json: { jobs: [job("new", "ci-a", "2026-11-10T03:25:00Z", "2026-11-10T03:28:00Z")] } },
                // Run 5: lost a job too, but someone re-ran it already.
                { item: { repository: repo, run_id: 5, attempt: 1 }, json: { jobs: [job("deno", "ci-a", "2026-11-10T03:10:05Z", "2026-11-10T03:15:00Z")] } },
            ] },
            rerun_now: { results: [
                { item: { repository: repo, run_id: 1, attempt: 1, jobs: ["php"] }, json: { run_attempt: 1, status: "completed" } },
                { item: { repository: repo, run_id: 5, attempt: 1, jobs: ["deno"] }, json: { run_attempt: 2, status: "queued" } },
            ] },
        };
        const keep = (name: string) => ({ name: `Keep ${name}`, "ansible.builtin.copy": { dest: `${folder}/${name}`, content: `{{ ${name} | to_json }}` } });
        const result = await play(folder, vars, [
            rerunTask("Bound the run list"), rerunTask("Pick the runs that changed after the first freeze and did not succeed or end cancelled"),
            rerunTask("Split the ended failed runs from the pending ones"), rerunTask("Pick the runs that lost a job to a stopped runner"),
            rerunTask("Keep the lost runs still at the attempt that lost the job"),
            keep("rerun_created_since"), keep("rerun_candidates"), keep("rerun_failed"), keep("rerun_pending"), keep("rerun_lost"), keep("rerun_due"),
        ]);
        assertEquals(result.code, 0, result.output);
        const read = async (name: string) => {
            const value = JSON.parse(await Deno.readTextFile(`${folder}/${name}`));
            // A decision is a JSON text; a plain fact is its value.
            return typeof value === "string" && /^\s*[[{]/.test(value) ? JSON.parse(value) : value;
        };
        assertEquals(await read("rerun_created_since"), "2026-11-10T01:10:00Z");
        assertEquals((await read("rerun_candidates")).map((c: { run_id: number }) => c.run_id), [1, 4, 5, 7, 8]);
        assertEquals((await read("rerun_failed")).map((c: { run_id: number; attempt: number }) => [c.run_id, c.attempt]), [[1, 1], [4, 2], [5, 1]]);
        assertEquals((await read("rerun_pending")).map((c: { run_id: number }) => c.run_id), [8, 7]);
        assertEquals(await read("rerun_lost"), [{ repository: repo, run_id: 1, attempt: 1, jobs: ["php"] }, { repository: repo, run_id: 5, attempt: 1, jobs: ["deno"] }]);
        assertEquals(await read("rerun_due"), [{ repository: repo, run_id: 1, attempt: 1, jobs: ["php"] }]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the rerun records each accepted call on its own and the refused ones apart", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const record = `${folder}/result-000-9.json`;
        await Deno.writeTextFile(record, JSON.stringify({ node_id: 9, outcome: "rebooted" }));
        const a = { repository: "hardimpactdev/orbit", run_id: 1, attempt: 1, jobs: ["php"] };
        const b = { repository: "hardimpactdev/orbit", run_id: 2, attempt: 1, jobs: ["web"] };
        const recordTask = structuredClone(rerunTask("Record the re-run jobs and the runs left alone"));
        recordTask["ansible.builtin.include_tasks"] = `${ansible}tasks/fleet-reboot-record.yml`;
        const result = await play(folder, { record_path: record, rerun_pending: [], rerun_truncated: false,
            rerun_posted: { results: [{ item: a, status: 201 }, { item: b, status: 409 }] } }, [recordTask]);
        assertEquals(result.code, 0, result.output);
        const written = JSON.parse(await Deno.readTextFile(record));
        assertEquals([written.reran, written.rerun_refused, written.rerun_truncated], [[a], [b], false]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the waits and calls of the rerun never fail the phase, and the tokens are always revoked", async () => {
    const wait = rerunTask("Wait for each candidate run to end");
    assertEquals([wait.retries, wait.delay, wait.ignore_errors], [8, 30, true]);
    assertEquals(rerunTask("Re-run the failed jobs of each lost run").failed_when, false);
    const block = plays.find((p) => p.name === "Re-run the CI jobs the drain cut")!.tasks[0] as Record<string, Task[]>;
    const revoke = block.always[0]["ansible.builtin.uri"] as Record<string, unknown>;
    assertEquals([revoke.url, revoke.method], ["https://api.github.com/installation/token", "DELETE"]);
});

Deno.test("a runner registers under its container name, which the rerun matches against job runner names", async () => {
    const setup = await Deno.readTextFile(new URL("../../tools/ci-runners/setup.sh", import.meta.url));
    assertMatch(setup, /--name "\$2"[\s\S]*config "\$REPO_URL" "\$name"/);
});

Deno.test("the rerun posts re-run-failed-jobs once per due run, records it, and never fails the Node", async () => {
    const post = rerunTask("Re-run the failed jobs of each lost run")["ansible.builtin.uri"] as Record<string, unknown>;
    assertEquals([post.method, post.status_code, post.url], ["POST", 201,
        "https://api.github.com/repos/{{ item.repository }}/actions/runs/{{ item.run_id }}/rerun-failed-jobs"]);
    assertEquals(rerunTask("Re-run the failed jobs of each lost run").loop, "{{ rerun_due | from_json }}");
    const rerun = plays.find((p) => p.name === "Re-run the CI jobs the drain cut")!;
    assertEquals(rerun.tags, ["rerun"]);
    const node = workflow("fleet-reboot-node").jobs[0].steps as Array<Record<string, any>>;
    const [mint, step] = [node.find((s) => s.name === "mint")!, node.find((s) => s.name === "rerun")!];
    assertEquals([mint.guard, mint.allowFailure, mint.dependsOn], ["${{ !inputs.ci }}", true, [{ step: "check", condition: { type: "succeeded" } }]]);
    // The shared mint child asks for the pass's tokens with the root run, which the Gateway binds to the Operation.
    assertEquals([mint.task.workflowIdOrName, mint.task.inputs.run_id, mint.task.inputs.purpose], ["task-github-mint", "${{ inputs.root_run }}", "ci_rerun"]);
    assertEquals([step.guard, step.allowFailure], ["${{ !inputs.ci }}", true]);
    assertEquals(step.writes, true, "the rerun sends a POST and revokes its tokens; a lost dispatch must not repeat it");
    assertEquals(step.task.inputs.env.ORBIT_GITHUB, '${{ vault.get("orbit-control-inputs", "fleet-reboot-github-operation-" + string(inputs.operation_id)) }}');
    const pass = workflow("operation-fleet-reboot").jobs.find((job: Record<string, unknown>) => job.name === "nodes").steps[0];
    assertEquals([pass.task.inputs.root_run, pass.task.inputs.ci], ["${{ run.id }}", "${{ inputs.intent.nodes[string(self.node)].ci_project != null }}"]);
});


for (const due of [true, false]) {
    Deno.test(`a held ${due ? "due" : "not-due"} Node is read but never drained or rebooted`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            const record = `${folder}/result-000-9.json`;
            const boot = { due, due_since: "2026-11-06T03:00:00Z", boot_id: "held-boot", kernel: "7.0.0-42-generic", failed_units: [] };
            // Execute the real drain control flow and record writer. Replace host reads with fixed observations and
            // every host writer with a failing sentinel: the fixture cannot drain or reboot this machine.
            const tasks = drainBlock.map((original) => {
                const task = structuredClone(original);
                if (task.name === "Read the boot state") {
                    return { name: task.name, "ansible.builtin.set_fact": { boot } };
                }
                if (task["ansible.builtin.import_tasks"]) {
                    return { name: task.name, "ansible.builtin.fail": { msg: "Held Node reached a drain" }, when: task.when };
                }
                if (task["ansible.builtin.include_tasks"]) task["ansible.builtin.include_tasks"] = `${ansible}tasks/fleet-reboot-record.yml`;
                return task;
            });
            const vars = { run: folder, record_path: record,
                record: "{{ lookup('ansible.builtin.file', record_path, errors='ignore') | default('{}', true) | from_json }}",
                halted: false, target: { node_id: 9, name: "held", defer: "operator_hold", ci_project: "orbit-ci", task_project: "orbit-tasks", gateway: {} },
                intent: { window: { timezone: "Europe/Amsterdam", start: "03:00", end: "06:00", enforced: false }, drain_seconds: 900 } };
            const drained = await play(folder, vars, tasks);
            assertEquals(drained.code, 0, drained.output);
            const saved = JSON.parse(await Deno.readTextFile(record));
            assertEquals(saved.reboot_hold, true);
            assertEquals(saved.outcome, due ? "deferred" : "not_due");
            if (due) {
                assertEquals([saved.reason, saved.boot_id_before, saved.kernel_before, saved.due_since],
                    ["operator_hold", boot.boot_id, boot.kernel, boot.due_since]);
            } else {
                assertEquals(saved.reason, undefined);
                assertEquals(saved.due_since, undefined);
            }
            for (const phase of ["Reboot the Node", "Check the Node"]) {
                const phaseTasks = plays.find((p) => p.name === phase)!.tasks;
                const guard = phaseTasks.find((t) => t.name === "Keep a decided Node")!;
                const result = await play(folder, vars, [guard, { name: "Unexpected host work", "ansible.builtin.fail": { msg: phase } }]);
                assertEquals(result.code, 0, result.output);
            }
            const repeated = await play(folder, vars, [plays.find((p) => p.name === "Drain the Node")!.tasks[0], ...tasks]);
            assertEquals(repeated.code, 0, repeated.output);
            assertEquals(/changed=[1-9]/.test(repeated.output), false);
            assertEquals(JSON.parse(await Deno.readTextFile(record)), saved);
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}


for (const scenario of ["held observation", "held record", "Gateway partial stop", "CI partial stop",
    "Gateway already stopped", "Gateway Horizon already stopped", "Gateway timeout"]) {
    Deno.test(`the drain rescue restores only its recorded stops after ${scenario} fails`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            for (const part of ["tasks", "files", "library", "bin"]) await Deno.mkdir(`${folder}/${part}`);
            const state = { units: { "schedule.service": scenario === "Gateway already stopped" ? "inactive" : "active",
                    "horizon.service": scenario.includes("already stopped") ? "inactive" : "active" },
                horizon_timeout: scenario === "Gateway timeout",
                runners: { "ci-a": "Running", "ci-b": "Running", "ci-external": "Running", "ci-parked": "Stopped" } };
            await Deno.writeTextFile(`${folder}/state.json`, JSON.stringify(state));
            const fixture = fileURLToPath(new URL("fixtures/fleet-reboot-host.py", import.meta.url));
            await Deno.copyFile(fixture, `${folder}/library/orbit_reboot_systemd.py`);
            for (const tool of ["systemctl", "incus"]) {
                await Deno.writeTextFile(`${folder}/bin/${tool}`, `#!/bin/sh\nexec python3 '${fixture}' ${tool} "$@"\n`);
                await Deno.chmod(`${folder}/bin/${tool}`, 0o755);
            }
            await Deno.writeTextFile(`${folder}/bin/pgrep`, "#!/bin/sh\nexit 1\n");
            await Deno.chmod(`${folder}/bin/pgrep`, 0o755);
            await Deno.copyFile(`${ansible}files/fleet-reboot-ci-drain.sh`, `${folder}/files/fleet-reboot-ci-drain.sh`);
            const transform = (value: unknown): any => {
                if (typeof value === "string") return value.replaceAll("/run/orbit-fleet-reboot-ci-stops", `${folder}/ci-stops`);
                if (Array.isArray(value)) return value.map(transform);
                if (value !== null && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([key, child]) =>
                    [key === "ansible.builtin.systemd_service" ? "orbit_reboot_systemd" : key, transform(child)]));
                return value;
            };
            for await (const file of Deno.readDir(`${ansible}tasks`)) {
                if (file.name.startsWith("fleet-reboot-")) {
                    await Deno.writeTextFile(`${folder}/tasks/${file.name}`, JSON.stringify(transform(parse(await Deno.readTextFile(`${ansible}tasks/${file.name}`)))));
                }
            }
            const phase = transform(structuredClone(plays.find((p) => p.name === "Drain the Node")!.tasks.find((t) => t.name === "Drain the Node")!));
            phase.block = phase.block.map((task: Task) => {
                if (task.name === "Read the boot state") return scenario === "held observation"
                    ? { name: task.name, "ansible.builtin.fail": { msg: "Observation failed" } }
                    : { name: task.name, "ansible.builtin.set_fact": { boot: { due: true, due_since: "2026-11-06T03:00:00Z",
                        kernel: "7.0.0-42-generic", boot_id: "before", failed_units: [] } } };
                if (task.name === "Record a Node that waits" && scenario === "held record") {
                    return { name: task.name, "ansible.builtin.fail": { msg: "Record write failed" } };
                }
                return task;
            });
            if (scenario.includes("already stopped") || scenario === "Gateway timeout") {
                phase.block.push({ name: "Fail after drain", "ansible.builtin.fail": { msg: "Drain failed" } });
            }
            const record = `${folder}/result.json`;
            const result = await play(folder, { run: folder, record_path: record, phase: "drain", halted: false,
                record: "{{ lookup('ansible.builtin.file', record_path, errors='ignore') | default('{}', true) | from_json }}",
                target: { node_id: 9, name: "fixture", defer: scenario.startsWith("held") ? "operator_hold" : null,
                    task_project: null, ci_project: scenario === "CI partial stop" ? "orbit-ci" : null,
                    gateway: scenario === "CI partial stop" ? null : { scheduler: "schedule.service", horizon: "horizon.service" } },
                intent: { window: { timezone: "Europe/Amsterdam", start: "03:00", end: "06:00", enforced: false }, drain_seconds: 20 },
            }, [phase], { REBOOT_FIXTURE: folder, ANSIBLE_LIBRARY: `${folder}/library`,
                PATH: `${folder}/bin:${Deno.env.get("PATH")}` });
            assertNotEquals(result.code, 0, result.output);
            const saved = JSON.parse(await Deno.readTextFile(record));
            assertEquals(saved.outcome, "failed", result.output);
            const calls: string[][] = (await Deno.readTextFile(`${folder}/calls.jsonl`).catch(() => "")).trim().split("\n").filter(Boolean).map((line) => JSON.parse(line));
            const starts = calls.filter((call) => call[0] === "systemd" && call[1] === "started" || call[0] === "incus" && call[1] === "start");
            if (scenario === "Gateway partial stop" || scenario === "Gateway timeout") {
                assertEquals(starts, [["systemd", "started", "schedule.service"], ["systemd", "started", "horizon.service"]]);
                assertEquals(saved.gateway_stopped, ["schedule.service", "horizon.service"]);
            } else if (scenario === "Gateway Horizon already stopped") {
                assertEquals(starts, [["systemd", "started", "schedule.service"]]);
                assertEquals(saved.gateway_stopped, ["schedule.service"]);
            } else if (scenario === "CI partial stop") {
                assertEquals(starts, [["incus", "start", "--project", "orbit-ci", "ci-a"]]);
                assertEquals(saved.ci_stopped.map((runner: { name: string }) => runner.name), ["ci-a"]);
            } else {
                assertEquals(starts, []);
            }
        } finally { await Deno.remove(folder, { recursive: true }); }
    });
}
