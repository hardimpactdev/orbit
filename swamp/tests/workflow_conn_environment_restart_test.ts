/** The T3 restart for the machine name (docs/cli/conn/environment-restart.mmd): the shipped workflow's steps, and its one phase run by real
 * Ansible against the Incus fixture with a T3 descriptor server. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string; condition: { type: string } }[]; task: { behaviorName?: string; inputs: { run?: string; env?: Record<string, string> } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-conn-environment-restart.yaml", import.meta.url))) as {
    inputs: { properties: { intent: { required: string[]; properties: Record<string, unknown> } } };
    jobs: { name: string; steps: Step[] }[];
};
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";

Deno.test("the restart runs one tagged phase of the shipped playbook between the input and the result, and takes no secret", () => {
    const steps = workflow.jobs.flatMap((job) => job.steps);
    assertEquals(workflow.jobs.map((job) => job.name), ["restart"]);
    assertEquals(steps.map((step) => step.name), ["input", "Restart T3", "result", "cleanup"]);
    assertEquals(steps[2].dependsOn, [{ step: "Restart T3", condition: { type: "always" } }]);
    assertEquals(workflow.inputs.properties.intent.required, ["instance_id", "t3_port", "name", "machine_info", "read_at", "container", "ssh_execution"]);
    const phase = steps[1];
    assertEquals(phase.task.inputs.run, 'timeout 240 /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags restart "$ORBIT_ANSIBLE/conn-environment-restart.yml"');
    const context = { inputs: { node_id: 9, intent: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const evaluate = (value: string) => value.startsWith("${{") ? String(cel.evaluate(value.slice(3, -2).trim(), context)) : value;
    const env = Object.fromEntries(Object.entries(phase.task.inputs.env!).map(([key, value]) => [key, evaluate(value)]));
    assertEquals([env.ANSIBLE_CONFIG, env.ORBIT_ANSIBLE, env.ORBIT_RUN_ID, env.ORBIT_RUN], [`${shipped}/ansible.cfg`, shipped, runId, `/tmp/orbit-ansible-${runId}`]);
    assertEquals(evaluate(phase.task.behaviorName!), "conn-environment-restart-9");
    assert(Deno.statSync(`${shipped}/conn-environment-restart.yml`).isFile);
    assert(!JSON.stringify(workflow).includes("vault.get"));
});

/** The members of t3.service as the phase's read prints them (`pid ppid etimes comm`), as measured on running containers. */
const base = ["  389     1  4217 node-MainThread", "  466   389  4216 t3-resource-mon"];
const idle = [...base, " 3630   389  4091 claude", " 3631  3630  4091 sh"];

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

/** One run of the phase against a container in `status`, whose /etc/machine-info holds `written`, and whose T3 (the fixture's) answers `label` or not at all. */
async function restart(options: { status?: string; down?: boolean; label?: string; written?: string; ps?: string[]; main?: number; unreadable?: boolean }): Promise<{ ok: boolean; output: string; result: Record<string, unknown>; systemctl: string[][] }> {
    const folder = Deno.makeTempDirSync();
    try {
        for (const dir of ["bin", "run", "containers/instance-7/etc"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/containers/instance-7/etc/machine-info`, options.written ?? "PRETTY_HOSTNAME=clone.shop\n");
        Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
            containers: { "instance-7": { status: options.status ?? "Running", t3: !options.down, t3_label: options.label ?? "clone.shop", ps: options.ps ?? idle, t3_main: options.main ?? 389, procs_unreadable: options.unreadable === true } } }));
        Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
            ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
            instance_id: 7, t3_port: 3773, name: "clone.shop", machine_info: "PRETTY_HOSTNAME=clone.shop\n", read_at: "2026-11-14T12:00:00.000Z",
            container: { project: "orbit-instances", name: "instance-7" },
        }));
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "restart", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", "restart_retries=2", "-e", "restart_delay=0", `${shipped}/conn-environment-restart.yml`],
            env: { HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            result: JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
            systemctl: JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)).systemctl ?? [],
        };
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
}

Deno.test({ name: "an idle running T3 restarts once and answers inside the container with the name; the result has the restart time", ignore: !available, async fn() {
    const run = await restart({});
    assert(run.ok, run.output);
    assertEquals(run.result.answer, "restarted");
    assert(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(String(run.result.restart_at)), String(run.result.restart_at));
    assertEquals(run.systemctl, [["try-restart", "--", "t3.service"]]);
} });

Deno.test({ name: "a container that froze or stopped after the read is skipped, and nothing restarts", ignore: !available, async fn() {
    for (const [status, reason] of [["Frozen", "frozen"], ["Stopped", "stopped"]]) {
        const run = await restart({ status });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "skipped", reason });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a container whose /etc/machine-info lacks the name is skipped, and nothing restarts", ignore: !available, async fn() {
    for (const written of ["PRETTY_HOSTNAME=instance-7\n", ""]) {
        const run = await restart({ written });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "skipped", reason: "name_not_written" });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a T3 that does not answer after the restart fails with conn.t3_unready, and the restart is not repeated", ignore: !available, async fn() {
    const run = await restart({ down: true });
    assert(!run.ok, run.output);
    assertEquals(run.result.error_code, "conn.t3_unready");
    assertEquals(run.systemctl.filter((call) => call[0] === "try-restart").length, 1);
} });

Deno.test({ name: "a T3 that answers without the name fails with conn.t3_label_mismatch", ignore: !available, async fn() {
    const run = await restart({ label: "instance-7" });
    assert(!run.ok, run.output);
    assertEquals(run.result.error_code, "conn.t3_label_mismatch");
} });

Deno.test({ name: "an idle T3 restarts: its monitor, its own git, idle providers and the session's MCP servers started with them", ignore: !available, async fn() {
    for (const ps of [
        idle,
        // Measured: a live Claude session with a stdio MCP server (php) and npm's node, all started with claude.
        [...base, " 3630   389  4091 claude", " 3631  3630  4091 sh", " 3632  3630  4090 php", " 3633  3630  4088 npm", " 3640  3633  4085 node"],
        [...base, " 3700   389   600 codex"],
        [...base, " 3800   389     0 git"],
        base,
    ]) {
        const run = await restart({ ps });
        assert(run.ok, run.output);
        assertEquals(run.result.answer, "restarted");
        assertEquals(run.systemctl, [["try-restart", "--", "t3.service"]]);
    }
} });

Deno.test({ name: "a T3 terminal, open idle or running a command, keeps the container busy and nothing restarts", ignore: !available, async fn() {
    for (const [ps, terminals, commands] of [
        [[...idle, " 4100   389    30 bash"], 1, 0],
        [[...idle, " 4100   389   300 bash", " 4120  4100   200 node", " 4121  4120   200 esbuild"], 1, 2],
        [[...base, " 4100   389   300 zsh", " 4130  4100    40 sleep"], 1, 1],
    ] as const) {
        const run = await restart({ ps: [...ps] });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals, commands });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a provider's descendant started after its session, or a turn's tool, keeps the container busy", ignore: !available, async fn() {
    for (const [ps, commands] of [
        // Measured mid-turn: the provider runs a tool shell with its commands.
        [[...idle, "203790  3630     0 bash", "203791 203790     0 ps", "203792 203790     0 bash"], 3],
        // A dev server an earlier turn started, 60 s after the session began.
        [[...idle, " 3900  3630  4031 node"], 1],
    ] as const) {
        const run = await restart({ ps: [...ps] });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals: 0, commands });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "an orphan left in T3's service, such as a backgrounded dev server, keeps the container busy", ignore: !available, async fn() {
    // Measured: node processes reparented to PID 1 that stay in t3.service's cgroup.
    const run = await restart({ ps: [...base, " 5000     1 90000 MainThread", " 5001     1 90000 MainThread"] });
    assert(run.ok, run.output);
    assertEquals(run.result, { answer: "busy", reason: "processes", terminals: 0, commands: 2 });
    assertEquals(run.systemctl, []);
} });

Deno.test({ name: "a process read that fails or does not hold systemd's MainPID keeps the container busy: it fails closed", ignore: !available, async fn() {
    for (const options of [{ unreadable: true }, { main: 0 }, { main: 777 }, { ps: [] as string[] }]) {
        const run = await restart(options);
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes_unreadable" });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test("the provider names of the process rule are the binaries the recipe ships", () => {
    const recipe = JSON.parse(Deno.readTextFileSync(new URL("../../apps/gateway/resources/machine-software/recipe.json", import.meta.url)));
    const bins: string[] = [];
    const walk = (value: unknown, key = "") => {
        if (value === null || typeof value !== "object") return;
        if ((key === "claude-code" || key === "codex") && "bin" in value) bins.push(...Object.values((value as { bin: Record<string, string> }).bin).map((path) => path.split("/").at(-1)!));
        for (const [child, nested] of Object.entries(value)) walk(nested, child);
    };
    walk(recipe);
    const template = Deno.readTextFileSync(new URL("../ansible/templates/t3-service-processes.j2", import.meta.url));
    const named = [...template.matchAll(/selectattr\('comm', 'in', \[([^\]]+)\]\)/g)].flatMap((match) => match[1].split(",").map((name) => name.trim().replaceAll("'", "")));
    assertEquals(bins.sort(), ["claude", "codex"]);
    assertEquals(named.sort(), bins.sort());
});
