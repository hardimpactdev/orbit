/** The one phase of a container's sleep (the asleep marker, then the power helper's prepare and its counter reads), run by real Ansible against
 * the Incus fixture and the helper fixture, and the shipped sleep and wake step expressions. A wake's resume and marker work run in its
 * `start` part: ansible_instance_container_test.ts. The helper's own engine: instance_power_helper_test.py. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { join } from "jsr:@std/path@1";
import { helperCalls as readHelperCalls, installPowerHelper } from "./_support/power_helper.ts";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string }[]; task: { behaviorName?: string; inputs: { run?: string; env?: Record<string, string> } } };
const steps = (kind: string) =>
    (parse(Deno.readTextFileSync(new URL(`../workflows/workflow-operation-instance-${kind}.yaml`, import.meta.url))) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";

Deno.test("the sleep and the wake each run one charted phase with the shipped Ansible settings; the sleep's names its Operation", () => {
    const context = { inputs: { operation_id: 41, node_id: 9, intent: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const evaluate = (value: string) => value.startsWith("${{") ? String(cel.evaluate(value.slice(3, -2).trim(), context)) : value;
    for (const [kind, parts] of [["sleep", [["Sleep the container", "--tags prepared_sleep", "instance-container-power.yml"]]],
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
            assertEquals(env.ORBIT_OPERATION_ID, "41");
        }
    }
});

Deno.test("every workflow step that runs instance-container.yml names its Operation, which the power helper's restore records", () => {
    const context = { inputs: { operation_id: 41, node_id: 9, intent: {} }, steps: {}, run: { id: runId } };
    let found = 0;
    for (const kind of ["wake", "start", "deploy-direct", "create"]) {
        for (const step of steps(kind).filter((candidate) => candidate.task?.inputs?.run?.includes("instance-container.yml"))) {
            found++;
            const value = step.task.inputs.env?.ORBIT_OPERATION_ID;
            assert(value !== undefined, `${kind}: ${step.name}`);
            assertEquals(String(cel.evaluate(value.slice(3, -2).trim(), context)), "41");
        }
    }
    assert(found >= 14, String(found));
});

Deno.test("a nested-fleet clone's start runs the deployment's start with the Club key, then the clone's fleet check and resume, and times the fleet", () => {
    const context = { inputs: { node_id: 9, operation_id: 31, intent: {}, execution_secrets: { club_key: { vault: "orbit-control-inputs", key: "nested-fleet-club-api-key" } } },
        steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const vault = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false })
        .registerFunction("vaultget(dyn, dyn): string", (v: unknown, k: unknown) => `${v}/${k}`);
    const evaluate = (value: string) => value.startsWith("${{") ? String(vault.evaluate(value.slice(3, -2).trim().replaceAll("vault.get(", "vaultget("), context)) : value;
    const all = steps("start");
    assertEquals(all.map((step) => step.name), ["input", "Start the container", "Start the inner fleet", "result", "cleanup"]);
    const container = all[1], fleet = all[2];
    assert(container.task.inputs.run!.includes('--tags start "$ORBIT_ANSIBLE/instance-container.yml"'));
    assertEquals(evaluate(container.task.inputs.env!.ORBIT_CLUB_KEY), "orbit-control-inputs/nested-fleet-club-api-key");
    // The same two phases as the completion's clone-fleet, each with its bound, and no Club key in the fleet step.
    const complete = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-complete.yaml", import.meta.url))) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps).find((step) => step.name === "clone-fleet")!;
    // The completion's command, and only the start may restart T3 for the proxy bypass.
    assertEquals(fleet.task.inputs.run!.split("; rc=$?")[0].split("s=$(date +%s%N); ")[1],
        complete.task.inputs.run!.replace("--tags fleet_resume", "--tags fleet_resume -e fleet_proxy_restart_t3=true"));
    assert(!complete.task.inputs.run!.includes("fleet_proxy_restart_t3"));
    assert(fleet.task.inputs.run!.includes('> "$ORBIT_RUN/fleet_ms"'));
    assertEquals(fleet.task.inputs.env!.ORBIT_CLUB_KEY, undefined);
    assertEquals(fleet.dependsOn?.[0].step, "Start the container");
    for (const step of [container, fleet]) {
        const env = Object.fromEntries(Object.entries(step.task.inputs.env!).map(([key, value]) => [key, evaluate(value)]));
        assertEquals([env.ANSIBLE_CONFIG, env.ORBIT_ANSIBLE, env.ORBIT_RUN_ID, env.ORBIT_OPERATION_ID], [`${shipped}/ansible.cfg`, shipped, runId, "31"]);
        assert(evaluate(step.task.behaviorName!).endsWith("-9"), step.name);
    }
});

Deno.test("a start's result adds the fleet's seconds when the fleet step ran, and is the phases' result as it is when it did not", () => {
    const run = steps("start").find((step) => step.name === "result")!.task.inputs.run!;
    const folder = Deno.makeTempDirSync();
    try {
        Deno.writeTextFileSync(`${folder}/result.json`, JSON.stringify({ commit: null, club_key: "unchanged" }));
        const read = () => {
            const done = new Deno.Command("sh", { args: ["-c", run.replace("/opt/orbit/ansible/venv/bin/python", "python3")], env: { ORBIT_RUN: folder } }).outputSync();
            assert(done.success, new TextDecoder().decode(done.stderr));
            return JSON.parse(new TextDecoder().decode(done.stdout));
        };
        assertEquals(read(), { commit: null, club_key: "unchanged" });
        Deno.writeTextFileSync(`${folder}/fleet_ms`, "91437\n");
        assertEquals(read(), { commit: null, club_key: "unchanged", fleet_seconds: 91.4 });
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});

Deno.test("the start's fleet step writes its milliseconds and keeps the exit code of the phase that failed", () => {
    const run = steps("start").find((step) => step.name === "Start the inner fleet")!.task.inputs.run!;
    for (const [playbook, success] of [["true", true], ["false", false]] as const) {
        const folder = Deno.makeTempDirSync();
        try {
            const done = new Deno.Command("sh", { args: ["-c", run.replaceAll("/opt/orbit/ansible/venv/bin/ansible-playbook", playbook)],
                env: { ORBIT_RUN: folder, ORBIT_ANSIBLE: folder, PATH: Deno.env.get("PATH")! } }).outputSync();
            assertEquals(done.success, success);
            assert(/^\d+\n$/.test(Deno.readTextFileSync(`${folder}/fleet_ms`)));
        } finally {
            Deno.removeSync(folder, { recursive: true });
        }
    }
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const user = new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();
const group = new TextDecoder().decode(new Deno.Command("id", { args: ["-gn"] }).outputSync().stdout).trim();

/** The shipped playbook with its tasks, and the power helper of the test copy (_support/power_helper.ts). */
function playbook(folder: string): string {
    const copy = `${folder}/ansible`;
    Deno.mkdirSync(`${copy}/tasks`, { recursive: true });
    for (const entry of Deno.readDirSync(shipped)) {
        if (!["tasks", "files"].includes(entry.name)) Deno.symlinkSync(join(shipped, entry.name), join(copy, entry.name));
    }
    for (const entry of Deno.readDirSync(`${shipped}/tasks`)) {
        if (!["instance-power-helper.yml", "instance-power-call.yml"].includes(entry.name)) Deno.symlinkSync(join(shipped, "tasks", entry.name), join(copy, "tasks", entry.name));
    }
    installPowerHelper(folder, copy);
    return copy;
}

type Sleep = { ok: boolean; output: string; result: Record<string, unknown>; marker: boolean; status: string; swap: string; reclaim: string; calls: string[];
    inner: Record<string, string>; intent: Record<string, unknown> | null; helper: string[][]; installed: boolean };

/** The sleep phase with a Caddy admin answer, the Incus fixture and the helper fixture behind a `systemd-run` of the fixture. */
async function sleep(
    options: { caddy?: string; clone?: boolean; web?: boolean; status?: string; anonAfter?: number; pauseFails?: boolean; stopFails?: boolean; resumeFails?: boolean;
        nested?: boolean; inner?: Record<string, string>; route?: boolean | null; busy?: number; crash?: string; settle?: number; earlier?: Record<string, unknown> },
): Promise<Sleep> {
    const folder = Deno.makeTempDirSync();
    const servers: Deno.HttpServer[] = [];
    try {
        for (const dir of ["bin", "run", "hibernation", "state", "systemd", "libexec"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        const project = options.nested ? "orbit-instances-nested" : "orbit-instances";
        Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ pause_fails: options.pauseFails === true, stop_fails: options.stopFails === true,
            resume_fails: options.resumeFails === true, containers: { "instance-7": { status: options.status ?? "Running", t3: false } },
            helper: { busy: options.busy ?? 0, crash: options.crash, settle: options.settle ?? 0 }, helper_project: project,
            ...(options.earlier ? { sleep: options.earlier } : {}) }));
        // The container's cgroup: swap closed as Incus writes it, and what is left in RAM after the reclaim.
        const cgroup = `${folder}/cgroup/lxc.payload.${project}_instance-7`;
        Deno.mkdirSync(cgroup, { recursive: true });
        for (const [path, value] of Object.entries(options.inner ?? {})) {
            Deno.mkdirSync(`${cgroup}/${path.split("/").slice(0, -1).join("/")}`, { recursive: true });
            Deno.writeTextFileSync(`${cgroup}/${path}`, value);
        }
        Deno.writeTextFileSync(`${cgroup}/memory.swap.max`, "0");
        Deno.writeTextFileSync(`${cgroup}/memory.reclaim`, "");
        Deno.writeTextFileSync(`${cgroup}/memory.current`, "524242944\n");
        Deno.writeTextFileSync(`${cgroup}/memory.stat`, `anon ${options.anonAfter ?? 0}\nfile 0\nshmem 0\n`);
        Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        const marker = `${folder}/hibernation/app-instance-7.asleep`;
        const caddy = Deno.serve({ port: 0, hostname: "127.0.0.1", onListen() {} }, () => new Response(options.caddy ?? loaded));
        servers.push(caddy);
        const clone = options.clone === false ? null : { t3: { internal_port: 3773, ready_seconds: 1, unit: "t3.service" } };
        Deno.writeTextFileSync(
            `${folder}/run/intent.json`,
            JSON.stringify({
                ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                container: { instance_id: 7, project, name: "instance-7", address: "10.10.0.7", clone, web_config: options.web ? { listen: 8080 } : null, web_port: options.web ? 8080 : null,
                    ...(options.nested ? { allowed_devices: {} } : {}) },
                marker,
                ...(options.route === null ? {} : { route: options.route ?? true }),
                freeze: { resident_anon_mib: 32 },
            }),
        );
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "prepared_sleep", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", `caddy_admin=http://127.0.0.1:${caddy.addr.port}`,
                "-e", `marker_owner=${user}`, "-e", `marker_group=${group}`, `${playbook(folder)}/instance-container-power.yml`],
            env: { HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, ORBIT_OPERATION_ID: "41", PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                ANSIBLE_HOME: `${folder}/ansible-home`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        const lines = (() => { try { return Deno.readTextFileSync(`${folder}/calls`).split("\n").filter(Boolean).map((line) => JSON.parse(line) as string[]); } catch { return []; } })();
        const helper = readHelperCalls(folder);
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            result: JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
            marker: (() => { try { return Deno.statSync(marker).isFile; } catch { return false; } })(),
            status: JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)).containers["instance-7"].status,
            swap: Deno.readTextFileSync(`${cgroup}/memory.swap.max`),
            reclaim: Deno.readTextFileSync(`${cgroup}/memory.reclaim`),
            calls: lines.map((call) => call.filter((arg) => !["--project", project].includes(arg))[0]),
            helper,
            inner: Object.fromEntries(Object.keys(options.inner ?? {}).map((path) => [path, (() => { try { return Deno.readTextFileSync(`${cgroup}/${path}`); } catch { return "gone"; } })()])),
            intent: (() => { try { return JSON.parse(Deno.readTextFileSync(`${folder}/helper-intent.json`)); } catch { return null; } })(),
            installed: (() => { try { return Deno.statSync(`${folder}/libexec/orbit-instance-power`).isFile; } catch { return false; } })(),
        };
    } finally {
        for (const server of servers) await server.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

const loaded = JSON.stringify({ apps: { http: { servers: { orbit: { routes: [{ match: [{ file: { root: "/data/caddy/orbit/hibernation", try_files: ["/app-instance-7.asleep"] } }] }] } } } } });
const ready = { version: 1, status: "ready", generation: "6f1c1d1e-0000-4000-8000-000000000001", processes: 18, resident_mapping_bytes: 336117760, metadata_bytes: 1357673,
    prepare_seconds: 0.291 };
const helperCalls = (sleep: Sleep) => sleep.helper.map((call) => call[0] === "discard" ? call.join(" ") : call[0]);

Deno.test({ name: "a sleep refuses before it changes anything when Caddy's loaded site lacks the asleep check", ignore: !available, async fn() {
    const unready = await sleep({ caddy: JSON.stringify({ apps: { http: {} } }) });
    assert(!unready.ok, unready.output);
    assertEquals(unready.result, { error_code: "instance.sleep_route_unready" });
    assertEquals([unready.marker, unready.calls, unready.status], [false, [], "Running"]);
} });

Deno.test({ name: "a sleep is one phase: the marker, the helper's install, its prepare through the Instance's unit, and counter reads until RAM holds nothing", ignore: !available, async fn() {
    const frozen = await sleep({ settle: 2, web: true });
    assert(frozen.ok, frozen.output);
    assertEquals(frozen.result, { sleep: "frozen", swap_subtree: 0, prepared_restore: { ...ready, residual_anon_bytes: 0, residual_file_bytes: 0 } });
    assertEquals([frozen.status, frozen.reclaim], ["Frozen", "524242944"]);
    assert(frozen.marker);
    assert(frozen.installed);
    // The counters settle after two reads: the phase reads again until both are 0, through the same lock.
    assertEquals(helperCalls(frozen), ["prepare", "residual", "residual", "residual"]);
    assertEquals(frozen.helper[0], ["prepare", "--instance", "7", "--operation", "41"]);
    assertEquals(frozen.intent, { schema_version: 1, instance_id: 7, operation_id: 41, incus_project: "orbit-instances", incus_name: "instance-7",
        web: { address: "10.10.0.7", port: 8080 } });
} });

Deno.test({ name: "a sleep of an Instance without a published Route or a web unit checks no Caddy site and sends the helper no web target", ignore: !available, async fn() {
    const done = await sleep({ caddy: JSON.stringify({ apps: { http: {} } }), route: false });
    assert(done.ok, done.output);
    assertEquals(done.result.sleep, "frozen");
    assertEquals(done.intent?.web, null);
    assert(done.marker);
} });

Deno.test({ name: "a sleep intent without `route` fails before it checks or writes anything", ignore: !available, async fn() {
    const done = await sleep({ route: null });
    assert(!done.ok, done.output);
    assertEquals(done.result, { error_code: "instance.container_asleep_failed" });
    assert(done.output.includes("The sleep intent names no `route`"), done.output);
    assertEquals([done.marker, done.calls], [false, []]);
} });

/** A nested-fleet copy as measured: the inner Incus writes 0 for its payload roots; polkit has its own 32 MiB; the rest is open. */
const nestedCgroups = {
    "lxc.payload.nested-gateway/memory.swap.max": "0",
    "lxc.payload.nested-app-dev/memory.swap.max": "0",
    "lxc.payload.nested-app-prod/memory.swap.max": "0",
    "system.slice/polkit.service/memory.swap.max": "33554432",
    "init.scope/memory.swap.max": "max",
};

Deno.test({ name: "a nested-fleet sleep counts the inner cgroups the helper opened and records no plan", ignore: !available, async fn() {
    const frozen = await sleep({ nested: true, inner: nestedCgroups });
    assert(frozen.ok, frozen.output);
    assertEquals(frozen.result, { sleep: "frozen", swap_subtree: 3,
        prepared_restore: { version: 1, status: "skipped", reason: "placement_unproven", residual_anon_bytes: 0, residual_file_bytes: 0 } });
    assertEquals(frozen.intent?.incus_project, "orbit-instances-nested");
} });

Deno.test({ name: "a sleep whose memory stays in RAM drops the plan, resumes through the helper and stops the container; the marker stays", ignore: !available, async fn() {
    const full = await sleep({ anonAfter: 300 * 1048576 });
    assert(full.ok, full.output);
    assertEquals(full.result, { sleep: "stopped", swap_subtree: 0,
        prepared_restore: { version: 1, status: "skipped", reason: "stopped", residual_anon_bytes: 300 * 1048576, residual_file_bytes: 0 } });
    assertEquals(full.status, "Stopped");
    // Twenty reads, then the plan goes before the resume, so no page is read back while swap is full.
    assertEquals(helperCalls(full), ["prepare", ...Array(20).fill("residual"), "discard --instance 7 --plan-only", "restore"]);
    assertEquals(full.calls.at(-1), "stop");
    assert(full.marker);
} });

Deno.test({ name: "a nested-fleet sleep whose memory stays in RAM never stops: the helper resumes it and closes its swap, the marker goes, and it fails", ignore: !available, async fn() {
    const kept = await sleep({ nested: true, inner: nestedCgroups, anonAfter: 1531 * 1048576 });
    assert(!kept.ok, kept.output);
    assertEquals(kept.result, { error_code: "instance.container_freeze_failed" });
    assertEquals([kept.status, kept.swap], ["Running", "0"]);
    assertEquals(kept.inner, nestedCgroups);
    assert(!kept.marker);
    assert(!kept.calls.includes("stop"));
    assertEquals(helperCalls(kept).slice(-2), ["discard --instance 7 --plan-only", "restore"]);
} });

Deno.test({ name: "a failed stop after a full swap leaves the resumed container serving without the marker", ignore: !available, async fn() {
    const failed = await sleep({ anonAfter: 300 * 1048576, stopFails: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals([failed.status, failed.swap], ["Running", "0"]);
    assert(!failed.marker);
} });

Deno.test({ name: "a failed resume after a full swap fails the sleep with the container frozen behind its marker", ignore: !available, async fn() {
    const failed = await sleep({ anonAfter: 300 * 1048576, resumeFails: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals(failed.status, "Frozen");
    assert(failed.marker);
} });

Deno.test({ name: "a failed pause leaves the container running with its swap closed, removes the marker and fails with the helper's code", ignore: !available, async fn() {
    const failed = await sleep({ pauseFails: true, inner: { "inner.slice/memory.swap.max": "0" } });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result, { error_code: "instance.container_freeze_failed" });
    assertEquals([failed.status, failed.swap, failed.inner], ["Running", "0", { "inner.slice/memory.swap.max": "0" }]);
    assert(!failed.marker);
    assertEquals(helperCalls(failed), ["prepare"]);
} });

Deno.test({ name: "a sleep leaves a Frozen container with its earlier result and a Stopped one as it is, without counter reads", ignore: !available, async fn() {
    const earlier = { sleep_operation_id: 40, swap_subtree: ["a.slice", "b.slice"], prepared_restore: { ...ready, residual_anon_bytes: 0, residual_file_bytes: 0 } };
    const again = await sleep({ status: "Frozen", earlier });
    assert(again.ok, again.output);
    assertEquals(again.result, { sleep: "frozen", swap_subtree: 2, prepared_restore: earlier.prepared_restore });
    assertEquals(helperCalls(again), ["prepare"]);

    const stopped = await sleep({ status: "Stopped" });
    assert(stopped.ok, stopped.output);
    assertEquals(stopped.result, { sleep: "stopped", swap_subtree: 0, prepared_restore: { version: 1, status: "not_applicable" } });
    assertEquals([stopped.status, helperCalls(stopped)], ["Stopped", ["prepare"]]);
} });

Deno.test({ name: "a busy lock is tried again; one that stays busy fails `instance.power_helper_busy` and a crash `instance.power_helper_failed`, both behind the marker", ignore: !available, async fn() {
    const waited = await sleep({ busy: 3 });
    assert(waited.ok, waited.output);
    assertEquals(waited.result.sleep, "frozen");

    const busy = await sleep({ busy: 100 });
    assert(!busy.ok, busy.output);
    assertEquals(busy.result, { error_code: "instance.power_helper_busy" });
    assertEquals([busy.marker, busy.status, busy.helper], [true, "Running", []]);

    const crashed = await sleep({ crash: "prepare" });
    assert(!crashed.ok, crashed.output);
    assertEquals(crashed.result, { error_code: "instance.power_helper_failed" });
    assertEquals([crashed.marker, helperCalls(crashed)], [true, ["prepare"]]);
} });
