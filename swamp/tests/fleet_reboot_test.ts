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
    // After a failed Node, the next ones are told so and record `skipped` without touching their Node.
    assertEquals(nodes.task.inputs.halted, '${{ steps.exists(k, steps[k].status == "failed") }}');
    const report = pass.jobs.find((job: Record<string, unknown>) => job.name === "pass").steps.find((step: Record<string, unknown>) => step.name === "Report the pass");
    const declared = Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Fleet/direct-operations.php", import.meta.url));
    assertMatch(declared, /'fleet\.reboot' => \[\s*'workflow' => 'operation-fleet-reboot',[\s\S]*?'terminal_result' => \['job' => 'pass', 'step' => 'Report the pass', 'data' => 'fleet-reboot-report'\]/);
    assertEquals(report.task.behaviorName, "fleet-reboot-report");
});

Deno.test("each Node runs drain, reboot and check in order, each phase bounded and the drain longer than its budget", () => {
    const steps = workflow("fleet-reboot-node").jobs[0].steps as Array<Record<string, any>>;
    assertEquals(steps.map((step) => step.name), ["drain", "reboot", "check"]);
    assertEquals(steps.map((step) => step.task.inputs.run.match(/^timeout (\d+) .* --tags (\w+) /).slice(1)), [["1500", "drain"], ["900", "reboot"], ["600", "check"]]);
    assertEquals(steps.slice(1).map((step) => step.dependsOn), [[{ step: "drain", condition: { type: "succeeded" } }], [{ step: "reboot", condition: { type: "succeeded" } }]]);
    // The drain waits up to the 900 s budget for CI jobs and Horizon, plus the reads around it.
    assertEquals(Number(steps[0].task.inputs.run.split(" ")[1]) > 900 + 300, true);
});

Deno.test("a Node waits outside the window and after a failed Node, and an operator's pass ignores the window", async () => {
    const decide = drainBlock.find((t) => t.name === "Decide whether the Node waits")!;
    const folder = await Deno.makeTempDir();
    try {
        const reason = async (clock: string, enforced: boolean, halted = "false") => {
            const vars = { intent: { window: { timezone: "Europe/Amsterdam", start: "03:00", end: "06:00", enforced }, drain_seconds: 900 }, clock: { stdout: clock } };
            const result = await play(folder, vars, [decide, { name: "Keep", "ansible.builtin.copy": { dest: `${folder}/reason`, content: "{{ wait_reason }}" } }], { ORBIT_HALTED: halted });
            assertEquals(result.code, 0, result.output);
            return (await Deno.readTextFile(`${folder}/reason`)).trim();
        };
        assertEquals(await reason("03:10", true), "");
        assertEquals(await reason("02:59", true), "window");
        // 05:30 leaves 30 minutes: less than the 15-minute drain plus 15 minutes for the reboot and the check.
        assertEquals(await reason("05:31", true), "window");
        assertEquals(await reason("05:30", true), "");
        assertEquals(await reason("14:00", false), "");
        assertEquals(await reason("03:10", true, "true"), "halted");
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
    assertMatch(cleanup.task.inputs.run, /rm -rf -- "\$1"; if systemctl is-active --quiet orbit-planned-reboot\.path; then touch -- "\$1\.reboot"; fi/);
});
