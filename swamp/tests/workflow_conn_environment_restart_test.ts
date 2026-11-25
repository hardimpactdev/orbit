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

/** The members of t3.service as the phase's read prints them (`pid ppid sid tty comm`), as measured on running containers. */
const base = ["  389     1   389 ?        node-MainThread", "  466   389   466 ?        t3-resource-mon"];
// Measured idle: the provider leads its own session, and its setup `sh` stays in it.
const idle = [...base, " 3630   389  3630 ?        claude", " 3631  3630  3630 ?        sh"];

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

/** One run of the phase against a container in `status`, whose /etc/machine-info holds `written`, and whose T3 (the fixture's) answers `label` or not at all. */
async function restart(options: { status?: string; down?: boolean; label?: string; written?: string; ps?: string[]; main?: number; unreadable?: boolean; children?: number; statMissing?: boolean; psFails?: number[]; exited?: number[] }): Promise<{ ok: boolean; output: string; result: Record<string, unknown>; systemctl: string[][] }> {
    const folder = Deno.makeTempDirSync();
    try {
        for (const dir of ["bin", "run", "containers/instance-7/etc"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/containers/instance-7/etc/machine-info`, options.written ?? "PRETTY_HOSTNAME=clone.shop\n");
        Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({
            containers: { "instance-7": { status: options.status ?? "Running", t3: !options.down, t3_label: options.label ?? "clone.shop", } } }));
        // T3's service as the phase's read finds it: the cgroup files, a /proc folder per live member, and stand-ins for systemctl and ps.
        const rootfs = `${folder}/containers/instance-7`;
        const cgroup = `${rootfs}/sys/fs/cgroup/system.slice/t3.service`;
        Deno.mkdirSync(cgroup, { recursive: true });
        const members = (options.ps ?? idle).map((line) => /^\s*(\d+)\s/.exec(line)?.[1] ?? "9999");
        if (!options.unreadable) Deno.writeTextFileSync(`${cgroup}/cgroup.procs`, members.map((pid) => `${pid}\n`).join(""));
        if (!options.statMissing) Deno.writeTextFileSync(`${cgroup}/cgroup.stat`, `nr_descendants ${options.children ?? 0}\nnr_dying_descendants 0\n`);
        for (const pid of members) if (!(options.exited ?? []).includes(Number(pid))) Deno.mkdirSync(`${rootfs}/proc/${pid}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/ps-table`, (options.ps ?? idle).map((line, index) => `${members[index]}\t${line}\n`).join(""));
        Deno.writeTextFileSync(`${folder}/ps-fails`, (options.psFails ?? []).map((pid) => `${pid}\n`).join(""));
        Deno.writeTextFileSync(`${folder}/bin/systemctl`, `#!/bin/sh\necho ${options.main ?? 389}\n`);
        Deno.writeTextFileSync(`${folder}/bin/ps`, `#!/bin/sh\nfor p; do :; done\ngrep -qx "$p" '${folder}/ps-fails' && exit 1\nline=$(grep "^$p\t" '${folder}/ps-table' | cut -f2-) || exit 1\nprintf '%s\\n' "$line"\n`);
        Deno.chmodSync(`${folder}/bin/systemctl`, 0o755);
        Deno.chmodSync(`${folder}/bin/ps`, 0o755);
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

Deno.test({ name: "an idle T3 restarts: its monitor, its own git, providers and what stays in the claude session", ignore: !available, async fn() {
    for (const ps of [
        idle,
        // A live Claude session: its stdio MCP servers (php, npm and its node) stay in the provider's session.
        [...idle, " 3632  3630  3630 ?        php", " 3633  3630  3630 ?        npm", " 3640  3633  3630 ?        node"],
        [...base, " 3700   389  3700 ?        codex"],
        [...base, " 3800   389   389 ?        git"],
        base,
    ]) {
        const run = await restart({ ps });
        assert(run.ok, run.output);
        assertEquals(run.result.answer, "restarted");
        assertEquals(run.systemctl, [["try-restart", "--", "t3.service"]]);
    }
} });

Deno.test({ name: "a T3 terminal, open idle, running a command or an exec'd provider name, keeps the container busy", ignore: !available, async fn() {
    for (const [ps, terminals, commands] of [
        [[...idle, " 4100   389  4100 pts/0    bash"], 1, 0],
        [[...idle, " 4100   389  4100 pts/0    bash", " 4120  4100  4100 pts/0    node", " 4121  4120  4100 pts/0    esbuild"], 1, 2],
        [[...base, " 4100   389  4100 pts/1    zsh", " 4130  4100  4100 pts/1    sleep"], 1, 1],
        // `exec claude` or `exec git` typed in a terminal keeps T3 as the parent and that name, but holds the terminal's tty.
        [[...idle, " 4200   389  4200 pts/2    claude"], 1, 0],
        [[...idle, " 4300   389  4300 pts/3    git"], 1, 0],
    ] as const) {
        const run = await restart({ ps: [...ps] });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals, commands });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a turn's tool tree, in its own session, keeps the container busy however soon after the provider it started", ignore: !available, async fn() {
    for (const [ps, commands] of [
        // Measured mid-turn: the tool shell leads its own session, and its commands stay in it.
        [[...idle, "316382  3630 316382 ?        bash", "316383 316382 316382 ?        sleep"], 2],
        // A first turn's `npm run dev`, two seconds after T3 started the provider: a dev server the turn leaves running.
        [[...idle, " 3634  3630  3634 ?        npm", " 3635  3634  3634 ?        node"], 2],
        // The provider's own session member whose parent left the service is an orphan: not the session's setup.
        [[...idle, " 3660     1  3630 ?        node"], 1],
        // codex: no session allowance; any descendant keeps it busy.
        [[...base, " 3700   389  3700 ?        codex", " 3701  3700  3700 ?        sh"], 1],
    ] as const) {
        const run = await restart({ ps: [...ps] });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals: 0, commands });
        assertEquals(run.systemctl, []);
    }
} });

/** A Conn Server's service, measured on conn-server 0.1.0 with one idle Claude session: the SDK host (`node`, named MainThread) leads
 * no session, and the `claude` it runs leads its own, as under T3. */
const conn = ["  184     1   184 ?        conn-server", " 1165   184   184 ?        MainThread"];
const connIdle = [...conn, " 1173  1165  1173 ?        claude", " 1174  1173  1173 ?        sh"];

Deno.test({ name: "an idle Conn Server restarts: its SDK host, the providers under it and what stays in the claude session", ignore: !available, async fn() {
    for (const ps of [connIdle, [...connIdle, " 1175  1173  1173 ?        php"], [...conn, " 1180  1165  1180 ?        codex"], conn]) {
        const run = await restart({ ps, main: 184 });
        assert(run.ok, run.output);
        assertEquals(run.result.answer, "restarted");
        assertEquals(run.systemctl, [["try-restart", "--", "t3.service"]]);
    }
} });

Deno.test({ name: "a terminal, a tool shell or another process under the Conn Server's SDK host keeps the container busy, and so does a MainThread under T3", ignore: !available, async fn() {
    for (const [ps, main, terminals, commands] of [
        [[...connIdle, " 1200  1165  1200 pts/0    bash"], 184, 0, 1],
        [[...connIdle, " 1300  1173  1300 ?        bash", " 1301  1300  1300 ?        sleep"], 184, 0, 2],
        [[...connIdle, " 1400  1165   184 ?        node"], 184, 0, 1],
        [[...conn, " 1180  1165  1180 ?        codex", " 1181  1180  1180 ?        sh"], 184, 0, 1],
        [[...conn, " 1500   184  1500 pts/0    MainThread"], 184, 1, 0],
        [[...idle, " 3900   389  3900 ?        MainThread"], 389, 1, 0],
    ] as const) {
        const run = await restart({ ps: [...ps], main });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals, commands });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "an orphan left in T3's service, or a member line that does not parse, keeps the container busy", ignore: !available, async fn() {
    // Measured: node processes of past turns reparented to PID 1, in their own sessions, still in t3.service's cgroup.
    for (const [ps, commands] of [[[...base, " 5000     1  2944 ?        node", " 5001     1  6586 ?        node"], 2], [[...idle, "garbled line"], 1]] as const) {
        const run = await restart({ ps: [...ps] });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes", terminals: 0, commands });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a read that fails, a MainPID outside the service, a descendant cgroup or an unreadable count keeps the container busy: it fails closed", ignore: !available, async fn() {
    // A ps that fails for a member still alive, after it printed the members before it, as a read that broke half way.
    for (const options of [{ unreadable: true }, { main: 0 }, { main: 777 }, { ps: [] as string[] }, { children: 1 }, { statMissing: true }, { psFails: [3631] }]) {
        const run = await restart(options);
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "busy", reason: "processes_unreadable" });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test("the provider names of the process rule are the binaries the recipe ships", () => {
    const recipe = JSON.parse(Deno.readTextFileSync(new URL("../../apps/gateway/resources/machine-software/core.yaml", import.meta.url)));
    const bins: string[] = [];
    const walk = (value: unknown, key = "") => {
        if (value === null || typeof value !== "object") return;
        if ((key === "claude-code" || key === "codex") && "bin" in value) bins.push(...Object.values((value as { bin: Record<string, string> }).bin).map((path) => path.split("/").at(-1)!));
        for (const [child, nested] of Object.entries(value)) walk(nested, child);
    };
    walk(recipe);
    const template = Deno.readTextFileSync(new URL("../ansible/templates/t3-service-processes.j2", import.meta.url));
    const list = (name: string) => template.match(new RegExp(`set ${name} = \\[([^\\]]+)\\]`))![1].split(",").map((entry) => entry.trim().replaceAll("'", ""));
    assertEquals(bins.sort(), ["claude", "codex"]);
    assertEquals(list("providers").sort(), bins.sort());
    assert(list("session_providers").every((name) => list("providers").includes(name)));
});

Deno.test({ name: "a member that exited between the list and its ps line may be left out, and the idle T3 restarts", ignore: !available, async fn() {
    const run = await restart({ ps: [...idle, " 3700  3630  3700 ?        bash"], psFails: [3700], exited: [3700] });
    assert(run.ok, run.output);
    assertEquals(run.result.answer, "restarted");
} });
