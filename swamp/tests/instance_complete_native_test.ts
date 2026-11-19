/**
 * Runs the real `operation-instance-complete` workflow on the release-pinned Swamp binary. Every machine step is a local shell stub that
 * records its name; the Route and its withdrawal are stub child workflows; `finish` is the real `complete_result` behavior, and
 * `accepted` the real assert. It proves the guards, the levels and the result expressions as the engine evaluates them for a normal
 * clone, and that a dead unit, a failed Route and a cancel withdraw the Route. `clone-services`, the one Node step, keeps its real argv
 * and stdin expressions: the engine renders the launcher's argv and reads the completion's one copy (CloneStage::completion) in its real
 * size and shape through vault.get and registers it with the run's redactor, as on a Node; a two-part copy fails there, as release
 * 2080's did.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
type Mode = "success" | "dead-unit" | "route-failure" | "cancel" | "published" | "two-part";
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
// The completion's one copy in its real size and shape (CloneStage::completion): the real release files as `stage`, the Process files as
// `private` and again as `processes`. An environment file has several lines; JSON keeps the copy on one.
const read = (path: string) => Deno.readTextFileSync(new URL(`../${path}`, import.meta.url));
const processes = { "/etc/orbit/processes/41/worker.env": "QUEUE_CONNECTION=database\nAPP_KEY=base64:" + "k".repeat(44) + "\nDB_PASSWORD=" + "p".repeat(32) + "\n" };
const stage = { launcher: read("scripts/instance_clone_launch.py"), script: read("scripts/instance_clone.py"), container: { instance_id: 8 },
    guest: read("scripts/instance_clone_guest.py"), network: read("ansible/instance_network.py"), account: "orbit",
    bundle: { node_services: read("ansible/instance_node_services.py"), machine_name: read("ansible/instance_machine_name.py"), root_disk: read("ansible/instance_root_disk.py"),
        mail: read("ansible/instance_mail.py"), bridge: read("ansible/instance_bridge.py"), node: read("ansible/instance_node_runtime.py"),
        workspace_owner: read("ansible/instance_workspace_owner.py") } };
const frame = (files: Record<string, string>) => JSON.stringify({ stage, private: JSON.stringify({ processes: files }), processes: files });
const copy = frame(processes);
const receipt = '{"error_code":"instance.container_process_inactive","process_failure":{"process_id":41,"unit":"orbit-process-41-worker.service","state":"failed"}}';
// What the Node step's stand-in receives: the argv and the value the engine resolved through vault.get, as SSH would carry them. It
// reads them as the shipped launcher does (`stage complete`: `stage` staged, `private` to the phase) and prints what the phase prints:
// the services line, then the check's line, or a dead unit's receipt when the Process files name one.
const sink = `import { z } from "npm:zod@4.4.3";
export const behavior = { type: "@orbit-test/complete-sink", version: "2026.11.15.2",
    resources: { terminal: { schema: z.object({ stdout: z.string(), exitCode: z.number() }), lifetime: "infinite", garbageCollection: 10 } },
    methods: { take: { description: "Take the completion copy", arguments: z.object({ copy: z.string(), argv: z.array(z.string()) }),
        execute: async (args: { copy: string; argv: string[] }, ctx: { writeResource(s: string, n: string, d: unknown): Promise<unknown> }) => {
            const value = JSON.parse(args.copy);
            if (args.argv[4] !== "print('launcher')" || args.argv.slice(-2).join(" ") !== "stage complete" || !args.argv[5].startsWith("/tmp/orbit-clone-8-"))
                throw new Error("not the launcher's argv");
            if (typeof value.stage?.script !== "string" || typeof value.private !== "string") throw new Error("not a completion copy");
            const processes = JSON.parse(value.private).processes;
            const check = processes.dead ? ${JSON.stringify(receipt)} : JSON.stringify({ phase: "check", changes: [] });
            return { dataHandles: [await ctx.writeResource("terminal", "terminal", { stdout: JSON.stringify({ phase: "services", changes: [] }) + "\\n" + check, exitCode: 0 })] };
        } } } };
`;

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
    const vault = await call("vault", "create", "local_encryption", "orbit-control-inputs");
    assert(vault.ok, vault.stderr + vault.stdout);
    // The Gateway's copy, put through stdin as its frame does. two-part: a copy with newlines, which one RegExp breaks (release 2080).
    // dead-unit: the stand-in reads a dead unit from the Process files it was given, as the guest's check would find one.
    const value = mode === "two-part" ? JSON.stringify(stage) + "\n" + JSON.stringify({ processes })
        : mode === "dead-unit" ? frame({ ...processes, dead: "1" }) : copy;
    const writer = new Deno.Command(binary!, { args: ["vault", "put", "orbit-control-inputs", "instance-complete-processes-1", "--json"], cwd: folder,
        ...auth, stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
    const stream = writer.stdin.getWriter();
    await stream.write(new TextEncoder().encode(value));
    await stream.close();
    const stored = await writer.output();
    assert(stored.success, text(stored.stderr));
    const models = `${folder}/extensions/models`;
    Deno.mkdirSync(`${models}/complete-sink`, { recursive: true });
    Deno.writeTextFileSync(`${models}/complete-sink/mod.ts`, sink);
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
            // A cancel cuts a running command, as it cuts the real SSH step; that case keeps the command stand-in below.
            if (name === "clone-services" && mode !== "cancel") {
                const real = (step.task as RecordValue).inputs as RecordValue;
                step.task = { type: "behavior", behaviorType: "@orbit-test/complete-sink", behaviorName: "complete-clone-services", methodName: "take",
                    inputs: { copy: real.stdin, argv: real.argv } };
                continue;
            }
            let run = `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === slow && mode === "cancel") run += "; sleep 30";
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
        intent: { instance_id: 8, clone_launcher: "print('launcher')", container: { clone: { source: "instance-7" }, project: "orbit-instances" }, database: null,
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
        // A Route the create did not publish needs the run folder: input runs, and cleanup removes it.
        for (const name of ["input", "clone-services", "route-csr-0", "route", "finish", "clone-private-cleanup", "cleanup", "accepted"]) {
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
        // The Node step ends 0 with the receipt as its output, so it reaches finish.
        assertEquals([stub(steps, "clone-services"), stub(steps, "finish"), stub(steps, "withdraw-route"), stub(steps, "accepted")], ["succeeded", "succeeded", "succeeded", "failed"]);
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
        assertEquals(stub(steps, "clone-services"), "succeeded");
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
        for (const name of ["clone-services", "finish", "accepted"]) assertEquals(stub(steps, name), "succeeded", name);
        // A plain clone writes no run folder: the input step and its cleanup skip.
        assertEquals([stub(steps, "input"), stub(steps, "cleanup")], ["skipped", "skipped"]);
        assertEquals([stub(steps, "route"), stub(steps, "withdraw-route"), "route-csr-0" in steps], ["skipped", "skipped", false]);
        assertEquals(result(steps).ready, true);
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned complete: the services step reads the real-size one-line copy through vault.get; a two-part copy fails with Regular expression too large", ignore: !binary, async fn() {
    // The copy this release's Gateway frames is one line of about 195 KB; Swamp masks it as one plain string.
    assert(!copy.includes("\n") && copy.length > 150_000, String(copy.length));
    const f = await fixture("two-part");
    try {
        const done = await execute(f, false);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals(stub(steps, "clone-services"), "failed");
        assert(JSON.stringify(steps["clone-services"]).includes("Regular expression too large"), JSON.stringify(steps["clone-services"]).slice(0, 400));
        // As in release 2080, the broken redactor fails every later step of the run too: nothing ends the completion ready.
        assertEquals([stub(steps, "finish"), stub(steps, "accepted")], ["failed", "skipped"]);
    } finally {
        f.cleanup();
    }
} });
