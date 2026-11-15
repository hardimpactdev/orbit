/**
 * Runs the real `operation-route-create` and `route-create-site` workflows on the release-pinned Swamp binary, as a clone's `clone-route`
 * calls them. Every behavior is a local shell stub that records the behavior name and the merge switch the engine evaluated: a clone's Node
 * steps take one of 16 names per Node (route id modulo 16), every other caller keeps its per-Node name, and only `merge: true` turns the merge on.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);

async function fixture() {
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-route-merge-" });
    const auth = await nativeAuth(folder);
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary!, { args: [...args, "--json"], cwd: folder, ...auth }).output();
        return { ok: done.success, stdout: text(done.stdout), stderr: text(done.stderr) };
    };
    const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
    assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    for (const name of ["route-create-site", "operation-route-create"]) {
        const created = JSON.parse((await call("workflow", "create", name)).stdout);
        const workflow = parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${name}.yaml`, import.meta.url))) as RecordValue;
        workflow.id = created.id;
        delete workflow.vaults;
        delete workflow.labels;
        for (const job of workflow.jobs as RecordValue[]) {
            delete job.labels;
            for (const step of job.steps as RecordValue[]) {
                delete step.labels;
                const task = step.task as RecordValue;
                if (task.type !== "behavior") continue;
                const env = (task.inputs as RecordValue | undefined)?.env as RecordValue | undefined;
                step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: task.behaviorName, methodName: "execute", inputs: {
                    run: `printf '%s %s %s\\n' "$STEP" "$NAME" "$MERGE" >> '${folder}/calls'`,
                    env: { STEP: String(step.name), NAME: task.behaviorName, MERGE: env?.ORBIT_ROUTE_MERGE ?? "-" } } };
            }
        }
        Deno.writeTextFileSync(created.path, stringify(workflow));
        const valid = await call("workflow", "validate", name);
        assert(valid.ok, valid.stderr + valid.stdout);
    }
    const node = { host: "10.44.0.7", user: "orbit", port: 22, identity_file: "/none", host_keys: [], account: "orbit" };
    const input = (routeId: number, merge: boolean | null) => JSON.stringify({
        operation_id: 1, node_id: 3, kind: "route.create", signed: [], prepared: "9", execution_secrets: {},
        ...(merge === null ? {} : { merge }),
        admitted: { path: `${folder}/intent.json`, assets: `${folder}/assets`, field: "route" },
        intent: { route_id: routeId, leaf_indexes: [], leaves: [], lan: [], site_nodes: [9], ingress: [], dns: [], env_files: [],
            probes: [{ node_id: 9, address: "10.44.0.7", domain: `r${routeId}.test` }], nodes: { "9": node },
            probe_ssh: { target: { host: "10.44.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" } },
    });
    const run = async (routeId: number, merge: boolean | null) => {
        const child = new Deno.Command(binary!, { args: ["workflow", "run", "operation-route-create", "--stdin", "--json"], cwd: folder, ...auth,
            stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(input(routeId, merge)));
        await writer.close();
        return await child.output();
    };
    const calls = () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n");
    return { folder, run, calls, cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

Deno.test({ name: "pinned route create: a clone's site merges under a pooled name per Node, and other callers keep their per-Node names", ignore: !binary, async fn() {
    const f = await fixture();
    try {
        // Routes 41 and 42 take slots 9 and 10 on Node 9; 57 shares slot 9 with 41 and runs after it; 43 is not a clone's create.
        for (const [route, merge] of [[41, true], [42, true], [57, true], [43, null]] as const) {
            const done = await f.run(route, merge);
            assert(done.success, text(done.stdout) + text(done.stderr));
        }
        const calls = f.calls();
        assertEquals(calls.filter((line) => line.startsWith("site ")),
            ["site route-site-9-9 1", "site route-site-9-10 1", "site route-site-9-9 1", "site route-site-9 "]);
        assertEquals(calls.filter((line) => line.includes("route-probe-209-")), ["Probe the site over TLS route-probe-209-9-9 -",
            "Probe the site over TLS route-probe-209-9-10 -", "Probe the site over TLS route-probe-209-9-9 -", "Probe the site over TLS route-probe-209-9 -"]);
    } finally {
        f.cleanup();
    }
} });
