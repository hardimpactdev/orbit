import { assertEquals, assertMatch, assertNotEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";
import { fleetRebootResult } from "../extensions/behaviors/operations/ansible-input/fleet_reboot_result.ts";

const ansible = fileURLToPath(new URL("../ansible/", import.meta.url));
const source = parse(await Deno.readTextFile(`${ansible}operation-fleet-reboot.yml`)) as any[];
const workflow = parse(await Deno.readTextFile(new URL("../workflows/workflow-fleet-reboot-node.yaml", import.meta.url))) as any;
const steps = workflow.jobs[0].steps as any[];
const fixture = fileURLToPath(new URL("fixtures/fleet-reboot-ssh.py", import.meta.url));
const diagnostic = "ssh: connect to host 192.0.2.9 port 22: Connection timed out";
const recordPath = (folder: string, id = 9, position = 0) => `${folder}/result-${String(position).padStart(3, "0")}-${id}.json`;

async function folder() {
    const path = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(path, { mode: 0o700 });
    await Deno.writeTextFile(`${path}/intent.json`, JSON.stringify({ nodes: { "9": { name: "fixture", defer: null } } }));
    return path;
}

/** The production phase control flow and native SSH plugin, with host reads replaced by a harmless ping and fixed state. */
async function phase(path: string, failures: number, held = false, name = "drain", retries?: string) {
    await Deno.writeTextFile(`${path}/ssh.json`, JSON.stringify({ failures, calls: 0 }));
    await Deno.writeTextFile(`${path}/intent.json`, JSON.stringify({ nodes: { "9": { name: "fixture", defer: held ? "operator_hold" : null } } }));
    const play = structuredClone(source.find((play) => play.tags.includes(name) && play.hosts === "reboot_node"));
    const block = play.tasks.find((task: any) => task.block).block;
    const read = block.findIndex((task: any) => task.name === (name === "drain" ? "Read the boot state" : "Wait for the boot to finish"));
    // An actual Ansible module over the real ssh connection plugin is needed: set_fact alone never connects.
    const readVars = block[read].vars;
    if (readVars?.ansible_ssh_retries !== undefined && retries !== undefined) readVars.ansible_ssh_retries = Number(retries);
    block[read] = { name: "Read the fixture boot state", "ansible.builtin.ping": {}, ...(readVars ? { vars: readVars } : {}) };
    if (name === "drain") block.splice(read + 1, 0, { name: "Keep fixture state", "ansible.builtin.set_fact": { boot: { due: false, kernel: "fixture-kernel" } } });
    const absolute = (value: any): any => {
        if (Array.isArray(value)) return value.map(absolute);
        if (value && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([key, child]) =>
            [key, ["ansible.builtin.import_tasks", "ansible.builtin.include_tasks"].includes(key) && typeof child === "string" ? ansible + child : absolute(child)]));
        return value;
    };
    play.become = false;
    play.vars = { ...play.vars, target: { node_id: 9, name: "fixture", defer: held ? "operator_hold" : null },
        intent: { window: { timezone: "Europe/Amsterdam" } } };
    const address = { hosts: "localhost", tags: [name], connection: "local", gather_facts: false, tasks: [{ name: "Address a local-only fixture", "ansible.builtin.add_host": {
        name: "node", groups: ["reboot_node"], ansible_connection: "ssh", ansible_host: "192.0.2.9", ansible_python_interpreter: "{{ ansible_playbook_python }}",
        ansible_ssh_executable: fixture, ansible_ssh_transfer_method: "piped", ansible_ssh_pipelining: true,
    } }] };
    await Deno.writeTextFile(`${path}/play.json`, JSON.stringify([address, absolute(play)]));
    const step = steps.find((step) => step.name === name);
    const env = Object.fromEntries(Object.entries(step.task.inputs.env).filter(([, value]) => typeof value === "string" && !value.includes("${{"))) as Record<string, string>;
    const result = await new Deno.Command("sh", {
        args: ["-c", step.task.inputs.run.replace('"$ORBIT_ANSIBLE/operation-fleet-reboot.yml"', '"$ORBIT_RUN/play.json"')],
        stdout: "piped", stderr: "piped",
        env: { ...env, ANSIBLE_CONFIG: `${ansible}ansible.cfg`, ANSIBLE_FORKS: "2", ORBIT_RUN: path, ORBIT_NODE_ID: "9", ORBIT_POSITION: "0",
            REBOOT_SSH_FIXTURE: path, ANSIBLE_COLLECTIONS_PATH: Deno.env.get("ANSIBLE_COLLECTIONS_PATH") ?? "/opt/orbit/ansible/collections",
            ...(retries === undefined ? {} : { ANSIBLE_SSH_RETRIES: retries }) },
    }).output();
    return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr),
        ssh: JSON.parse(await Deno.readTextFile(`${path}/ssh.json`)) };
}

Deno.test("fleet reboot retries a first unreachable attempt and succeeds on the second without a failure record", async () => {
    const path = await folder();
    try {
        const run = await phase(path, 1);
        assertEquals(run.code, 0, run.output);
        assertEquals(run.output, "", "Private phase output must not enter native history");
        assertEquals(run.ssh.calls, 2);
        assertEquals(run.ssh.argv.every((argv: string[]) => argv.includes("ConnectTimeout=10")), true);
        const record = await fleetRebootResult({ folder: path, node_id: 9, position: 0 });
        assertEquals(record, { node_id: 9, outcome: "not_due", error_code: undefined });
        assertEquals(JSON.parse(await Deno.readTextFile(recordPath(path))).error_code, undefined);
        const env = steps[0].task.inputs.env;
        assertEquals([env.ANSIBLE_SSH_RETRIES, env.ANSIBLE_TIMEOUT], ["0", "10"]);
        assertMatch(env.ANSIBLE_SSH_ARGS, /ConnectionAttempts=1/);
        const drain = source.find((play) => play.name === "Drain the Node").tasks.find((task: any) => task.block).block;
        assertEquals(drain.find((task: any) => task.name === "Read the boot state").vars.ansible_ssh_retries, 7);
    } finally { await Deno.remove(path, { recursive: true }); }
});

for (const held of [false, true]) {
    Deno.test(`fleet reboot records a persistently unreachable ${held ? "held" : "ordinary"} Node and later Nodes skip halted without SSH`, async () => {
        const path = await folder();
        try {
            // Exercise all eight production attempts once. The held case uses two attempts to keep this fixture fast.
            const run = await phase(path, 100, held, "drain", held ? "1" : undefined);
            assertEquals(run.code, 4, run.output);
            assertEquals(run.output, "");
            assertEquals(run.ssh.calls, held ? 2 : 8);
            const shaped = await fleetRebootResult({ folder: path, node_id: 9, position: 0 });
            assertEquals([shaped.outcome, shaped.error_code], ["failed", "fleet.node_unreachable"]);
            assertMatch(shaped.error_detail!, /ssh: connect to host 192\.0\.2\.9 port 22: Connection timed out/);
            assertEquals(shaped.error_detail!.includes("fixture-private-credential"), false);
            assertMatch(shaped.error_detail!, /\[REDACTED\]/);
            const saved = JSON.parse(await Deno.readTextFile(recordPath(path)));
            assertEquals([saved.step, saved.reboot_hold], ["drain", held]);
            assertEquals(saved.drain_started, undefined);
            await assertRejects(() => Deno.stat(`${path}/drain-9.json`), Deno.errors.NotFound);
            await assertRejects(() => Deno.stat(`${path}/drain-9.stderr`), Deno.errors.NotFound);
            // The real production halt condition reads the newly written failed record before any remote task.
            const count = run.ssh.calls;
            await Deno.rename(recordPath(path), recordPath(path, 8));
            const later = await phase(path, 100, false, "drain", "1");
            assertEquals(later.code, 0, later.output);
            assertEquals(later.ssh.calls, 0);
            assertNotEquals(count, 0);
            assertEquals(JSON.parse(await Deno.readTextFile(recordPath(path))).reason, "halted");
        } finally { await Deno.remove(path, { recursive: true }); }
    });
}

Deno.test("fleet reboot records unreachable check work on the controller after earlier phases connected", async () => {
    const path = await folder();
    try {
        await Deno.writeTextFile(recordPath(path), JSON.stringify({ node_id: 9, boot_id_before: "before", kernel_before: "old", drain_started: true }));
        const run = await phase(path, 100, false, "check", "1");
        assertEquals(run.code, 4, run.output);
        await fleetRebootResult({ folder: path, node_id: 9, position: 0 });
        const saved = JSON.parse(await Deno.readTextFile(recordPath(path)));
        assertEquals([saved.outcome, saved.error_code, saved.step, saved.boot_id_before], ["failed", "fleet.node_unreachable", "check", "before"]);
    } finally { await Deno.remove(path, { recursive: true }); }
});

Deno.test("fleet reboot records a started cut phase as failed instead of not reached", async () => {
    const path = await folder();
    try {
        await Deno.writeTextFile(`${path}/drain-9.json`, '{"plays":[');
        const result = await fleetRebootResult({ folder: path, node_id: 9, position: 0 });
        assertEquals([result.outcome, result.error_code], ["failed", "fleet.reboot_cut"]);
    } finally { await Deno.remove(path, { recursive: true }); }
});

Deno.test("collection can report a retained attempted-Node record when finalization was cut", async () => {
    const path = await folder();
    try {
        const run = await phase(path, 100, false, "drain", "1");
        assertEquals(run.code, 4, run.output);
        const report = await new Deno.Command("/opt/orbit/ansible/venv/bin/ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "report", `${ansible}operation-fleet-reboot.yml`], stdout: "piped", stderr: "piped",
            env: { ORBIT_RUN: path, ANSIBLE_STDOUT_CALLBACK: "default", ANSIBLE_CONFIG: `${ansible}ansible.cfg`, ANSIBLE_FORKS: "2" },
        }).output();
        assertEquals(report.code, 0, new TextDecoder().decode(report.stderr));
        const saved = JSON.parse(await Deno.readTextFile(`${path}/report.json`));
        assertEquals(saved.nodes.map((node: any) => [node.node_id, node.outcome, node.error_code]), [[9, "failed", "fleet.reboot_cut"]]);
    } finally { await Deno.remove(path, { recursive: true }); }
});

Deno.test("unreachable diagnostics are redacted and bounded before recording and output", async () => {
    const path = await folder();
    try {
        await Deno.writeTextFile(`${path}/drain-9.json`, JSON.stringify({ stats: { node: { unreachable: 1 } }, plays: [{ tasks: [{ hosts: { node: {
            unreachable: true, msg: "password=fixture-private-credential\n" + "public line\n".repeat(500) + diagnostic,
        } } }] }] }));
        const result = await fleetRebootResult({ folder: path, node_id: 9, position: 0 });
        assertEquals(new TextEncoder().encode(result.error_detail!).length <= 4096, true);
        assertEquals(result.error_detail!.includes("fixture-private-credential"), false);
        assertMatch(result.error_detail!, /Connection timed out/);
        assertEquals(JSON.parse(await Deno.readTextFile(recordPath(path))).error_detail, result.error_detail);
    } finally { await Deno.remove(path, { recursive: true }); }
});

Deno.test("the controller records phase failures after succeeded, failed and skipped phases", () => {
    const record = steps.find((step) => step.name === "Record the Node");
    assertEquals(record.task.methodName, "fleetRebootResult");
    assertEquals(record.dependsOn.map((dependency: any) => dependency.step), ["drain", "reboot", "check", "mint", "rerun"]);
    assertEquals(record.dependsOn.every((dependency: any) => JSON.stringify(dependency.condition) === JSON.stringify({ or: [{ type: "completed" }, { type: "skipped" }] })), true);
});


for (const name of ["drain", "reboot"]) {
    Deno.test(`the ${name} phase never replays a command that executed before SSH disconnected`, async () => {
        const path = await folder();
        try {
            const command = `${path}/harmless-change`;
            await Deno.writeTextFile(command, '#!/bin/sh\nprintf "effect\\n" >> "$REBOOT_SSH_FIXTURE/effects"\nprintf after > "$REBOOT_SSH_FIXTURE/boot-id"\n');
            await Deno.chmod(command, 0o755);
            await Deno.writeTextFile(`${path}/boot-id`, "before");
            await Deno.writeTextFile(`${path}/ssh.json`, JSON.stringify({ failures: 0, calls: 0, disconnect_after: command }));
            const original = source.find((play) => play.hosts === "reboot_node" && play.tags.includes(name));
            const block = original.tasks.find((task: any) => task.block);
            const task = name === "reboot"
                ? { ...structuredClone(block.block.find((task: any) => task.name === "Reboot and wait for the Node")),
                    "ansible.builtin.reboot": { reboot_command: command, boot_time_command: `cat ${path}/boot-id`, test_command: "true", reboot_timeout: 5 } }
                : { name: "Harmless drain stand-in", "ansible.builtin.raw": command };
            const read = block.block.find((task: any) => task.name === "Read the boot state");
            const tasks = name === "drain" ? [{ name: "Initial safe fixture read", "ansible.builtin.ping": {}, vars: read.vars ?? {} }, task] : [task];
            await Deno.writeTextFile(`${path}/play.json`, JSON.stringify([
                { hosts: "localhost", connection: "local", gather_facts: false, tags: [name], tasks: [{ name: "Address the local-only fixture", "ansible.builtin.add_host": {
                    name: "fixture", groups: ["reboot_node"], ansible_host: "192.0.2.9", ansible_connection: "ssh", ansible_ssh_executable: fixture,
                    ansible_python_interpreter: "{{ ansible_playbook_python }}", ansible_ssh_pipelining: true,
                } }] },
                { ...original, become: false, vars: { ...original.vars, ...(block.vars ?? {}) }, tasks },
            ]));
            const step = steps.find((step) => step.name === name);
            const env = Object.fromEntries(Object.entries(step.task.inputs.env).filter(([, value]) => typeof value === "string" && !value.includes("${{"))) as Record<string, string>;
            const result = await new Deno.Command("sh", {
                args: ["-c", step.task.inputs.run.replace('"$ORBIT_ANSIBLE/operation-fleet-reboot.yml"', '"$ORBIT_RUN/play.json"')], stdout: "piped", stderr: "piped",
                env: { ...env, ANSIBLE_CONFIG: `${ansible}ansible.cfg`, ANSIBLE_FORKS: "2", ORBIT_RUN: path, ORBIT_NODE_ID: "9", REBOOT_SSH_FIXTURE: path,
                    ANSIBLE_COLLECTIONS_PATH: Deno.env.get("ANSIBLE_COLLECTIONS_PATH") ?? "/opt/orbit/ansible/collections" },
            }).output();
            const effects = (await Deno.readTextFile(`${path}/effects`)).trim().split("\n");
            assertEquals(effects, ["effect"], "SSH retried a command after it already changed the Node");
            assertEquals(result.code, name === "reboot" ? 0 : 4);
        } finally { await Deno.remove(path, { recursive: true }); }
    });
}
