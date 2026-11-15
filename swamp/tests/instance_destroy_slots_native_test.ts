/**
 * Runs the real `operation-instance-destroy` workflow on the release-pinned Swamp binary: nine container removals of one Node at once,
 * as a burst of Conn removals sends them. Every behavior is a local shell stub that records its rendered name and its start and end;
 * "Delete the container" holds its name for a while, as the playbook does. Swamp fails a step that waits for a name longer than
 * SWAMP_LOCK_TIMEOUT_MS (60 s live, shortened here): with one name per Node the later removals failed (Operations 23368 and 23496).
 * With the slot names (`<family>-<node>-<instance_id % 64>`) no removal waits on another one's name.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
// Each container delete holds its name this long; a step that waits longer than the lock timeout fails.
const holdSeconds = 2;
const lockTimeoutMs = 5000;

async function fixture() {
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-destroy-slots-" });
    const auth = await nativeAuth(folder);
    const env = { ...(auth.env ?? {}), SWAMP_LOCK_TIMEOUT_MS: String(lockTimeoutMs) };
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary!, { args: [...args, "--json"], cwd: folder, ...auth, env }).output();
        return { ok: done.success, stdout: text(done.stdout), stderr: text(done.stderr) };
    };
    const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
    assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    const name = "operation-instance-destroy";
    const created = JSON.parse((await call("workflow", "create", name)).stdout);
    const workflow = parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${name}.yaml`, import.meta.url))) as RecordValue;
    workflow.id = created.id;
    delete workflow.vaults;
    for (const job of workflow.jobs as RecordValue[]) {
        delete job.labels;
        for (const step of job.steps as RecordValue[]) {
            delete step.labels;
            const task = step.task as RecordValue;
            if (task.type !== "behavior") continue;
            const hold = step.name === "Delete the container" ? `timeout ${holdSeconds} tail -f /dev/null || true; ` : "";
            step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: task.behaviorName, methodName: "execute", inputs: {
                run: `s=$(date +%s%N); ${hold}printf '%s|%s|%s|%s\\n' "$STEP" "$NAME" "$s" "$(date +%s%N)" >> '${folder}/calls'`,
                env: { STEP: String(step.name), NAME: task.behaviorName } } };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", name);
    assert(valid.ok, valid.stderr + valid.stdout);
    // A Route-less container removal as DevelopmentInstanceDestroyProjection sends it: no Route, no teardown, no database.
    const input = (instanceId: number) => JSON.stringify({
        operation_id: instanceId, node_id: 9, kind: "instance.destroy", execution_secrets: {},
        intent: {
            instance_id: instanceId, force: true,
            container_destroy: { instance_id: instanceId, name: `instance-${instanceId}` },
            members: [{ instance_id: instanceId, route_id: null }], additional_route_ids: [],
            ssh_execution: { target: { host: "10.44.0.9", user: "orbit", port: 22, host_keys: ["ssh-ed25519 fixture"] }, identity_file: "/none" },
            native_runtime: { processes: [] }, teardown_steps: [], push_check_paths: [], source_paths: [],
            direct_destroy: { node_user: "orbit", database: { servers: [], sqlite: [] }, hibernation: [0, 1, 2].map((i) => ({ instance_id: instanceId, path: `/h/${instanceId}.${i}` })) },
        },
    });
    const run = async (instanceId: number) => {
        const child = new Deno.Command(binary!, { args: ["workflow", "run", name, "--stdin", "--json"], cwd: folder, ...auth, env,
            stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(input(instanceId)));
        await writer.close();
        return await child.output();
    };
    const calls = () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((line) => {
        const [step, name, start, end] = line.split("|");
        return { step, name, start: BigInt(start), end: BigInt(end) };
    });
    return { run, calls, cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

Deno.test({ name: "pinned destroy: nine container removals of one Node at once all succeed, none waits on another's step name", ignore: !binary,
    async fn() {
        const f = await fixture();
        try {
            // Clones made together have consecutive IDs: nine slots of 64 on Node 9.
            const ids = [3201, 3202, 3203, 3204, 3205, 3206, 3207, 3208, 3209];
            const runs = await Promise.all(ids.map((id) => f.run(id)));
            runs.forEach((r, i) => assert(r.success, `removal of ${ids[i]}: ${text(r.stdout)}${text(r.stderr)}`));
            const calls = f.calls();
            const deletes = calls.filter((c) => c.step === "Delete the container");
            assertEquals(deletes.map((c) => c.name).sort(), ids.map((id) => `id-delete-the-container-9-${id % 64}`).sort());
            // Each removal's own steps take turns under its own slot; no two removals share a name.
            for (const id of ids) {
                const own = calls.filter((c) => c.name.endsWith(`-9-${id % 64}`)).map((c) => c.step);
                assertEquals(own.sort(), ["Clean container files", "Delete the container", "Prepare container removal", "Read container removal",
                    "Remove hibernation files", "Remove hibernation files", "Remove hibernation files"].sort());
            }
            assertEquals(calls.length, ids.length * 7);
        } finally {
            f.cleanup();
        }
    } });
