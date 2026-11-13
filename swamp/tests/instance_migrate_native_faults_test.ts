/** Pinned native DAG and real finish receipts. Placement and machine effects are local fixtures; Ansible proves the effects. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
const source = new URL(
    "../workflows/workflow-operation-instance-migrate.yaml",
    import.meta.url,
);
const pin = Swamp.version;
type RecordValue = Record<string, unknown>;
type Mode = "failure" | "timeout" | "cancel" | "deadline" | "lost" | "unsafe" | "mail-failure" | "mail-invalid";
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};

async function nativeFixture(mode: Mode, clone = false) {
    const folder = Deno.makeTempDirSync({ prefix: "orbit-ansible-" });
    const call = async (...args: string[]) => {
        const done = await new Deno.Command(binary!, {
            args: [...args, "--json"],
            cwd: folder,
            env: { SWAMP_NO_INBOX_NOTICE: "1" },
        }).output();
        return {
            ok: done.success,
            stdout: text(done.stdout),
            stderr: text(done.stderr),
        };
    };
    const version = await new Deno.Command(binary!, { args: ["--version"] })
        .output();
    assert(
        text(version.stdout).includes(pin),
        "Use the release-pinned Swamp binary for this proof.",
    );
    const initialized = await call("repo", "init", folder, "--tool", "none", "--no-vault");
    assert(initialized.ok, initialized.stderr + initialized.stdout);
    const models = `${folder}/extensions/models`;
    Deno.mkdirSync(models, { recursive: true });
    for (const name of ["assets.ts", "migrate_result.ts", "result.ts"]) {
        Deno.copyFileSync(
            new URL(
                `../extensions/behaviors/operations/instance-dev-command/${name}`,
                import.meta.url,
            ),
            `${models}/${name}`,
        );
    }
    Deno.writeTextFileSync(
        `${models}/route-proof.ts`,
        `import { z } from "npm:zod@4.4.3";
export const behavior = { type: "@fixture/route-proof", version: "2026.11.11.1", resources: { result: { schema: z.object({ready:z.boolean()}), lifetime:"1d", garbageCollection:10 } }, methods: {
  result: { description: "Return the fixture Route receipt", arguments: z.object({phase: z.enum(["route", "restore-route"])}), async execute(args: {phase: string}, ctx: {writeResource(spec:string, name:string, value:unknown): Promise<unknown>}) { Deno.writeTextFileSync(${
            JSON.stringify(folder + "/calls")
        }, args.phase + "-child\\n", {append: true}); Deno.writeTextFileSync(${JSON.stringify(folder)} + "/" + args.phase, ""); const value = {ready: true}; await ctx.writeResource("result", "result", value); return value; } }
} };`,
    );
    const route = JSON.parse(
        (await call("workflow", "create", "restore-route-child")).stdout,
    );
    Deno.writeTextFileSync(
        route.path,
        stringify({
            id: route.id,
            name: route.name,
            inputs: {type: "object", properties: {phase: {type: "string"}}, required: ["phase"]},
            jobs: [{
                name: "route",
                steps: [{
                    name: "result",
                    task: {
                        type: "behavior",
                        behaviorType: "@fixture/route-proof",
                        behaviorName: "route-proof",
                        methodName: "result",
                        inputs: {phase: "${{ inputs.phase }}"},
                    },
                }],
            }],
        }),
    );
    const created = JSON.parse(
        (await call("workflow", "create", "migrate-native-faults")).stdout,
    );
    const workflow = parse(Deno.readTextFileSync(source)) as RecordValue;
    workflow.id = created.id;
    workflow.name = created.name;
    delete workflow.vaults;
    delete workflow.labels;
    for (const job of workflow.jobs as RecordValue[]) {
        for (const step of job.steps as RecordValue[]) {
            const name = String(step.name);
            delete step.labels;
            delete step.target;
            if (name === "accepted") continue;
            if (name === "finish") {
                ((step.task as RecordValue).inputs as RecordValue).folder =
                    folder;
                continue;
            }
            if (name === "restore-route" || name === "route") {
                step.task = {
                    type: "workflow",
                    workflowIdOrName: route.name,
                    inputs: {phase: name},
                };
                continue;
            }
            let run =
                `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === "workspace") {
                run += "; printf '%s\\n' 'orbit-commit=" + "a".repeat(40) + "'";
            }
            if (name === "Apply development mail") {
                run += mode === "mail-failure" ? "; exit 42"
                    : `; printf '%s' 'invalid-json' > '${folder}/mail-result.json'`;
            }
            if (name === "t3-register") {
                run += `; printf '%s' '{"environment_id":"fixture"}'`;
            }
            if (name === "container-processes" && !mode.startsWith("mail-")) {
                run += mode === "failure"
                    ? "; exit 42"
                    : mode === "timeout"
                    ? "; timeout 0.1 sleep 20"
                    : "; sleep 30";
            }
            step.task = {
                type: "behavior",
                behaviorType: "command/shell",
                behaviorName: `migrate-fault-${name.toLowerCase().replaceAll(" ", "-")}`,
                methodName: "execute",
                inputs: { run },
            };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", created.name);
    assert(valid.ok, valid.stderr + valid.stdout);
    const input = () =>
        JSON.stringify({
            operation_id: 1,
            node_id: 1,
            kind: "instance.migrate",
            execution_secrets: {},
            intent: {
                instance_id: 1,
                container: { clone: clone ? {t3: {}} : null, mail: mode.startsWith("mail-") ? {} : null },
                route_mutations: {},
                // The Gateway always renders migrate; source only with a repository credential.
                migrate: {},
            },
        });
    const history = async () =>
        JSON.parse(
            (await call("workflow", "history", "get", created.name)).stdout,
        );
    const receipt = async () => {
        const finish = (await history()).jobs.flatMap((
            job: { steps: RecordValue[] },
        ) => job.steps).find((step: RecordValue) => step.name === "finish");
        assertEquals(finish.status, "succeeded", JSON.stringify(finish));
        return JSON.parse(finish.outputs.stdout);
    };
    return {
        folder,
        call,
        created,
        input,
        history,
        receipt,
        cleanup: () => Deno.removeSync(folder, { recursive: true }),
    };
}

for (
    const mode of ["failure", "timeout", "cancel", "deadline", "lost"] as const
) {
    Deno.test({
        name:
            `pinned migrate ${mode}: real native finish proves restoration, including the restore Route child after a lost root`,
        ignore: !binary,
        async fn() {
            const f = await nativeFixture(mode);
            let child: Deno.ChildProcess | undefined;
            let finished = false;
            try {
                child = new Deno.Command("setsid", {
                    args: [
                        binary!,
                        "workflow",
                        "run",
                        f.created.name,
                        "--stdin",
                        "--json",
                    ],
                    cwd: f.folder,
                    env: { SWAMP_NO_INBOX_NOTICE: "1" },
                    stdin: "piped",
                    stdout: "piped",
                    stderr: "piped",
                }).spawn();
                const writer = child.stdin.getWriter();
                await writer.write(new TextEncoder().encode(f.input()));
                await writer.close();
                const completion = child.output().then((done) => {
                    finished = true;
                    return done;
                });
                if (["cancel", "deadline", "lost"].includes(mode)) {
                    const until = Date.now() + 15000;
                    while (
                        !exists(`${f.folder}/container-processes`) &&
                        !finished && Date.now() < until
                    ) {
                        await new Promise((resolve) => setTimeout(resolve, 20));
                    }
                    assert(
                        exists(`${f.folder}/container-processes`),
                        finished
                            ? text((await completion).stdout) +
                                text((await completion).stderr)
                            : "The fault must occur after quiesce and during an executing phase.",
                    );
                    if (mode === "lost") {
                        const group = await new Deno.Command("ps", {
                            args: ["-o", "pgid=", "-p", String(child.pid)],
                        }).output();
                        assertEquals(
                            Number(text(group.stdout).trim()),
                            child.pid,
                        );
                        Deno.kill(-child.pid, "SIGKILL");
                    } else child.kill("SIGTERM");
                }
                const done = await completion;
                assert(!done.success, text(done.stdout) + text(done.stderr));
                assert(
                    exists(`${f.folder}/quiesce`),
                    text(done.stdout) + text(done.stderr),
                );
                assert(!exists(`${f.folder}/retire`));
                if (mode !== "lost") {
                    assert(
                        exists(`${f.folder}/restore`),
                        text(done.stdout) + text(done.stderr),
                    );
                    const result = await f.receipt();
                    const receipts = Deno.env.get("ORBIT_SWAMP_PROOF_RECEIPTS");
                    if (receipts) {
                        Deno.writeTextFileSync(
                            `${receipts}/${mode}.json`,
                            JSON.stringify(await f.history(), null, 2) + "\n",
                        );
                    }
                    const failedPhase = (await f.history()).jobs.flatMap((
                        job: { steps: RecordValue[] },
                    ) => job.steps).find((step: RecordValue) =>
                        step.name === "container-processes"
                    );
                    assertEquals(
                        failedPhase.error,
                        `Command exited with code ${
                            ["cancel", "deadline"].includes(mode)
                                ? -1
                                : mode === "timeout"
                                ? 124
                                : 42
                        }`,
                    );
                    assertEquals({
                        moved: result.moved,
                        restored: result.restored,
                        ready: result.ready,
                        error_code: result.error_code,
                    }, {
                        moved: false,
                        restored: true,
                        ready: false,
                        error_code: "instance.migrate_processes_failed",
                    });
                } else {
                    assert(!exists(`${f.folder}/restore`));
                    assert((await f.call("run", "doctor", "--fix")).ok);
                    const interrupted = await f.history();
                    assertEquals(interrupted.status, "interrupted");
                    await f.call(
                        "workflow",
                        "cancel",
                        f.created.name,
                        "--run",
                        interrupted.id,
                    );
                    assertEquals(
                        (await f.history()).status,
                        "interrupted",
                        "The release pin cannot cancel an already terminal interrupted root.",
                    );

                }
            } finally {
                if (child && !finished) {
                    try {
                        Deno.kill(-child.pid, "SIGKILL");
                    } catch { /* Only this fixture's group. */ }
                }
                f.cleanup();
            }
        },
    });
}

for (const mode of ["mail-failure", "mail-invalid"] as const) {
    for (const clone of [false, true]) {
        Deno.test({
            name: `pinned migrate advisory mail ${mode} (${clone ? "clone" : "default"}): cutover and retirement succeed without rollback`,
            ignore: !binary,
            async fn() {
                const f = await nativeFixture(mode, clone);
                try {
                    const child = new Deno.Command(binary!, {
                        args: ["workflow", "run", f.created.name, "--stdin", "--json"],
                        cwd: f.folder, env: {SWAMP_NO_INBOX_NOTICE: "1"},
                        stdin: "piped", stdout: "piped", stderr: "piped",
                    }).spawn();
                    const writer = child.stdin.getWriter();
                    await writer.write(new TextEncoder().encode(f.input()));
                    await writer.close();
                    const done = await child.output();
                    assert(done.success, text(done.stdout) + text(done.stderr));
                    const history = await f.history();
                    assertEquals(history.status, "succeeded");
                    const statuses = Object.fromEntries(history.jobs.flatMap((job: {steps: RecordValue[]}) =>
                        job.steps.map((step) => [step.name, step.status])));
                    assertEquals(statuses["Apply development mail"], mode === "mail-failure" ? "failed" : "succeeded");
                    const mailStep = history.jobs.flatMap((job: {steps: RecordValue[]}) => job.steps)
                        .find((step: RecordValue) => step.name === "Apply development mail");
                    assertEquals(mailStep.error, mode === "mail-failure" ? "Command exited with code 42" : undefined);
                    for (const name of ["container-processes", "route", "retire", "finish", "accepted"]) assertEquals(statuses[name], "succeeded");
                    assertEquals([statuses.restore, statuses["restore-route"]], ["skipped", "skipped"]);
                    if (clone) assertEquals(statuses.agent, "succeeded");
                    assert(exists(`${f.folder}/route`));
                    assert(exists(`${f.folder}/retire`));
                    assert(!exists(`${f.folder}/restore`));
                    const result = await f.receipt();
                    assertEquals([result.ready, result.moved, result.restored], [true, true, false]);
                    assertEquals(result.warnings, [{code: "instance.mail_update_failed", step: "mail"}]);
                    assertEquals([result.mail, result.mailer, result.error_code], [undefined, undefined, undefined]);
                    const receipts = Deno.env.get("ORBIT_SWAMP_PROOF_RECEIPTS");
                    if (receipts) Deno.writeTextFileSync(`${receipts}/${mode}-${clone ? "clone" : "default"}.json`, JSON.stringify(history, null, 2) + "\n");
                } finally { f.cleanup(); }
            },
        });
    }
}
