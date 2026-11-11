/** The shipped migrate DAG as local control flow on the pin. Placement and machine tasks are fixtures; Ansible cases prove effects. */
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
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};

async function nativeFixture(
    mode: "failure" | "timeout" | "cancel" | "deadline" | "lost",
) {
    const folder = Deno.makeTempDirSync({ prefix: "migrate-native-" });
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
            if (name === "accepted") {
                step.task = {
                    type: "assert",
                    expr: "steps.finish.outputs.exitCode == 0",
                    severity: "high",
                    message: "Fixture finish failed",
                };
                continue;
            }
            let run =
                `printf '%s\\n' '${name}' >> '${folder}/calls'; touch '${folder}/${name}'`;
            if (name === "container-processes") {
                run += mode === "failure"
                    ? "; exit 42"
                    : mode === "timeout"
                    ? "; timeout 0.1 sleep 20"
                    : "; sleep 30";
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
                route_mutations: null,
                restore_only: restore,
                restore_quiesced: restore,
            },
        });
    return {
        folder,
        call,
        created,
        input,
        cleanup: () => Deno.removeSync(folder, { recursive: true }),
    };
}

for (
    const mode of ["failure", "timeout", "cancel", "deadline", "lost"] as const
) {
    Deno.test({
        name:
            `pinned migrate DAG ${mode}: native restore finishes or a restore-only run has no forward work`,
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
                    assert(exists(`${f.folder}/finish`));
                } else {
                    assert(!exists(`${f.folder}/restore`));
                    assert(!exists(`${f.folder}/finish`));
                    assert((await f.call("run", "doctor", "--fix")).ok);
                    const history = JSON.parse(
                        (await f.call(
                            "workflow",
                            "history",
                            "get",
                            f.created.name,
                        )).stdout,
                    );
                    assertEquals(history.status, "interrupted");
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
                        ["input", "restore", "finish", "cleanup"],
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
