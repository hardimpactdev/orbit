/**
 * The power helper behind the fixture's `systemd-run` (docs/reference/prepared-restore.md#the-power-helper): it answers each command as
 * the helper's contract says, on the Incus fixture's state.json and the fixture's cgroup files, so the sleep phase's own steps run for
 * real. The helper's engine has its own tests (instance_power_helper_test.py). `helper` in state.json scripts what the fixture does:
 * `busy` calls answer with systemd's UnitExists refusal first, `crash` names a command that ends with exit code 70, `worker_resumed`
 * makes a restore find the container that the fast worker resumed meanwhile, `thaw_fails` keeps a container frozen that the kernel
 * holds frozen while Incus calls it running (its `cgroup.freeze` is 1), `pause_fails` comes from the Incus fixture. Each call is one JSON line in `helper-calls`, beside the Incus fixture's `calls`.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
const args = Deno.args;
const unit = args.find((arg) => arg.startsWith("--unit="))!.slice("--unit=".length);
const command = args.slice(args.indexOf("/usr/local/libexec/orbit-instance-power") + 1);
const helper = state.helper ?? {};
const save = () => Deno.writeTextFileSync(file, JSON.stringify(state));

if ((helper.busy ?? 0) > 0) {
    helper.busy -= 1;
    state.helper = helper;
    save();
    console.error(`Failed to start transient service unit: Unit ${unit}.service was already loaded or has a fragment file.`);
    Deno.exit(1);
}
Deno.writeTextFileSync(`${folder}/helper-calls`, JSON.stringify(command) + "\n", { append: true });
const stdin = command[0] === "prepare" || command[0] === "restore" ? new TextDecoder().decode(await new Response(Deno.stdin.readable).arrayBuffer()) : "";
if (stdin !== "") Deno.writeTextFileSync(`${folder}/helper-intent.json`, stdin);
if (helper.crash === command[0]) {
    console.log(JSON.stringify({ schema_version: 1, outcome: "failed", error_code: "instance.power_helper_failed" }));
    Deno.exit(70);
}

const instance = Number(command[2]);
const intent = stdin === "" ? null : JSON.parse(stdin);
const project = intent?.incus_project ?? state.helper_project ?? "orbit-instances";
const cgroup = `${folder}/cgroup/lxc.payload.${project}_instance-${instance}`;
const container = state.containers?.[`instance-${instance}`];
const status = container ? String(container.status).toLowerCase() : "missing";
const out = (body: Record<string, unknown>) => console.log(JSON.stringify({ schema_version: 1, command: command[0], instance_id: instance, error_code: null, ...body }));
/** The kernel's own freeze of the container's cgroup: 1 while a freeze holds it, whatever the Incus fixture says. */
const kernelFrozen = () => { try { return Deno.readTextFileSync(`${cgroup}/cgroup.freeze`).trim() === "1"; } catch { return false; } };
const walk = (path: string, relative = ""): string[] =>
    [relative, ...[...Deno.readDirSync(path)].filter((entry) => entry.isDirectory).flatMap((entry) => walk(`${path}/${entry.name}`, relative === "" ? entry.name : `${relative}/${entry.name}`))];

if (command[0] === "prepare") {
    state.helper_project = project;
    if (status === "frozen") {
        out({ outcome: "already_frozen", state_before: "frozen", state_after: "frozen", swap_subtree: state.sleep?.swap_subtree ?? [],
            prepared_restore: state.sleep?.prepared_restore ?? { version: 1, status: "skipped", reason: "no_plan" } });
    } else if (status === "stopped") {
        out({ outcome: "stopped", state_before: "stopped", state_after: "stopped", swap_subtree: [], prepared_restore: { version: 1, status: "not_applicable" } });
    } else if (kernelFrozen()) {
        out({ outcome: "failed", error_code: "instance.container_frozen_unmanaged", state_before: "running", state_after: "frozen", swap_subtree: [] });
    } else {
        const zero = walk(cgroup).filter((path) => { try { return Deno.readTextFileSync(`${cgroup}/${path}/memory.swap.max`).trim() === "0"; } catch { return false; } });
        for (const path of zero) Deno.writeTextFileSync(`${cgroup}/${path}/memory.swap.max`, "max");
        state.opened = zero;
        if (state.pause_fails) {
            for (const path of zero) Deno.writeTextFileSync(`${cgroup}/${path}/memory.swap.max`, "0");
            save();
            out({ outcome: "failed", error_code: "instance.container_freeze_failed", state_before: "running", state_after: "running", swap_subtree: [] });
        } else {
            container.status = "Frozen";
            Deno.writeTextFileSync(`${cgroup}/memory.reclaim`, Deno.readTextFileSync(`${cgroup}/memory.current`).trim());
            const prepared = project === "orbit-instances-nested"
                ? { version: 1, status: "skipped", reason: "placement_unproven" }
                : { version: 1, status: "ready", generation: "6f1c1d1e-0000-4000-8000-000000000001", processes: 18, resident_mapping_bytes: 336117760,
                    metadata_bytes: 1357673, prepare_seconds: 0.291 };
            state.plan = prepared.status === "ready";
            state.sleep = { sleep_operation_id: Number(command[4]), swap_subtree: zero.filter((path) => path !== ""), prepared_restore: prepared };
            save();
            out({ outcome: "frozen", state_before: "running", state_after: "frozen", swap_subtree: state.sleep.swap_subtree, prepared_restore: prepared,
                seconds: { pause: 0.01, capture: 0.2, publish: 0.05, reclaim: 0.03 } });
        }
    }
} else if (command[0] === "residual") {
    if (status !== "frozen" || state.sleep?.sleep_operation_id !== Number(command[4])) {
        out({ outcome: "not_recorded", state_before: status, state_after: status, prepared_restore: null });
    } else {
        // The counters settle a moment after the reclaim: `settle` reads still see the memory before it.
        const reads = state.residual_reads ?? 0;
        state.residual_reads = reads + 1;
        const stat = Deno.readTextFileSync(`${cgroup}/memory.stat`);
        const counter = (name: string) => reads < (helper.settle ?? 0) ? 4096 : Number(stat.match(new RegExp(`^${name} (\\d+)$`, "m"))![1]);
        state.sleep.prepared_restore = { ...state.sleep.prepared_restore, residual_anon_bytes: counter("anon"), residual_file_bytes: counter("file") };
        save();
        out({ outcome: "recorded", state_before: "frozen", state_after: "frozen", prepared_restore: state.sleep.prepared_restore });
    }
} else if (command[0] === "restore") {
    if (status === "missing") {
        out({ outcome: "missing", state_before: "missing", state_after: "missing", swap_subtree: [], prepared_restore: null });
    } else if (status === "running" && kernelFrozen()) {
        // Incus lost the freeze: the helper thaws the cgroup itself, or fails clearly with the container frozen.
        if (state.thaw_fails) {
            out({ outcome: "failed", error_code: "instance.container_frozen_unmanaged", state_before: "running", state_after: "frozen",
                swap_subtree: [], kernel_frozen: true });
        } else {
            Deno.writeTextFileSync(`${cgroup}/cgroup.freeze`, "0");
            for (const path of state.opened ?? []) { try { Deno.writeTextFileSync(`${cgroup}/${path}/memory.swap.max`, "0"); } catch { /* gone */ } }
            state.plan = false;
            state.opened = [];
            delete state.sleep;
            save();
            out({ outcome: "resumed", state_before: "running", state_after: "running", swap_subtree: [], kernel_frozen: true,
                prepared_restore: { version: 1, status: "skipped", reason: "plan_stale" }, seconds: { resume: 0.01, close: 0.001 } });
        }
    } else if (status !== "frozen") {
        if (status === "stopped") {
            state.plan = false;
            state.opened = [];
            delete state.sleep;
            save();
        }
        out({ outcome: status === "running" ? "already_running" : status, state_before: status, state_after: status, swap_subtree: [],
            prepared_restore: { version: 1, status: "not_applicable" } });
    } else if (helper.worker_resumed) {
        // The Node's fast worker held the lock and resumed the container before this call took it.
        container.status = "Running";
        save();
        out({ outcome: "already_running", state_before: "running", state_after: "running", swap_subtree: [],
            prepared_restore: { version: 1, status: "not_applicable" } });
    } else if (state.resume_fails) {
        out({ outcome: "failed", error_code: "instance.container_start_failed", state_before: "frozen", state_after: "frozen", swap_subtree: [],
            prepared_restore: { version: 1, status: "skipped", reason: state.plan ? "plan_invalid" : "no_plan" } });
    } else {
        container.status = "Running";
        for (const path of state.opened ?? []) { try { Deno.writeTextFileSync(`${cgroup}/${path}/memory.swap.max`, "0"); } catch { /* gone */ } }
        const restored = state.plan ? { version: 1, status: "complete", processes: 18, pages_read: 82060 } : { version: 1, status: "skipped", reason: "no_plan" };
        state.plan = false;
        state.opened = [];
        delete state.sleep;
        save();
        out({ outcome: "resumed", state_before: "frozen", state_after: "running", swap_subtree: [], prepared_restore: restored,
            seconds: { load: 0.01, resume: 0.02, close: 0.001 } });
    }
} else if (command[0] === "discard") {
    state.plan = false;
    if (command[3] !== "--plan-only") {
        delete state.sleep;
        state.opened = [];
    }
    const removed = command[3] === "--plan-only" ? true : !(state.discarded ?? []).includes(instance);
    if (command[3] !== "--plan-only") state.discarded = [...new Set([...(state.discarded ?? []), instance])];
    save();
    out({ outcome: "discarded", operation_id: null, removed });
} else if (command[0] === "capabilities") {
    out({ outcome: "checked", capabilities: {} });
}
