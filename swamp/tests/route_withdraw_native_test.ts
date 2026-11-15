/**
 * Runs the real `route-withdraw` and `route-withdraw-node` workflows on the release-pinned Swamp binary, as a destroy calls them. Every
 * behavior is a local shell stub that records the behavior name, its child run and its start and end: the engine evaluates the real Node
 * selection, the real slot names (16 per Node) and the real input mapping. An in-TLD clone's destroy visits only its Router; a Route with
 * a private DNS line also visits the vpn Node; two withdrawals in one slot on one Node both run, one after the other on that name's lock,
 * each with its own run folder.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);

async function fixture() {
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-route-withdraw-" });
    const auth = await nativeAuth(folder);
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary!, { args: [...args, "--json"], cwd: folder, ...auth }).output();
        return { ok: done.success, stdout: text(done.stdout), stderr: text(done.stderr) };
    };
    const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
    assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    for (const name of ["route-withdraw-node", "route-withdraw"]) {
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
                // The apply phase holds its name for a moment, so a second withdrawal in the same slot shows whether it waits.
                const hold = step.name === "apply" ? "timeout 0.6 tail -f /dev/null || true; " : "";
                step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: task.behaviorName, methodName: "execute", inputs: {
                    run: `s=$(date +%s%N); ${hold}printf '%s %s %s %s %s\\n' "$STEP" "$NAME" "$RUN" "$s" "$(date +%s%N)" >> '${folder}/calls'`,
                    env: { STEP: String(step.name), NAME: task.behaviorName, RUN: '${{ "/tmp/orbit-ansible-" + run.id }}' } } };
            }
        }
        Deno.writeTextFileSync(created.path, stringify(workflow));
        const valid = await call("workflow", "validate", name);
        assert(valid.ok, valid.stderr + valid.stdout);
    }
    const connection = { nodeHost: "10.44.0.7", nodeUser: "orbit", nodePort: 22, nodeIdentityFile: "/none", become: true };
    // Interned artifacts as NativeRoutePublicationArtifacts::capture sends them: equal states share one index.
    const caddy = (served: boolean) => ({ content: served ? "# h\n\n# orbit: app-dev r\nr.test {}\n" : "# h\n", version: served ? "a" : "b",
        listen_addresses: ["10.44.0.7"], unserved: !served, had_served_groups: served });
    const dns = (line: boolean) => ({ node_id: 1, records: line ? "# Managed by Orbit.\nhost-record=r.example.org,10.44.0.7\n" : "# Managed by Orbit.\n\n",
        catalog: "{}\n", digest: "d" });
    const publication = (nodeId: number, serves: boolean, states: Record<string, RecordValue>) => ({
        index: 0, node_id: nodeId, user: "orbit", control_node_id: 209, connection, host_keys: ["ssh-ed25519 fixture"], serves_routes: serves, ...states });
    const input = (routeId: number, vpnLine: boolean) => JSON.stringify({
        operation_id: 1, node_id: 3, kind: "instance.destroy", side: "forward", execution_secrets: {},
        intent: { route_mutations: {
            forward: { routes: [], cleanup: [], lan: [], ingress: [], retire_lan: [], retire_ingress: [], certificates: [], url_writes: [],
                prepare_certificates: [],
                publications: [
                    publication(9, true, { before: { caddy: 0, php: 0, dns: null }, prepared: { caddy: 0, php: 0, dns: null },
                        withdrawn: { caddy: 1, php: 0, dns: null }, after: { caddy: 1, php: 0, dns: null } }),
                    publication(1, false, vpnLine
                        ? { before: { caddy: 1, php: 0, dns: 1 }, prepared: { caddy: 1, php: 0, dns: 1 }, withdrawn: { caddy: 1, php: 0, dns: 0 }, after: { caddy: 1, php: 0, dns: 0 } }
                        : { before: { caddy: 1, php: 0, dns: 0 }, prepared: { caddy: 1, php: 0, dns: 0 }, withdrawn: { caddy: 1, php: 0, dns: 0 }, after: { caddy: 1, php: 0, dns: 0 } }),
                ] },
            restore: { routes: [{ route_id: routeId }] },
            artifacts: { caddy: [caddy(true), caddy(false)], php: [{ configurations: {} }], dns: [dns(false), dns(true)] },
        } },
    });
    const run = async (routeId: number, vpnLine: boolean) => {
        const child = new Deno.Command(binary!, { args: ["workflow", "run", "route-withdraw", "--stdin", "--json"], cwd: folder, ...auth,
            stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(input(routeId, vpnLine)));
        await writer.close();
        return await child.output();
    };
    const calls = () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").map((line) => {
        const [step, name, folder, start, end] = line.split(" ");
        return { step, name, folder, start: BigInt(start), end: BigInt(end) };
    });
    return { run, calls, cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

Deno.test({ name: "pinned route withdraw: pooled names, no empty vpn visit, and two withdrawals in one slot", ignore: !binary, async fn() {
    const f = await fixture();
    try {
        // Route 1150 (slot 14) in the TLD: the vpn Node's DNS states are one artifact, so only the Router is visited.
        let done = await f.run(1150, false);
        assert(done.success, text(done.stdout) + text(done.stderr));
        assertEquals(f.calls().map((c) => `${c.step} ${c.name}`),
            ["input ansible-input", "compose route-withdraw-compose-9-14", "apply route-withdraw-apply-9-14", "forward forward-result-0-14", "result route-mutation-phase"]);
        // Route 1151 (slot 15) outside the TLD: its line on the vpn Node goes, so Node 1 is visited too.
        const first = f.calls().length;
        done = await f.run(1151, true);
        assert(done.success, text(done.stdout) + text(done.stderr));
        assertEquals(f.calls().slice(first).filter((c) => c.step === "apply").map((c) => c.name).sort(),
            ["route-withdraw-apply-1-15", "route-withdraw-apply-9-15"]);
        // Routes 41 and 57 share slot 9 on Node 9 and withdraw at the same time: both run, each in its own run folder, and the second
        // apply starts after the first one ended (that name's lock). Route 42 (slot 10) beside them waits for neither.
        const before = f.calls().length;
        const runs = await Promise.all([f.run(41, false), f.run(57, false), f.run(42, false)]);
        for (const r of runs) assert(r.success, text(r.stdout) + text(r.stderr));
        const applies = f.calls().slice(before).filter((c) => c.step === "apply");
        const slot9 = applies.filter((c) => c.name === "route-withdraw-apply-9-9").sort((a, b) => Number(a.start - b.start));
        assertEquals(slot9.length, 2);
        assert(slot9[0].folder !== slot9[1].folder, "each withdrawal keeps its own run folder");
        assert(slot9[1].start >= slot9[0].end, "the second withdrawal in the slot waits for the first one's step");
        assertEquals(applies.filter((c) => c.name === "route-withdraw-apply-9-10").length, 1);
    } finally {
        f.cleanup();
    }
} });
