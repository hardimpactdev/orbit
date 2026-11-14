/**
 * Runs the real `operation-instance-complete` workflow on the release-pinned Swamp binary. Every machine step is a local shell stub that
 * records its name; the Route and its withdrawal are stub child workflows; `finish` is the real `complete_result` behavior, and
 * `accepted` the real assert. It proves the guards, the levels and the result expressions as the engine evaluates them for a normal
 * clone, and that a dead unit, a failed Route and a cancel withdraw the Route.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
type Mode = "success" | "dead-unit" | "route-failure" | "cancel" | "published";
/** The long completion steps a cancel must stop: the services, the database fill and the fleet start. */
const LONG = ["clone-services", "server-database-fill", "clone-fleet"] as const;
type Long = typeof LONG[number];
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};
const receipt = '{"error_code":"instance.container_process_inactive","process_failure":{"process_id":41,"unit":"orbit-process-41-worker.service","state":"failed"}}';

async function fixture(mode: Mode, slow: Long = "clone-services") {
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-ansible-" });
    const auth = await nativeAuth(folder);
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary!, { args: [...args, "--json"], cwd: folder, ...auth }).output();
        return { ok: done.success, stdout: text(done.stdout), stderr: text(done.stderr) };
    };
    const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
    assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    const models = `${folder}/extensions/models`;
    Deno.mkdirSync(models, { recursive: true });
    for (const name of ["assets.ts", "complete_result.ts", "migrate_result.ts", "result.ts"]) {
        Deno.copyFileSync(new URL(`../extensions/behaviors/operations/instance-dev-command/${name}`, import.meta.url), `${models}/${name}`);
    }
    // One stub child for the Route create and for the withdrawal: it records its phase, and the Route fails in route-failure mode.
    const child = JSON.parse((await call("workflow", "create", "complete-route-child")).stdout);
    Deno.writeTextFileSync(child.path, stringify({
        id: child.id, name: child.name,
        inputs: { type: "object", properties: { phase: { type: "string" } }, required: ["phase"] },
        jobs: [{ name: "route", steps: [{ name: "result", task: { type: "behavior", behaviorType: "command/shell", behaviorName: "complete-route-child",
            methodName: "execute", inputs: { run: `printf '%s\\n' "\${{ inputs.phase }}" >> '${folder}/calls'; touch '${folder}/\${{ inputs.phase }}'` +
                (mode === "route-failure" ? `; test "\${{ inputs.phase }}" != route` : "") } } }] }],
    }));
    const created = JSON.parse((await call("workflow", "create", "complete-native")).stdout);
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-complete.yaml", import.meta.url))) as RecordValue;
    workflow.id = created.id;
    workflow.name = created.name;
    delete workflow.vaults;
    delete workflow.labels;
    for (const job of workflow.jobs as RecordValue[]) {
        for (const step of job.steps as RecordValue[]) {
            const name = String(step.name);
            delete step.labels;
            if (name === "accepted") continue;
            if (name === "finish") {
                ((step.task as RecordValue).inputs as RecordValue).folder = folder;
                continue;
            }
            if (name === "route" || name === "withdraw-route") {
                step.task = { type: "workflow", workflowIdOrName: child.name, inputs: { phase: name } };
                continue;
            }
            let run = `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === slow && mode === "cancel") run += "; sleep 30";
            // The real step accepts every exit code (`okExitCodes: any`), so a refusal ends `succeeded` with its output; a shell stub
            // has no such option, so it prints the receipt and ends 0, as that step records it.
            if (name === "clone-check") run += mode === "dead-unit" ? `; printf '%s\\n' '${receipt}'` : `; printf '%s\\n' '{"phase":"check","changes":[]}'`;
            step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: `complete-${name}`, methodName: "execute",
                inputs: { run } };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", created.name);
    assert(valid.ok, valid.stderr + valid.stdout);
    const input = JSON.stringify({
        operation_id: 1, node_id: 3, kind: "instance.complete",
        execution_secrets: { process_environments: { vault: "orbit-control-inputs", key: "instance-complete-processes-1" } },
        intent: { instance_id: 8, container: { clone: { source: "instance-7" }, project: "orbit-instances" }, database: null,
            server_database: slow === "server-database-fill" ? { node_id: 5, fill: ["true"],
                intent: { ssh_execution: { target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" }, owner_client: ["true"] } } : null,
            ...(slow === "clone-fleet" ? { fleet: { seed: "e".repeat(40) } } : {}),
            // A Route its create published is not captured again: the completion carries none (StartInstanceCompleteAction).
            server_valkey: null, route: mode === "published" ? null : { leaf_indexes: [0] }, route_mutations: mode === "published" ? null : { artifacts: {}, restore: {} },
            ssh_execution: { target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" } },
    });
    const history = async () => JSON.parse((await call("workflow", "history", "get", created.name)).stdout);
    const steps = async (): Promise<Record<string, RecordValue>> =>
        Object.fromEntries((await history()).jobs.flatMap((job: { steps: RecordValue[] }) => job.steps).map((step: RecordValue) => [step.name, step]));
    return { folder, auth, created, input, steps, slow, cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

/**
 * Starts the run in its own process group; a cancel sends SIGTERM once the slow stub runs and the Route has ended, and the result
 * gives the seconds from that signal to the run's end.
 */
async function execute(f: Awaited<ReturnType<typeof fixture>>, cancel: boolean): Promise<Deno.CommandOutput & { stopSeconds: number }> {
    const child = new Deno.Command("setsid", { args: [binary!, "workflow", "run", f.created.name, "--stdin", "--json"], cwd: f.folder,
        ...f.auth, stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(f.input));
    await writer.close();
    let finished = false;
    const completion = child.output().then((done) => {
        finished = true;
        return done;
    });
    try {
        if (cancel) {
            // The Route job runs beside the completion steps. Under load it can still be in its child workflow when the slow stub
            // starts; the cancel then cuts the Route too, and the result names the Route. So the cancel waits until the run's own
            // history reads the slow step running and the Route succeeded. The slow stub sleeps 30 s, longer than this wait.
            const until = Date.now() + 25000;
            let steps: Record<string, RecordValue> = {};
            while (!finished && Date.now() < until) {
                if (exists(`${f.folder}/${f.slow}`)) {
                    try {
                        steps = await f.steps();
                    } catch { /* The run may be writing its history; read it again. */ }
                    if (stub(steps, f.slow) === "running" && stub(steps, "route") === "succeeded") break;
                }
                await new Promise((resolve) => setTimeout(resolve, 250));
            }
            assertEquals([stub(steps, f.slow), stub(steps, "route")], ["running", "succeeded"], `The cancel must come while ${f.slow} runs and after the Route.`);
            const signalled = Date.now();
            child.kill("SIGTERM");
            return { ...(await completion), stopSeconds: (Date.now() - signalled) / 1000 };
        }
        return { ...(await completion), stopSeconds: 0 };
    } finally {
        if (!finished) {
            try {
                Deno.kill(-child.pid, "SIGKILL");
            } catch { /* Only this fixture's group. */ }
        }
    }
}

const stub = (steps: Record<string, RecordValue>, name: string) => String(steps[name]?.status);
const result = (steps: Record<string, RecordValue>) => JSON.parse(String((steps.finish.outputs as RecordValue).stdout));

Deno.test({ name: "pinned complete: a normal clone with a Route runs every step and ends ready", ignore: !binary, async fn() {
    const f = await fixture("success");
    try {
        const done = await execute(f, false);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        for (const name of ["input", "clone-input", "clone-services", "clone-check", "route-csr-0", "route", "finish", "clone-private-cleanup", "cleanup", "accepted"]) {
            assertEquals(stub(steps, name), "succeeded", name);
        }
        for (const name of ["database-snapshot", "server-database", "server-valkey", "clone-fleet", "withdraw-route"]) assertEquals(stub(steps, name), "skipped", name);
        assertEquals([result(steps).ready, result(steps).failed_step], [true, undefined]);
        assert(!exists(`${f.folder}/withdraw-route`));
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned complete: a dead unit fails the check with its receipt and withdraws the Route", ignore: !binary, async fn() {
    const f = await fixture("dead-unit");
    try {
        const done = await execute(f, false);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        // The check step accepts the phase's exit, so its output reaches finish.
        assertEquals([stub(steps, "clone-check"), stub(steps, "finish"), stub(steps, "withdraw-route"), stub(steps, "accepted")], ["succeeded", "succeeded", "succeeded", "failed"]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.error_code, value.process_failure.process_id], [false, "check", "instance.container_process_inactive", 41]);
        assert(exists(`${f.folder}/withdraw-route`));
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned complete: a failed Route names the Route and is withdrawn", ignore: !binary, async fn() {
    const f = await fixture("route-failure");
    try {
        const done = await execute(f, false);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([stub(steps, "route"), stub(steps, "withdraw-route"), stub(steps, "clone-private-cleanup")], ["failed", "succeeded", "succeeded"]);
        // The Route job never stops the container steps.
        assertEquals([stub(steps, "clone-services"), stub(steps, "clone-check")], ["succeeded", "succeeded"]);
        assertEquals([result(steps).failed_step, result(steps).error_code], ["route", "instance.create_route_failed"]);
    } finally {
        f.cleanup();
    }
} });

for (const [slow, step] of [["clone-services", "workspace"], ["server-database-fill", "database"], ["clone-fleet", "fleet"]] as const) {
    Deno.test({ name: `pinned complete: a cancel during ${slow} stops it within 10 s, withdraws the Route and removes the private folder`, ignore: !binary, async fn() {
        const f = await fixture("cancel", slow);
        try {
            const done = await execute(f, true);
            assert(!done.success, text(done.stdout) + text(done.stderr));
            // The step sleeps 30 s; the cancel ends it and the whole result job well inside the bound the removal waits on.
            assert(done.stopSeconds < 10, `${slow} took ${done.stopSeconds} s to stop`);
            const steps = await f.steps();
            assertEquals(stub(steps, "clone-check"), "skipped");
            assertEquals([stub(steps, "withdraw-route"), stub(steps, "clone-private-cleanup")], ["succeeded", "succeeded"], JSON.stringify(Object.keys(steps)));
            assertEquals([result(steps).ready, result(steps).failed_step], [false, step]);
            assert(exists(`${f.folder}/withdraw-route`));
        } finally {
            f.cleanup();
        }
    } });
}

Deno.test({ name: "pinned complete: a clone whose create published its address runs no Route step and withdraws nothing", ignore: !binary, async fn() {
    const f = await fixture("published");
    try {
        const done = await execute(f, false);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        for (const name of ["clone-input", "clone-services", "clone-check", "finish", "accepted"]) assertEquals(stub(steps, name), "succeeded", name);
        assertEquals([stub(steps, "route"), stub(steps, "withdraw-route"), "route-csr-0" in steps], ["skipped", "skipped", false]);
        assertEquals(result(steps).ready, true);
    } finally {
        f.cleanup();
    }
} });
