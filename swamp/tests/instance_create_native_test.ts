/**
 * Runs the real `operation-instance-create` workflow on the release-pinned Swamp binary for a normal container clone. Every machine step is
 * a local shell stub that records its name; `finish` is the real `create_result` behavior, and `accepted` the real assert. It proves the
 * guards, the levels and the result expressions as the engine evaluates them: the create ends at the registration, runs no Route,
 * database or fleet step, and a failure before the end stops the container.
 */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { nativeAuth } from "./_support/native_auth.ts";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
type RecordValue = Record<string, unknown>;
type Mode = "success" | "workspace-failure";
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

async function fixture(mode: Mode) {
    const folder = Deno.makeTempDirSync({ prefix: "orbit-ansible-" });
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
    // What the real steps print: the checkout its commit and key names, the registration the T3 server it recorded.
    Deno.writeTextFileSync(`${folder}/workspace.out`, `orbit-commit=${commit}\n${JSON.stringify({ phase: "checkout", changes: [], environment: { copied: ["APP_NAME"], dropped: [] } })}\n`);
    Deno.writeTextFileSync(`${folder}/register.out`, JSON.stringify(server));
    // One stub child for every child workflow of the legacy job; a clone skips them all.
    const child = JSON.parse((await call("workflow", "create", "create-child")).stdout);
    Deno.writeTextFileSync(child.path, stringify({
        id: child.id, name: child.name,
        inputs: { type: "object", properties: { phase: { type: "string" } }, required: ["phase"] },
        jobs: [{ name: "child", steps: [{ name: "result", task: { type: "behavior", behaviorType: "command/shell", behaviorName: "create-child",
            methodName: "execute", inputs: { run: `printf '%s\\n' "\${{ inputs.phase }}" >> '${folder}/calls'` } } }] }],
    }));
    const created = JSON.parse((await call("workflow", "create", "create-native")).stdout);
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as RecordValue;
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
            if ((step.task as RecordValue).type === "workflow") {
                step.task = { type: "workflow", workflowIdOrName: child.name, inputs: { phase: name } };
                continue;
            }
            let run = `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === "clone-workspace") run += mode === "workspace-failure" ? "; exit 3" : `; cat '${folder}/workspace.out'`;
            if (name === "clone-register") run += `; cat '${folder}/register.out'`;
            step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: `create-${name}`, methodName: "execute", inputs: { run } };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", created.name);
    assert(valid.ok, valid.stderr + valid.stdout);
    const input = JSON.stringify({
        operation_id: 1, node_id: 3, kind: "instance.create", execution_secrets: {},
        // A normal clone as NativeDevelopmentInstanceInput hands it over: no database, Route or fleet in its create.
        intent: { instance_id: 8, already_active: false, name_converted_from: null, container: { clone: { source: "instance-7" }, project: "orbit-instances", mail: null, steps: [], processes: [] },
            database: null, server_database: null, server_valkey: null, processes: [], setup_steps: [], route: null, route_mutations: null,
            environment_testing: false, ssh_execution: { target: { host: "127.0.0.1", user: "orbit", port: 22, host_keys: [] }, identity_file: "/none" } },
    });
    const history = async () => JSON.parse((await call("workflow", "history", "get", created.name)).stdout);
    const steps = async (): Promise<Record<string, RecordValue>> =>
        Object.fromEntries((await history()).jobs.flatMap((job: { steps: RecordValue[] }) => job.steps).map((step: RecordValue) => [step.name, step]));
    return { folder, auth, created, input, steps, cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

async function execute(f: Awaited<ReturnType<typeof fixture>>) {
    const child = new Deno.Command(binary!, { args: ["workflow", "run", f.created.name, "--stdin", "--json"], cwd: f.folder,
        ...f.auth, stdin: "piped", stdout: "piped", stderr: "piped" }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(f.input));
    await writer.close();
    return await child.output();
}

const status = (steps: Record<string, RecordValue>, name: string) => String(steps[name]?.status);
const result = (steps: Record<string, RecordValue>) => JSON.parse(String((steps.finish.outputs as RecordValue).stdout));
const calls = (folder: string) => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n");

Deno.test({ name: "pinned create: a normal clone ends ready at its registration and runs no step of its completion", ignore: !binary, async fn() {
    const f = await fixture("success");
    try {
        const done = await execute(f);
        assert(done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        for (const name of ["input", "clone-input", "clone-container", "clone-workspace", "clone-t3", "clone-sessions", "clone-session-copy",
            "clone-register", "finish", "clone-private-cleanup", "cleanup", "accepted"]) {
            assertEquals(status(steps, name), "succeeded", name);
        }
        for (const name of ["clone-club-key", "stop-container", "withdraw-route", "checkout", "container"]) assertEquals(status(steps, name), "skipped", name);
        assertEquals(Object.keys(steps).filter((name) => /route-csr|clone-route|database|server-|fleet|services/.test(name) && status(steps, name) !== "skipped"), []);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.commit, value.t3, value.environment], [true, undefined, commit, server, { copied: ["APP_NAME"], dropped: [] }]);
        // The registration is the last machine step of the clone job; finish and the private-folder cleanup come after it.
        const order = calls(f.folder);
        assert(order.indexOf("clone-register") < order.indexOf("clone-private-cleanup"), order.join(" "));
        assertEquals(order.includes("create-child"), false);
    } finally {
        f.cleanup();
    }
} });

Deno.test({ name: "pinned create: a failed workspace registers nothing, stops the container and removes the private folder", ignore: !binary, async fn() {
    const f = await fixture("workspace-failure");
    try {
        const done = await execute(f);
        assert(!done.success, text(done.stdout) + text(done.stderr));
        const steps = await f.steps();
        assertEquals([status(steps, "clone-workspace"), status(steps, "clone-t3"), status(steps, "clone-sessions"), status(steps, "clone-register")],
            ["failed", "succeeded", "skipped", "skipped"]);
        assertEquals([status(steps, "stop-container"), status(steps, "withdraw-route"), status(steps, "clone-private-cleanup"), status(steps, "accepted")],
            ["succeeded", "skipped", "succeeded", "failed"]);
        const value = result(steps);
        assertEquals([value.ready, value.failed_step, value.error_code], [false, "workspace", "instance.create_workspace_failed"]);
        assert(exists(`${f.folder}/stop-container`));
    } finally {
        f.cleanup();
    }
} });
