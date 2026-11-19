/**
 * Runs the real `operation-instance-create` workflow on the release-pinned Swamp binary for a normal container clone. Every machine step is
 * a local shell stub that records its name; `clone-register` is the real `register` behavior with a local vault, `finish` the real
 * `create_result` behavior, and `accepted` the real assert. It proves the guards, the levels and the result expressions as the engine
 * evaluates them: a ready clone ends at its registration, which builds the result, with four machine dispatches and no input step; it
 * runs no database or fleet step, publishes its Route beside the copy only when its intent carries one, and a failure before the end
 * runs finish and stops the container.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
type Mode = "success" | "two-part-stage" | "workspace-failure" | "register-failure" | "route" | "route-failure" | "route-deferred" | "route-csr-failure" | "route-cancel" | "route-then-t3-failure";
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};
const commit = "b".repeat(40);
const server = JSON.parse(JSON.parse(Deno.readTextFileSync(new URL("fixtures/instance-create-16210-t3-register.json", import.meta.url))).step.outputs.stdout);
// What clone-t3 leaves in the private folder and clone-session-copy brings beside the run folder: the served environment and both sessions.
const session = (token: string, scopes: string[]) => ({ token, status: 200,
    body: JSON.stringify({ authenticated: true, scopes, expiresAt: server.expires_at }) });
const reply = JSON.stringify({ environment: JSON.stringify({ environmentId: server.environment_id, serverVersion: server.server_version }),
    gateway: session("fixture-gateway-session", ["access:read", "access:write"]), swamp: session("fixture-swamp-session", []) });
// The launch copy in its real size and shape (CloneStage::value): the real release files as `stage`, the clone copy's text as `private`.
const read = (path: string) => Deno.readTextFileSync(new URL(`../${path}`, import.meta.url));
const token = "ghs_" + "T".repeat(36);
const modelKey = "orbit-model-" + "m".repeat(52);
const privateText = `https://x-access-token:${token}@github.com\n#orbit-model-environment\nANTHROPIC_BASE_URL=http://10.253.0.1:8319\n` +
    `ANTHROPIC_AUTH_TOKEN=${modelKey}\nORBIT_MODEL_KEY=${modelKey}\nCLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1\nDISABLE_TELEMETRY=1\nDISABLE_ERROR_REPORTING=1\n`;
const stage = { launcher: read("scripts/instance_clone_launch.py"), script: read("scripts/instance_clone.py"), container: { instance_id: 8 },
    guest: read("scripts/instance_clone_guest.py"), network: read("ansible/instance_network.py"), account: "orbit",
    bundle: { node_services: read("ansible/instance_node_services.py"), machine_name: read("ansible/instance_machine_name.py"), root_disk: read("ansible/instance_root_disk.py"),
        mail: read("ansible/instance_mail.py"), bridge: read("ansible/instance_bridge.py"), node: read("ansible/instance_node_runtime.py"),
        workspace_owner: read("ansible/instance_workspace_owner.py") } };
// What the copy step's stand-in receives: the value resolved by the engine through vault.get, as the SSH stdin would. It checks the
// shape and prints the length of the private text.
const sink = `import { z } from "npm:zod@4.4.3";
export const behavior = { type: "@orbit-test/stage-sink", version: "2026.11.15.1",
    resources: { terminal: { schema: z.object({ stdout: z.string() }), lifetime: "infinite", garbageCollection: 10 } },
    methods: { take: { description: "Take the launch copy", arguments: z.object({ stage: z.string(), wait: z.number() }),
        execute: async (args: { stage: string; wait: number }, ctx: { writeResource(s: string, n: string, d: unknown): Promise<unknown> }) => {
            const launch = JSON.parse(args.stage);
            if (typeof launch.private !== "string" || typeof launch.stage?.script !== "string") throw new Error("not a launch copy");
            await new Promise((resolve) => setTimeout(resolve, args.wait * 1000));
            return { dataHandles: [await ctx.writeResource("terminal", "terminal", { stdout: "private " + launch.private.length })] };
        } } } };
`;

async function fixture(mode: Mode) {
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
    // The registration stores both sessions as sensitive fields of this vault.
    const vault = await call("vault", "create", "local_encryption", "orbit-control-inputs");
    assert(vault.ok, vault.stderr + vault.stdout);
    // The Gateway's copies of a clone create (CloneStage, InstanceGitHubReadTokens), put through stdin as the Gateway's frame does.
    const put = async (key: string, value: string) => {
        const child = new Deno.Command(binary!, { args: ["vault", "put", "orbit-control-inputs", key, "--json"], cwd: folder, ...auth, stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(value));
        await writer.close();
        const done = await child.output();
        assert(done.success, text(done.stderr));
    };
    // two-part-stage: the value release 2080 read (#3780): the public JSON line, a newline, the private text.
    await put("instance-create-launch-1", mode === "two-part-stage" ? JSON.stringify(stage) + "\n" + privateText : JSON.stringify({ stage, private: privateText }));
    await put("instance-create-clone-1", privateText);
    const models = `${folder}/extensions/models`;
    Deno.mkdirSync(`${models}/stage-sink`, { recursive: true });
    Deno.writeTextFileSync(`${models}/stage-sink/mod.ts`, sink);
    for (const [behavior, names] of [["instance-dev-command", ["assets.ts", "complete_result.ts", "migrate_result.ts", "result.ts"]], ["instance-t3", ["mod.ts"]]] as const) {
        Deno.mkdirSync(`${models}/${behavior}`, { recursive: true });
        for (const name of names) {
            Deno.copyFileSync(new URL(`../extensions/behaviors/operations/${behavior}/${name}`, import.meta.url), `${models}/${behavior}/${name}`);
        }
    }
    // What the real steps print: the checkout its commit and key names, the registration the T3 server it recorded.
    Deno.writeTextFileSync(`${folder}/workspace.out`, `orbit-commit=${commit}\n${JSON.stringify({ phase: "checkout", changes: [], environment: { copied: ["APP_NAME"], dropped: [] } })}\n`);
    Deno.writeTextFileSync(`${folder}/t3.json`, mode === "register-failure" ? JSON.stringify({ environment: "{}" }) : reply);
    // One stub child for every child workflow: the legacy job's, which a clone skips, the clone's Route steps and the withdrawal. It
    // records its phase; the Route fails in route-failure mode and runs 30 s in route-cancel mode.
    const child = JSON.parse((await call("workflow", "create", "create-child")).stdout);
    // A Node that refused the merge leaves its mark in the create's run folder and fails the site, as route-create-site.yml does.
    const routeRun = mode === "route-failure" ? `; test "\${{ inputs.phase }}" != clone-route`
        : mode === "route-deferred" ? `; if [ "\${{ inputs.phase }}" = clone-route ]; then printf '{"node_id":9,"code":"caddy.publish_busy"}' > '${folder}-run/route-deferred-9.json'; exit 2; fi`
        : mode === "route-csr-failure" ? `; test "\${{ inputs.phase }}" != clone-route-csr`
        : mode === "route-cancel" ? `; if [ "\${{ inputs.phase }}" = clone-route ]; then touch '${folder}/clone-route'; sleep 30; fi` : "";
    Deno.writeTextFileSync(child.path, stringify({
        id: child.id, name: child.name,
        inputs: { type: "object", properties: { phase: { type: "string" } }, required: ["phase"] },
        jobs: [{ name: "child", steps: [{ name: "result", task: { type: "behavior", behaviorType: "command/shell", behaviorName: "create-child",
            methodName: "execute", inputs: { run: `printf '%s\\n' "\${{ inputs.phase }}" >> '${folder}/calls'; touch '${folder}/\${{ inputs.phase }}'` + routeRun } } }] }],
    }));
    const created = JSON.parse((await call("workflow", "create", "create-native")).stdout);
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as RecordValue;
    workflow.id = created.id;
    workflow.name = created.name;
    delete workflow.labels;
    for (const job of workflow.jobs as RecordValue[]) {
        for (const step of job.steps as RecordValue[]) {
            const name = String(step.name);
            delete step.labels;
            if (name === "accepted") continue;
            if (name === "finish" || name === "clone-register") {
                // The run folder and the copied t3.json that both read and, on a ready create, remove: their own, so the records stay.
                const inputs = (step.task as RecordValue).inputs as RecordValue;
                inputs.folder = `${folder}-run`;
                inputs.t3_file = `${folder}-run-t3.json`;
                continue;
            }
            // route-cancel: a cancel cuts a running command, as it cuts the real SSH copy; that case keeps the command stand-in below.
            if (name === "clone-container" && mode !== "route-cancel") {
                // The stand-in reads the real stdin expression: the engine resolves vault.get(clone_launch) and registers its value.
                const real = (step.task as RecordValue).inputs as RecordValue;
                step.task = { type: "behavior", behaviorType: "@orbit-test/stage-sink", behaviorName: "create-clone-container", methodName: "take",
                    inputs: { stage: real.stdin, wait: 0 } };
                continue;
            }
            if ((step.task as RecordValue).type === "workflow") {
                step.task = { type: "workflow", workflowIdOrName: child.name, inputs: { phase: name } };
                continue;
            }
            let run = `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            // The T3 step runs the checkout: a failed checkout leaves its part's name in t3.json, which clone-session-copy brings beside
            // the run folder; a ready one leaves the reply.
            if (name === "clone-t3") run += mode === "workspace-failure" ? `; printf '{"t3_part":"checkout"}' > '${folder}/t3.json'; exit 3` : `; cat '${folder}/workspace.out'`;
            if (name === "clone-session-copy") run += `; cp '${folder}/t3.json' '${folder}-run-t3.json'`;
            // The input steps a clone keeps write the run folder: the Route's beside the CSR, the stop's after a failure.
            if (name === "clone-route-input" || name === "stop-input") run += `; mkdir -p '${folder}-run'`;
            // The publication runs beside the copy: a cancel while clone-route runs cuts the copy too.
            if (name === "clone-container") run += "; sleep 30";
            // A later clone step fails after the Route has published (Nick's question: what happens to the address).
            if (name === "clone-t3" && mode === "route-then-t3-failure") run = `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'; sleep 3; exit 4`;
            step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: `create-${name}`, methodName: "execute", inputs: { run } };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", created.name);
    assert(valid.ok, valid.stderr + valid.stdout);
    const input = JSON.stringify({
        // The copies and the launcher, as the Gateway sends them for every clone (CloneStage, InstanceGitHubReadTokens::cloneReference).
        operation_id: 1, node_id: 3, kind: "instance.create", execution_secrets: Object.fromEntries([["git_credential", "clone"], ["clone_launch", "launch"]].map(([name, prefix]) => [name, { vault: "orbit-control-inputs", key: `instance-create-${prefix}-1` }])),
        // A normal clone as NativeDevelopmentInstanceInput hands it over: no database, Route or fleet in its create.
        intent: { instance_id: 8, already_active: false, name_converted_from: null, clone_launcher: "print('launcher')",
            container: { clone: { source: "instance-7" }, project: "orbit-instances", mail: null, steps: [], processes: [] },
            // A Route only when the admission took the free Route Nodes (RoutePublicationHolds::admit).
            database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [],
            route: mode.startsWith("route") ? { leaf_indexes: [0] } : null, route_mutations: mode.startsWith("route") ? { artifacts: {}, restore: {} } : null,
            environment_testing: false, ssh_execution: { target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" } },
    });
    const history = async () => JSON.parse((await call("workflow", "history", "get", created.name)).stdout);
    const steps = async (): Promise<Record<string, RecordValue>> =>
        Object.fromEntries((await history()).jobs.flatMap((job: { steps: RecordValue[] }) => job.steps).map((step: RecordValue) => [step.name, step]));
    return { folder, auth, created, input, steps, cleanup: () => {
        Deno.removeSync(folder, { recursive: true });
        if (exists(`${folder}-run`)) Deno.removeSync(`${folder}-run`, { recursive: true });
        if (exists(`${folder}-run-t3.json`)) Deno.removeSync(`${folder}-run-t3.json`);
    } };
}

/** Starts the run in its own process group; a cancel sends SIGTERM once the clone's Route child runs. */
async function execute(f: Awaited<ReturnType<typeof fixture>>, cancel = false) {
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
            const until = Date.now() + 25000;
            while (!finished && Date.now() < until && !exists(`${f.folder}/clone-route`)) await new Promise((resolve) => setTimeout(resolve, 250));
            assert(exists(`${f.folder}/clone-route`), "The cancel must come while clone-route runs.");
            child.kill("SIGTERM");
        }
        return await completion;
    } finally {
        if (!finished) {
            try {
                Deno.kill(-child.pid, "SIGKILL");
            } catch { /* Only this fixture's group. */ }
        }
    }
}

const status = (steps: Record<string, RecordValue>, name: string) => String(steps[name]?.status);
// The result step that ran: finish, or a ready clone's clone-register (the Gateway's terminal `fallback`).
const result = (steps: Record<string, RecordValue>) =>
    JSON.parse(String(((status(steps, "finish") === "skipped" ? steps["clone-register"] : steps.finish).outputs as RecordValue).stdout));
// The registered server, with the vault keys of this fixture's model.
const registered = (t3: RecordValue) => ({ ...t3, gateway_session: server.gateway_session, swamp_session: server.swamp_session });
const calls = (folder: string) => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n");

Deno.test({ name: "pinned create: a normal clone ends ready at its registration and runs no step of its completion", ignore: !binary, async fn() {
    const f = await fixture("success");
    try {
        const done = await execute(f);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        for (const name of ["clone-container", "clone-t3", "clone-session-copy", "clone-register", "clone-private-cleanup", "accepted"]) {
            assertEquals(status(steps, name), "succeeded", name);
        }
        // The copy step read the real-size launch copy through vault.get, and the private text arrived whole. Neither secret is in the
        // run's history.
        assertEquals(String((steps["clone-container"].outputs as RecordValue).stdout), "private " + privateText.length);
        const history = JSON.stringify(await f.steps());
        assertEquals([history.includes(token), history.includes(modelKey)], [false, false]);
        // Four dispatches in a row: no input step, and clone-register builds the result, so finish and the cleanup step skip.
        for (const name of ["input", "finish", "clone-club-key", "clone-ci-hold", "stop-input", "stop-container", "withdraw-route", "checkout", "container", "cleanup"]) {
            assertEquals(status(steps, name), "skipped", name);
        }
        assertEquals("clone-input" in steps, false);
        // The copy of the sessions is gone; no run folder was made.
        assertEquals([exists(`${f.folder}-run`), exists(`${f.folder}-run-t3.json`)], [false, false]);
        assertEquals(Object.keys(steps).filter((name) => /route-csr|clone-route|database|server-|fleet|services/.test(name) && status(steps, name) !== "skipped"), []);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.commit, registered(value.t3), value.environment], [true, undefined, commit, server, { copied: ["APP_NAME"], dropped: [] }]);
        assert(/^nckrtl-instance-t3-[a-f0-9-]{36}-register-session-gateway-8-token$/.test(value.t3.gateway_session.key), value.t3.gateway_session.key);
        assertEquals(JSON.stringify(value).includes("fixture-gateway-session"), false);
        assertEquals(calls(f.folder).includes("create-child"), false);
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: release 2080's two-part stage copy fails the copy step with Regular expression too large; one line passes", ignore: !binary, async fn() {
    const f = await fixture("two-part-stage");
    try {
        const done = await execute(f);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals(status(steps, "clone-container"), "failed");
        assert(JSON.stringify(steps["clone-container"]).includes("Regular expression too large"), JSON.stringify(steps["clone-container"]).slice(0, 400));
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a failed checkout in the T3 step registers nothing, stops the container and removes the private folder", ignore: !binary, async fn() {
    const f = await fixture("workspace-failure");
    try {
        const done = await execute(f);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([status(steps, "clone-t3"), status(steps, "clone-session-copy"), status(steps, "clone-register")],
            ["failed", "succeeded", "skipped"]);
        assertEquals([status(steps, "finish"), status(steps, "stop-input"), status(steps, "stop-container"), status(steps, "withdraw-route"), status(steps, "clone-private-cleanup"), status(steps, "accepted")],
            ["succeeded", "succeeded", "succeeded", "skipped", "succeeded", "failed"]);
        // A failed create keeps its run folder for the container stop; the cleanup step removes it after.
        assertEquals([status(steps, "cleanup"), exists(`${f.folder}-run`)], ["succeeded", true]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.error_code], [false, "workspace", "instance.create_workspace_failed"]);
        assert(exists(`${f.folder}/stop-container`));
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a refused registration runs finish, names the registration, stops the container and keeps no session", ignore: !binary, async fn() {
    const f = await fixture("register-failure");
    try {
        const done = await execute(f);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([status(steps, "clone-t3"), status(steps, "clone-session-copy"), status(steps, "clone-register"), status(steps, "finish")],
            ["succeeded", "succeeded", "failed", "succeeded"]);
        assertEquals([status(steps, "stop-input"), status(steps, "stop-container"), status(steps, "accepted"), status(steps, "cleanup")],
            ["succeeded", "succeeded", "failed", "succeeded"]);
        // The registration removed the copy of the reply before its check refused it.
        assertEquals(exists(`${f.folder}-run-t3.json`), false);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step], [false, "register"]);
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a clone with a Route publishes it beside the copy and still ends at its registration", ignore: !binary, async fn() {
    const f = await fixture("route");
    try {
        const done = await execute(f);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        for (const name of ["clone-route-input", "clone-route-csr-0", "clone-route", "clone-register", "accepted"]) assertEquals(status(steps, name), "succeeded", name);
        // The legacy Route steps keep their names for a checkout create; a clone skips them.
        for (const name of ["input", "route", "finish", "withdraw-route", "stop-container", "cleanup"]) assertEquals(status(steps, name), "skipped", name);
        // clone-register removed the Route's run folder of the ready create.
        assertEquals([exists(`${f.folder}-run`), exists(`${f.folder}-run-t3.json`)], [false, false]);
        const value = result(steps);
        assertEquals([value.ready, value.route_publication, value.warnings], [true, "published", undefined]);
        assertEquals(calls(f.folder).includes("withdraw-route"), false);
    } finally {
        f.cleanup();
    }
} });

for (const [mode, csr, route] of [["route-failure", "succeeded", "failed"], ["route-csr-failure", "failed", "skipped"]] as const) {
Deno.test({ name: `pinned create: a failed publication (${mode}) leaves the run succeeded and the create ready, names the Route warning and withdraws nothing`, ignore: !binary, async fn() {
    const f = await fixture(mode);
    try {
        const done = await execute(f);
        // The publication steps allow their failure: the run, and so the create, still succeeds (D2).
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([status(steps, "clone-route-csr-0"), status(steps, "clone-route"), status(steps, "clone-register"), status(steps, "withdraw-route")],
            [csr, route, "succeeded", "skipped"]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.route_publication], [true, undefined, "failed"]);
        assertEquals(value.warnings, [{ code: "instance.create_route_failed", step: "route" }]);
    } finally {
        f.cleanup();
    }
} });
}

Deno.test({ name: "pinned create: a merge a Node refused leaves the create ready and the address deferred to the completion, with no Route warning", ignore: !binary, async fn() {
    const f = await fixture("route-deferred");
    try {
        const done = await execute(f);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        // The four-dispatch path: clone-register builds the result and finish skips; the mark is read before the run folder goes.
        assertEquals([status(steps, "clone-route-csr-0"), status(steps, "clone-route"), status(steps, "clone-register"), status(steps, "finish"), status(steps, "withdraw-route")],
            ["succeeded", "failed", "succeeded", "skipped", "skipped"]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.route_publication, value.warnings], [true, undefined, "deferred", undefined]);
        assertEquals([exists(`${f.folder}-run`), exists(`${f.folder}-run-t3.json`)], [false, false]);
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a cancel while clone-route runs withdraws the half Route and stops the container", ignore: !binary, async fn() {
    const f = await fixture("route-cancel");
    try {
        const done = await execute(f, true);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([status(steps, "withdraw-route"), status(steps, "clone-private-cleanup")], ["succeeded", "succeeded"], JSON.stringify(Object.keys(steps)));
        assertEquals([result(steps).ready, status(steps, "clone-register")], [false, "skipped"]);
        assert(exists(`${f.folder}/withdraw-route`));
        assert(exists(`${f.folder}/stop-container`));
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a later clone step that fails after the Route published fails the run, keeps the published address and stops the container", ignore: !binary, async fn() {
    const f = await fixture("route-then-t3-failure");
    try {
        const done = await execute(f);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        // The publication runs to its end beside the clone steps; a clone failure never stops it.
        assertEquals([status(steps, "clone-route-csr-0"), status(steps, "clone-route"), status(steps, "clone-t3"), status(steps, "clone-register")],
            ["succeeded", "succeeded", "failed", "skipped"]);
        // The published Route is active and its Nodes are free (the Gateway's progress event): the failed create keeps it.
        assertEquals([status(steps, "withdraw-route"), status(steps, "stop-container"), status(steps, "accepted")], ["skipped", "succeeded", "failed"]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.error_code, value.route_publication], [false, "t3", "instance.t3_unready", "published"]);
        assertEquals(calls(f.folder).includes("withdraw-route"), false);
    } finally {
        f.cleanup();
    }
} });

Deno.test("a clone has no seed-wait step: the copy step waits for its seed itself, only during a retake", () => {
    // docs/reference/development-containers.md#a-retake-keeps-the-seed: instance_clone.py's container phase reads the snapshots again
    // while the admitted seed's `next-` stands alone (instance_clone_test.py covers the wait).
    const flow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as
        { jobs: { name: string; steps: { name: string; dependsOn: { step: string }[] }[] }[] };
    const clone = flow.jobs.find((job) => job.name === "clone")!.steps;
    assertEquals(clone.find((step) => step.name === "clone-seed-wait"), undefined);
    assertEquals(clone.find((step) => step.name === "clone-container")!.dependsOn.map((edge) => edge.step), ["clone-ci-hold"]);
});
