/** The two parts of a container's sleep (the asleep marker, the freeze), run by real Ansible against the Incus fixture, and the shipped sleep and
 * wake step expressions. A wake's resume and marker work run in its `start` part: ansible_instance_container_test.ts. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string }[]; task: { behaviorName?: string; inputs: { run?: string; env?: Record<string, string> } } };
const steps = (kind: string) =>
    (parse(Deno.readTextFileSync(new URL(`../workflows/workflow-operation-instance-${kind}.yaml`, import.meta.url))) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";

Deno.test("each sleep and wake step runs its charted part with the shipped Ansible settings; the wake is one playbook", () => {
    const context = { inputs: { node_id: 9, intent: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const evaluate = (value: string) => value.startsWith("${{") ? String(cel.evaluate(value.slice(3, -2).trim(), context)) : value;
    for (const [kind, parts] of [["sleep", [["Mark the Route asleep", "--tags asleep", "instance-container-power.yml"], ["Freeze the container", "--tags freeze", "instance-container-power.yml"]]],
        ["wake", [["Wake the container", "--tags start", "instance-container.yml"]]]] as const) {
        const all = steps(kind);
        assertEquals(all.map((step) => step.name), ["input", ...parts.map((part) => part[0]), "result", "cleanup"]);
        assertEquals(all[parts.length + 1].dependsOn?.[0].step, parts.at(-1)![0]);
        for (const [name, tags, playbook] of parts) {
            const step = all.find((candidate) => candidate.name === name)!;
            assert(step.task.inputs.run!.includes(`${tags} "$ORBIT_ANSIBLE/${playbook}"`), name);
            const env = Object.fromEntries(Object.entries(step.task.inputs.env!).map(([key, value]) => [key, evaluate(value)]));
            assertEquals(env.ANSIBLE_CONFIG, `${shipped}/ansible.cfg`);
            assertEquals(env.ORBIT_ANSIBLE, shipped);
            assertEquals(env.ORBIT_RUN_ID, runId);
            assert(Deno.statSync(`${shipped}/${playbook}`).isFile);
            assert(evaluate(step.task.behaviorName!).endsWith("-9"), name);
        }
    }
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const user = new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();
const group = new TextDecoder().decode(new Deno.Command("id", { args: ["-gn"] }).outputSync().stdout).trim();

/** One part of instance-container-power.yml with a Caddy admin answer and, for a clone, a T3 that serves or not (the fixture's T3). */
async function part(
    tag: "asleep" | "freeze",
    options: { caddy?: string; t3?: boolean; clone?: boolean; marker?: boolean; status?: string; anonAfter?: number; pauseFails?: boolean; stopFails?: boolean; resumeFails?: boolean; unreadable?: boolean },
): Promise<{ ok: boolean; output: string; result: Record<string, unknown>; marker: boolean; status: string; swap: string; reclaim: string; calls: string[] }> {
    const folder = Deno.makeTempDirSync();
    const servers: Deno.HttpServer[] = [];
    try {
        for (const dir of ["bin", "run", "hibernation", "containers/instance-7/home/agent"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/state.json`,
            JSON.stringify({ pause_fails: options.pauseFails === true, stop_fails: options.stopFails === true, resume_fails: options.resumeFails === true, containers: { "instance-7": { status: options.status ?? "Running", t3: options.t3 === true } } }));
        // The container's cgroup: swap closed as Incus writes it, and what is left in RAM after the reclaim.
        const cgroup = `${folder}/cgroup/lxc.payload.orbit-instances_instance-7`;
        Deno.mkdirSync(cgroup, { recursive: true });
        Deno.writeTextFileSync(`${cgroup}/memory.swap.max`, "0");
        Deno.writeTextFileSync(`${cgroup}/memory.reclaim`, "");
        if (!options.unreadable) Deno.writeTextFileSync(`${cgroup}/memory.current`, "524242944\n");
        Deno.writeTextFileSync(`${cgroup}/memory.stat`, `anon ${options.anonAfter ?? 0}\nfile 0\nshmem 0\n`);
        Deno.writeTextFileSync(
            `${folder}/bin/incus`,
            `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`,
        );
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        const marker = `${folder}/hibernation/app-instance-7.asleep`;
        if (options.marker) Deno.writeTextFileSync(marker, "");
        const caddy = Deno.serve({ port: 0, hostname: "127.0.0.1", onListen() {} }, () => new Response(options.caddy ?? "{}"));
        servers.push(caddy);
        const clone = options.clone === false ? null : { t3: { internal_port: 3773, ready_seconds: 1, unit: "t3.service" } };
        Deno.writeTextFileSync(
            `${folder}/run/intent.json`,
            JSON.stringify({
                ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                container: { project: "orbit-instances", name: "instance-7", clone },
                marker,
                freeze: { resident_anon_mib: 32 },
            }),
        );
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", tag, "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `caddy_admin=http://127.0.0.1:${caddy.addr.port}`,
                "-e", `marker_owner=${user}`, "-e", `marker_group=${group}`, "-e", `cgroup_root=${folder}/cgroup`, `${shipped}/instance-container-power.yml`],
            env: { HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            result: JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
            marker: (() => { try { return Deno.statSync(marker).isFile; } catch { return false; } })(),
            status: JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)).containers["instance-7"].status,
            swap: Deno.readTextFileSync(`${folder}/cgroup/lxc.payload.orbit-instances_instance-7/memory.swap.max`),
            reclaim: Deno.readTextFileSync(`${folder}/cgroup/lxc.payload.orbit-instances_instance-7/memory.reclaim`),
            calls: (() => { try { return Deno.readTextFileSync(`${folder}/calls`).split("\n").filter(Boolean).map((line) => (JSON.parse(line) as string[]).filter((arg) => !["--project", "orbit-instances"].includes(arg))[0]); } catch { return []; } })(),
        };
    } finally {
        for (const server of servers) await server.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

const loaded = JSON.stringify({ apps: { http: { servers: { orbit: { routes: [{ match: [{ file: { root: "/data/caddy/orbit/hibernation", try_files: ["/app-instance-7.asleep"] } }] }] } } } } });

Deno.test({ name: "a sleep writes the marker once Caddy's loaded site has the check, and refuses without changing anything when it lacks it", ignore: !available, async fn() {
    const ready = await part("asleep", { caddy: loaded });
    assert(ready.ok, ready.output);
    assertEquals(ready.result, {});
    assert(ready.marker);

    const unready = await part("asleep", { caddy: JSON.stringify({ apps: { http: {} } }) });
    assert(!unready.ok, unready.output);
    assertEquals(unready.result, { error_code: "instance.sleep_route_unready" });
    assert(!unready.marker);
} });

Deno.test({ name: "a freeze opens swap for the container, pauses it and pushes its memory to swap; the marker stays", ignore: !available, async fn() {
    const frozen = await part("freeze", { marker: true });
    assert(frozen.ok, frozen.output);
    assertEquals(frozen.result, { sleep: "frozen" });
    // The reclaim asks for the whole memory the container holds.
    assertEquals([frozen.status, frozen.swap, frozen.reclaim], ["Frozen", "max", "524242944"]);
    assert(frozen.marker);
    // The reclaim follows the pause, so no frozen process pulls a page back.
    assertEquals(frozen.calls.filter((call) => call !== "query"), ["pause"]);
} });

Deno.test({ name: "a freeze whose memory stays in RAM (swap is full) resumes and stops the container instead", ignore: !available, async fn() {
    const full = await part("freeze", { marker: true, anonAfter: 300 * 1048576 });
    assert(full.ok, full.output);
    assertEquals(full.result, { sleep: "stopped" });
    assertEquals(full.status, "Stopped");
    assertEquals(full.calls.filter((call) => call !== "query"), ["pause", "resume", "stop"]);
    assert(full.marker);
} });

Deno.test({ name: "a failed stop after a full swap closes swap again and removes the marker of the running container", ignore: !available, async fn() {
    const failed = await part("freeze", { marker: true, anonAfter: 300 * 1048576, stopFails: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals([failed.status, failed.swap], ["Running", "0"]);
    assert(!failed.marker);
} });

Deno.test({ name: "a failed resume after a full swap fails the sleep with the container frozen behind its marker", ignore: !available, async fn() {
    const failed = await part("freeze", { marker: true, anonAfter: 300 * 1048576, resumeFails: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals(failed.status, "Frozen");
    assert(failed.marker);
} });

Deno.test({ name: "an unreadable memory count after the pause keeps the container frozen behind its marker, without a reclaim", ignore: !available, async fn() {
    const kept = await part("freeze", { marker: true, anonAfter: 300 * 1048576, unreadable: true });
    assert(kept.ok, kept.output);
    assertEquals(kept.result, { sleep: "frozen" });
    assertEquals([kept.status, kept.swap, kept.reclaim], ["Frozen", "max", ""]);
    assert(kept.marker);
    assertEquals(kept.calls.filter((call) => call !== "query"), ["pause"]);
} });

Deno.test({ name: "a failed pause closes swap again, removes the marker and fails with its own code; the container keeps running", ignore: !available, async fn() {
    const failed = await part("freeze", { marker: true, pauseFails: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals([failed.status, failed.swap], ["Running", "0"]);
    assert(!failed.marker);
} });

Deno.test({ name: "a freeze leaves a Frozen or Stopped container as it is", ignore: !available, async fn() {
    for (const [status, sleep] of [["Frozen", "frozen"], ["Stopped", "stopped"]]) {
        const done = await part("freeze", { marker: true, status });
        assert(done.ok, done.output);
        assertEquals(done.result, { sleep });
        assertEquals([done.status, done.swap, done.reclaim], [status, "0", ""]);
        assertEquals(done.calls.filter((call) => call !== "query"), []);
    }
} });
