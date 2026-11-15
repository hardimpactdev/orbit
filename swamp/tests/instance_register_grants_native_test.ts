/**
 * The T3 registration steps of the create and the migrate, run by `swamp serve` with the grants a release loads
 * (apps/gateway/bin/render-swamp-grants), as the Gateway's principal `user:orbit-gateway`, on the release-pinned Swamp binary.
 *
 * Swamp names a sensitive field's vault key from the method name (data_writer.ts at 537e03d1); the grant allows the Gateway's run to
 * write only `...-register-session-...` and `...-renew-session-...`. #3794 ran the create's registration as a new method, so its sessions
 * would have gone to `...-create_register-session-...`: refused by the grant, and not the key the Gateway reads (release 2086's
 * pre-check). This test takes each real step from its workflow, runs it under the enforced grants (a refused write of #3794's key is
 * the control), and checks the STORED key names and that the Gateway's read of the key the step RETURNED finds the session.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const swampRoot = new URL("..", import.meta.url).pathname;
type RecordValue = Record<string, unknown>;
type Step = { name: string; task: RecordValue; dependsOn?: unknown; guard?: string };

const flowStep = (workflow: string, name: string) => {
    const flow = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as { inputs: RecordValue; jobs: { steps: Step[] }[] };
    const step = structuredClone(flow.jobs.flatMap((job) => job.steps).find((candidate) => candidate.name === name)!) as Step & { labels?: unknown };
    // Its `role: swamp` label places it on a production worker; this serve runs it itself.
    delete step.labels;
    return { inputs: flow.inputs, step: step as Step };
};
const session = (token: string, scopes: string[]) => ({ token, status: 200,
    body: JSON.stringify({ authenticated: true, scopes, expiresAt: "2026-12-10T10:00:00.000Z" }) });
const reply = JSON.stringify({ environment: JSON.stringify({ environmentId: "env-8", serverVersion: "0.1.7" }),
    gateway: session("fixture-gateway-session-value", ["access:read", "access:write"]), swamp: session("fixture-swamp-session-value", []) });

/**
 * A repository with the rendered release grants, the T3 behavior and one workflow named as the Gateway's (the grants admit its
 * workflows by name); a token-mode serve on it; the Gateway's token. `build` gets the run folder and returns the steps.
 */
async function world(inputs: RecordValue, build: (run: string) => Step[]) {
    const folder = Deno.makeTempDirSync({ dir: "/tmp", prefix: "orbit-ansible-" });
    const run = `${folder}-run`;
    const auth = await nativeAuth(folder);
    const call = async (args: string[], stdin?: string) => {
        const child = new Deno.Command(binary!, { args: [...args, "--json"], cwd: folder, ...auth, stdin: stdin === undefined ? "null" : "piped",
            stdout: "piped", stderr: "piped", signal: AbortSignal.timeout(180_000) }).spawn();
        if (stdin !== undefined) {
            const writer = child.stdin.getWriter();
            await writer.write(new TextEncoder().encode(stdin));
            await writer.close();
        }
        const done = await child.output();
        return { ok: done.success, out: text(done.stdout), err: text(done.stderr) };
    };
    const must = async (args: string[]) => {
        const done = await call(args);
        assert(done.ok, args.slice(0, 3).join(" ") + "\n" + done.out.slice(0, 800) + done.err.slice(0, 800));
        return done;
    };
    let serve: Deno.ChildProcess | undefined;
    const close = async () => {
        if (serve) {
            serve.kill("SIGTERM");
            await serve.status;
        }
        for (const path of [folder, run, `${run}-t3.json`]) await Deno.remove(path, { recursive: true }).catch(() => undefined);
    };
    try {
        const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
        assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
        await must(["repo", "init", folder, "--tool", "none", "--no-vault"]);
        const rendered = await new Deno.Command("php", { args: [new URL("../../apps/gateway/bin/render-swamp-grants", import.meta.url).pathname, swampRoot] }).output();
        assert(rendered.success, text(rendered.stderr));
        for (const [name, content] of Object.entries(JSON.parse(text(rendered.stdout)) as Record<string, string>)) {
            Deno.mkdirSync(`${folder}/${name}`.replace(/\/[^/]+$/, ""), { recursive: true });
            Deno.writeTextFileSync(`${folder}/${name}`, content);
        }
        await must(["vault", "create", "local_encryption", "orbit-control-inputs"]);
        for (const [behavior, names] of [["instance-dev-command", ["assets.ts", "complete_result.ts", "migrate_result.ts", "result.ts"]], ["instance-t3", ["mod.ts"]]] as const) {
            Deno.mkdirSync(`${folder}/extensions/models/${behavior}`, { recursive: true });
            for (const name of names) {
                Deno.copyFileSync(new URL(`../extensions/behaviors/operations/${behavior}/${name}`, import.meta.url), `${folder}/extensions/models/${behavior}/${name}`);
            }
        }
        const created = JSON.parse((await must(["workflow", "create", "operation-instance-create"])).out);
        Deno.writeTextFileSync(created.path, stringify({ id: created.id, name: created.name, vaults: ["orbit-control-inputs"], inputs, jobs: [{ name: "j", steps: build(run) }] }));
        await must(["access", "token", "mint", "admin", "--principal", "user:orbit-admin", "--duration", "1h"]);
        await must(["access", "token", "mint", "gateway", "--principal", "user:orbit-gateway", "--duration", "1h"]);
        const revealed = JSON.parse((await must(["access", "token", "reveal", "gateway"])).out);
        const token = String(revealed.token ?? revealed.credential ?? revealed.value);
        const port = 38000 + Math.floor(Math.random() * 1000);
        serve = new Deno.Command(binary!, { args: ["serve", "--repo-dir", folder, "--auth-mode", "token", "--admins", "user:orbit-admin", "--port", String(port),
            "--host", "127.0.0.1", "--no-schedule"], cwd: folder, ...auth, stdin: "null", stdout: "null", stderr: "null" }).spawn();
        let up = false;
        for (let i = 0; i < 60 && !up; i++) {
            up = await fetch(`http://127.0.0.1:${port}/health`, { signal: AbortSignal.timeout(2_000) }).then((response) => { response.body?.cancel(); return true; }, () => false);
            if (!up) await new Promise((resolve) => setTimeout(resolve, 500));
        }
        assert(up, "serve did not start");
        const server = ["--server", `ws://127.0.0.1:${port}`, "--token", token];
        // The control: this serve enforces the grants per key. #3794's key, stored here directly, is refused to the Gateway's read.
        const denied = "nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-create_register-session-gateway-8-token";
        await must(["vault", "put", "orbit-control-inputs", denied, "fixture-refused-value"]);
        const refused = await call(["vault", "read-secret", "orbit-control-inputs", denied, ...server]);
        assert(!refused.ok && !refused.out.includes("fixture-refused-value") && /Access denied: user:orbit-gateway/.test(refused.out + refused.err), `the release grants are not enforced: ${refused.out.slice(0, 300)}`);
        await must(["vault", "delete", "orbit-control-inputs", denied]);
        return { run, call, close, name: created.name as string, server };
    } catch (error) {
        await close();
        throw error;
    }
}

/** Runs the workflow as the Gateway; returns the stored T3 key names, the returned keys, and the Gateway's read of each. */
async function register(w: Awaited<ReturnType<typeof world>>, input: RecordValue, step: string) {
    const ran = await w.call(["workflow", "run", w.name, "--stdin", ...w.server], JSON.stringify(input));
    // The run history from the repository the serve writes (the server reply leaves step outputs out).
    const history = JSON.parse((await w.call(["workflow", "history", "get", w.name])).out);
    const record = (history.jobs as { steps: RecordValue[] }[]).flatMap((job) => job.steps).find((s) => s.name === step)!;
    const shown = JSON.stringify(record ?? history).replaceAll(/fixture-(gateway|swamp)-session-value/g, "<session>").slice(0, 1500);
    assert(ran.ok && record?.status === "succeeded", `${step} under the release grants as user:orbit-gateway: ${shown}`);
    const stored = ((await w.call(["vault", "list-keys", "orbit-control-inputs"])).out.match(/nckrtl-instance-t3-[A-Za-z0-9_.-]+/g) ?? []).sort();
    const stdout = JSON.parse(String((record.outputs as RecordValue).stdout));
    const server = (stdout.t3 ?? stdout) as { gateway_session: { key: string }; swamp_session: { key: string } };
    const returned = [server.gateway_session.key, server.swamp_session.key];
    // The Gateway reads the key the run returned, as its principal (DevelopmentInstanceCreateProjection, SwampVaultExecutionCopy).
    const reads: string[] = [];
    for (const key of returned) {
        const read = await w.call(["vault", "read-secret", "orbit-control-inputs", key, ...w.server]);
        reads.push(read.ok && /fixture-(gateway|swamp)-session-value/.test(read.out) ? "found" : "missing");
    }
    return { stored, returned: [...returned].sort(), reads };
}

const shape = /^nckrtl-instance-t3-[a-f0-9-]{36}-register-session-(gateway|swamp)-8-token$/;

Deno.test({ name: "a clone create's registration stores its sessions under the keys the release grants allow and the Gateway reads", ignore: !binary, async fn() {
    const { inputs, step } = flowStep("workflow-operation-instance-create.yaml", "clone-register");
    const w = await world(inputs, (run) => {
        // The real step's task (its method and its inputs map); the T3 reply where clone-session-copy puts it. Its guard, which needs
        // the earlier clone steps, is covered by instance_create_native_test.ts.
        // `container` stands in for the earlier clone steps' records (succeeded), so the result is ready and names both keys.
        step.task.inputs = { ...(step.task.inputs as RecordValue), folder: run, t3_file: `${run}-t3.json`, container: { mail: null, launch: "succeeded",
            workspace: { status: "succeeded", outputs: { stdout: "orbit-commit=" + "c".repeat(40) } }, deploy: [], processes: "skipped", seed: "skipped",
            t3: "succeeded", register: "skipped", server: null, club_key: null } };
        delete step.guard;
        step.dependsOn = [];
        Deno.writeTextFileSync(`${run}-t3.json`, reply);
        return [step];
    });
    try {
        const intent = { instance_id: 8, already_active: false, name_converted_from: null, database: null, server_database: null, server_valkey: null,
            processes: [], setup_steps: [], route: null, container: { clone: { source: "instance-7" }, project: "orbit-instances" },
            ssh_execution: { target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" } };
        const done = await register(w, { operation_id: 1, node_id: 3, kind: "instance.create", intent, execution_secrets: {} }, "clone-register");
        assertEquals(done.stored.length, 2, done.stored.join(" "));
        for (const key of done.stored) assert(shape.test(key), `stored ${key}`);
        assertEquals(done.returned, done.stored);
        assertEquals(done.reads, ["found", "found"]);
    } finally {
        await w.close();
    }
} });

Deno.test({ name: "a migrate's registration stores its sessions under the same allowed keys, which the Gateway reads", ignore: !binary, async fn() {
    const { step } = flowStep("workflow-operation-instance-migrate.yaml", "t3-register");
    const w = await world({ type: "object" }, (run) => {
        step.task.inputs = { ...(step.task.inputs as RecordValue), instance_id: 8, folder: run };
        delete step.guard;
        step.dependsOn = [];
        Deno.mkdirSync(run, { recursive: true });
        Deno.writeTextFileSync(`${run}/t3.json`, reply);
        return [step];
    });
    try {
        const done = await register(w, {}, "t3-register");
        assertEquals(done.stored.length, 2, done.stored.join(" "));
        for (const key of done.stored) assert(shape.test(key), `stored ${key}`);
        assertEquals(done.returned, done.stored);
        assertEquals(done.reads, ["found", "found"]);
    } finally {
        await w.close();
    }
} });
