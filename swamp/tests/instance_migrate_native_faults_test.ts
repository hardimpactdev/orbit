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
type Mode = "failure" | "timeout" | "cancel" | "deadline" | "lost" | "unsafe";
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};

async function nativeFixture(mode: Mode) {
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
    assert(
        (await call("repo", "init", folder, "--tool", "none", "--no-vault")).ok,
    );
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
  result: { description: "Return the fixture Route receipt", arguments: z.object({}), async execute(_args: unknown, ctx: {writeResource(spec:string, name:string, value:unknown): Promise<unknown>}) { Deno.writeTextFileSync(${
            JSON.stringify(folder + "/calls")
        }, "restore-route-child\\n", {append: true}); const value = {ready: true}; await ctx.writeResource("result", "result", value); return value; } }
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
            jobs: [{
                name: "route",
                steps: [{
                    name: "result",
                    task: {
                        type: "behavior",
                        behaviorType: "@fixture/route-proof",
                        behaviorName: "route-proof",
                        methodName: "result",
                        inputs: {},
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
            if (name === "restore-route") {
                step.task = {
                    type: "workflow",
                    workflowIdOrName: route.name,
                    inputs: {},
                };
                continue;
            }
            let run =
                `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === "workspace") {
                run += "; printf '%s\\n' 'orbit-commit=" + "a".repeat(40) + "'";
            }
            if (name === "container-processes") {
                run += mode === "failure"
                    ? "; exit 42"
                    : mode === "timeout"
                    ? "; timeout 0.1 sleep 20"
                    : "; sleep 30";
            }
            if (mode === "lost" && ["retire", "restore-check"].includes(name)) {
                const helper = new URL(
                    "../ansible/instance_migrate_claim.py",
                    import.meta.url,
                ).pathname;
                const side = name === "retire" ? "retire" : "restore";
                const owner = name === "retire"
                    ? "${{ run.id }}"
                    : "restore-operation-2";
                run =
                    `python3 '${helper}' '${folder}/original-migrate.claim' '${side}' '${owner}' && ` +
                    run;
            }
            if (name === "restore-check" && mode === "unsafe") {
                run +=
                    `; printf '%s' '{"error_code":"instance.migrate_restore_unsafe"}' > '${folder}/result.json'; exit 42`;
            }
            step.task = {
                type: "behavior",
                behaviorType: "command/shell",
                behaviorName: `migrate-fault-${name}`,
                methodName: "execute",
                inputs: { run },
            };
        }
    }
    Deno.writeTextFileSync(created.path, stringify(workflow));
    const valid = await call("workflow", "validate", created.name);
    assert(valid.ok, valid.stderr + valid.stdout);
    const input = (restore: boolean) =>
        JSON.stringify({
            operation_id: 1,
            node_id: 1,
            kind: "instance.migrate",
            execution_secrets: {},
            intent: {
                instance_id: 1,
                container: { clone: null },
                route_mutations: {},
                restore_only: restore,
                restore_quiesced: restore,
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
                await writer.write(new TextEncoder().encode(f.input(false)));
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
                    Deno.writeTextFileSync(`${f.folder}/calls`, "");
                    const input = `${f.folder}/restore-input.json`;
                    Deno.writeTextFileSync(input, f.input(true));
                    const restored = await f.call(
                        "workflow",
                        "run",
                        f.created.name,
                        "--input-file",
                        input,
                    );
                    assert(restored.ok, restored.stdout + restored.stderr);
                    assertEquals(
                        Deno.readTextFileSync(`${f.folder}/calls`).trim().split(
                            "\n",
                        ),
                        [
                            "input",
                            "restore-check",
                            "restore-route-child",
                            "restore",
                            "cleanup",
                        ],
                    );
                    assertEquals(await f.receipt(), {
                        instance_id: 1,
                        moved: false,
                        restored: true,
                        ready: true,
                    });
                    const claim = Deno.readTextFileSync(
                        `${f.folder}/original-migrate.claim`,
                    );
                    const resumed = await f.call(
                        "workflow",
                        "resume",
                        f.created.name,
                        "--run",
                        interrupted.id,
                        "--from",
                        "retire",
                    );
                    assert(!resumed.ok, resumed.stdout + resumed.stderr);
                    assert(
                        !exists(`${f.folder}/retire`),
                        "A resumed original root must lose the permanent restore claim before retirement effects.",
                    );
                    assertEquals(
                        Deno.readTextFileSync(
                            `${f.folder}/original-migrate.claim`,
                        ),
                        claim,
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

Deno.test({
    name:
        "pinned restore ownership refusal prevents both the Route child and container removal, with the real unsafe receipt",
    ignore: !binary,
    async fn() {
        const f = await nativeFixture("unsafe");
        try {
            const input = `${f.folder}/restore-input.json`;
            Deno.writeTextFileSync(input, f.input(true));
            const done = await f.call(
                "workflow",
                "run",
                f.created.name,
                "--input-file",
                input,
            );
            assert(!done.ok, done.stdout + done.stderr);
            assertEquals(
                Deno.readTextFileSync(`${f.folder}/calls`).trim().split("\n"),
                ["input", "restore-check", "cleanup"],
            );
            const result = await f.receipt();
            assertEquals({
                restored: result.restored,
                error_code: result.error_code,
            }, {
                restored: false,
                error_code: "instance.migrate_restore_unsafe",
            });
        } finally {
            f.cleanup();
        }
    },
});

Deno.test("atomic Node claim admits exactly one competing side and keeps the complete winner after a rerun", async () => {
    const folder = Deno.makeTempDirSync();
    const helper =
        new URL("../ansible/instance_migrate_claim.py", import.meta.url)
            .pathname;
    const path = `${folder}/original-migrate.claim`;
    try {
        const calls = ["restore", "retire"].map((side) =>
            new Deno.Command("python3", {
                args: [helper, path, side, `${side}-owner`],
            }).output()
        );
        const results = await Promise.all(calls);
        assertEquals(results.filter((done) => done.success).length, 1);
        const saved = Deno.readTextFileSync(path);
        const winner = JSON.parse(saved);
        assert(typeof winner.at === "string");
        const repeated = await new Deno.Command("python3", {
            args: [helper, path, winner.side, winner.owner],
        }).output();
        assert(repeated.success, text(repeated.stderr));
        assertEquals(JSON.parse(text(repeated.stdout)), { changed: false });
        assertEquals(Deno.readTextFileSync(path), saved);
        const otherOwner = await new Deno.Command("python3", {
            args: [helper, path, winner.side, "different-owner"],
        }).output();
        assert(!otherOwner.success);
        assertEquals(Deno.readTextFileSync(path), saved);
        assertEquals([...Deno.readDirSync(folder)].map((entry) => entry.name), [
            "original-migrate.claim",
        ]);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});

Deno.test("a killed claimant leaves a complete permanent claim before any effects and its same owner continues", async () => {
    const folder = Deno.makeTempDirSync();
    const helper =
        new URL("../ansible/instance_migrate_claim.py", import.meta.url)
            .pathname;
    const crash =
        "import os, runpy, signal, sys; from pathlib import Path; claim = runpy.run_path(sys.argv[1])['claim']; claim(Path(sys.argv[2]), sys.argv[3], 'same-owner'); os.kill(os.getpid(), signal.SIGKILL)";
    try {
        for (const side of ["restore", "retire"]) {
            const path = `${folder}/${side}.claim`;
            const killed = await new Deno.Command("python3", {
                args: ["-c", crash, helper, path, side],
            }).output();
            assert(!killed.success);
            const saved = Deno.readTextFileSync(path);
            assertEquals(JSON.parse(saved).owner, "same-owner");
            const continued = await new Deno.Command("python3", {
                args: [helper, path, side, "same-owner"],
            }).output();
            assert(continued.success, text(continued.stderr));
            assertEquals(JSON.parse(text(continued.stdout)), {
                changed: false,
            });
            assertEquals(Deno.readTextFileSync(path), saved);
        }
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
