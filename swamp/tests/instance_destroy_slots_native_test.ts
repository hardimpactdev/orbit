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
    // The removal and its database child, each behavior a stub; "Delete the container" and "Drop databases and users" hold their name.
    for (const flow of ["operation-database-destroy", name]) {
        const created = JSON.parse((await call("workflow", "create", flow)).stdout);
        const workflow = parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${flow}.yaml`, import.meta.url))) as RecordValue;
        workflow.id = created.id;
        delete workflow.vaults;
        for (const job of workflow.jobs as RecordValue[]) {
            delete job.labels;
            for (const step of job.steps as RecordValue[]) {
                delete step.labels;
                const task = step.task as RecordValue;
                if (task.type !== "behavior") continue;
                const hold = ["Delete the container", "Drop databases and users"].includes(String(step.name))
                    ? `timeout ${holdSeconds} tail -f /dev/null || true; ` : "";
                step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: task.behaviorName, methodName: "execute", inputs: {
                    run: `s=$(date +%s%N); ${hold}printf '%s|%s|%s|%s\\n' "$STEP" "$NAME" "$s" "$(date +%s%N)" >> '${folder}/calls'`,
                    env: { STEP: String(step.name), NAME: task.behaviorName } } };
            }
        }
        Deno.writeTextFileSync(created.path, stringify(workflow));
        const valid = await call("workflow", "validate", flow);
        assert(valid.ok, valid.stderr + valid.stdout);
    }
    const ssh = { target: { host: "10.44.0.5", user: "orbit", port: 22, host_keys: ["ssh-ed25519 fixture"] }, identity_file: "/none" };
    const database = { driver: "pgsql", ssh_execution: ssh, admin_client: ["psql"] };
    const secret = (part: string) => ({ vault: "orbit-control-inputs", key: `database-destroy-1-${part}` });
    // A Route-less container removal as DevelopmentInstanceDestroyProjection sends it: no Route and no teardown. With `owned`, it owns a
    // database on the server of Node 5.
    const input = (instanceId: number, owned = false) => JSON.stringify({
        operation_id: instanceId, node_id: 9, kind: "instance.destroy",
        execution_secrets: owned ? Object.fromEntries(["fence", "drop", "gone", "restore"].map((part) => [`server_1_${part}`, secret(part)])) : {},
        intent: {
            instance_id: instanceId, force: true,
            container_destroy: { instance_id: instanceId, name: `instance-${instanceId}` },
            members: [{ instance_id: instanceId, route_id: null }], additional_route_ids: [],
            ssh_execution: { target: { host: "10.44.0.9", user: "orbit", port: 22, host_keys: ["ssh-ed25519 fixture"] }, identity_file: "/none" },
            native_runtime: { processes: [] }, teardown_steps: [], push_check_paths: [], source_paths: [],
            direct_destroy: { node_user: "orbit", database: { servers: owned ? [{ server_id: 1, node_id: 5, intent: database }] : [], sqlite: [] }, hibernation: [0, 1, 2].map((i) => ({ instance_id: instanceId, path: `/h/${instanceId}.${i}` })) },
        },
    });
    // database:destroy of its own, as the Gateway starts it: no slot.
    const direct = JSON.stringify({ operation_id: 1, node_id: 5, kind: "database.destroy", intent: database,
        execution_secrets: Object.fromEntries(["fence", "drop", "gone", "restore"].map((part) => [part, secret(part)])) });
    const run = async (instanceId: number, owned = false) => {
        const flow = instanceId === 0 ? "operation-database-destroy" : name;
        const child = new Deno.Command(binary!, { args: ["workflow", "run", flow, "--stdin", "--json"], cwd: folder, ...auth, env,
            stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(instanceId === 0 ? direct : input(instanceId, owned)));
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
            // The first two own a database on one server: its steps take each removal's slot too.
            const runs = await Promise.all(ids.map((id, i) => f.run(id, i < 2)));
            runs.forEach((r, i) => assert(r.success, `removal of ${ids[i]}: ${text(r.stdout)}${text(r.stderr)}`));
            const calls = f.calls();
            const deletes = calls.filter((c) => c.step === "Delete the container");
            assertEquals(deletes.map((c) => c.name).sort(), ids.map((id) => `id-delete-the-container-9-${id % 64}`).sort());
            // Each removal's own steps take turns under its own slot; no two removals share a name.
            const database = ["Check they are gone", "Drop databases and users", "Fence new clients"];
            for (const [i, id] of ids.entries()) {
                const own = calls.filter((c) => new RegExp(`^[a-z-]+(-5)?-9-${id % 64}(-[0-2])?$`).test(c.name)).map((c) => c.step);
                assertEquals(own.sort(), ["Clean container files", "Delete the container", "Prepare container removal", "Read container removal",
                    "Remove hibernation files", "Remove hibernation files", "Remove hibernation files", "Stop the container",
                    ...(i < 2 ? database : [])].sort());
                // The container stops before its databases drop, so none of its clients holds a session that refuses the drop.
                const mine = calls.filter((c) => new RegExp(`^[a-z-]+(-5)?-9-${id % 64}(-[0-2])?$`).test(c.name));
                const stop = mine.find((c) => c.step === "Stop the container")!;
                const delete_ = mine.find((c) => c.step === "Delete the container")!;
                assert(stop.end <= delete_.start, `removal of ${id}: the stop ends before the deletion starts`);
                if (i < 2) assert(stop.end <= mine.find((c) => c.step === "Fence new clients")!.start, `removal of ${id}: the stop ends before the drop starts`);
            }
            assertEquals(calls.filter((c) => c.name.startsWith("database-destroy-ssh-5-9-")).length, 6);
            // A database:destroy of its own keeps one name per database Node.
            const before = calls.length;
            const done = await f.run(0);
            assert(done.success, text(done.stdout) + text(done.stderr));
            assertEquals([...new Set(f.calls().slice(before).map((c) => c.name))], ["database-destroy-ssh-5"]);
        } finally {
            f.cleanup();
        }
    } });
