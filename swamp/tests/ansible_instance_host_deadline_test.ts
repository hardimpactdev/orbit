/** Exercise the shipped Instance host tasks against disposable local boundaries. */
import {
    assert,
    assertEquals,
    assertRejects,
    assertStringIncludes,
} from "jsr:@std/assert@1";
import {
    bridgeAdmits,
    bridgeAnswersDns,
    caddy,
    fixture,
    GiB,
    hasAnsible,
    nodeAddresses,
    peer,
} from "./_support/instance_host.ts";

// Rescue old implementations without ever signalling a reused PID.
function rescueFixtureChild(folder: string, pidFile: string) {
    new Deno.Command("python3", {
        args: [
            "-c",
            `import os, signal
try:
    pid = int(open(${JSON.stringify(pidFile)}).read())
    fd = os.pidfd_open(pid)
    if ${JSON.stringify(folder)} in open(f"/proc/{pid}/cmdline").read():
        signal.pidfd_send_signal(fd, signal.SIGKILL)
    os.close(fd)
except (FileNotFoundError, ProcessLookupError):
    pass
`,
        ],
    }).outputSync();
}

Deno.test("a stuck Instance host playbook throws with its last task and kills a descendant holding stdout", async () => {
    const f = await fixture();
    try {
        const stub = `${f.folder}/bin/ansible-playbook`;
        // The leader exits on TERM, but its child ignores it and keeps the output pipe open.
        // timeout --kill-after cannot finish collecting output in that case: its leader has already exited.
        Deno.writeTextFileSync(
            `${f.folder}/stuck.py`,
            `import os, signal, subprocess, sys
signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
child = subprocess.Popen([sys.executable, "-c", '''import os, signal
signal.signal(signal.SIGTERM, signal.SIG_IGN)
open(${JSON.stringify(f.folder + "/stuck.pid")}, "w").write(str(os.getpid()))
print("TASK [fixture barrier never returns]", flush=True)
signal.pause()
'''])
child.wait()
`,
        );
        Deno.writeTextFileSync(
            stub,
            `#!/bin/sh\nexec python3 '${f.folder}/stuck.py'\n`,
        );
        Deno.chmodSync(stub, 0o755);
        const error = await assertRejects(
            () => f.run(1),
            Error,
            "Instance host playbook timed out after 1s",
        );
        assertStringIncludes(
            error.message,
            "TASK [fixture barrier never returns]",
        );
        const pid = Deno.readTextFileSync(`${f.folder}/stuck.pid`).trim();
        const status = new TextDecoder().decode(
            new Deno.Command("ps", { args: ["-o", "stat=", "-p", pid] })
                .outputSync().stdout,
        ).trim();
        // An orphan killed with its parent can briefly be a zombie until PID 1 reaps it.
        assert(
            status === "" || status.startsWith("Z"),
            `descendant ${pid} survived: ${status}`,
        );
    } finally {
        f.cleanup();
    }
});

Deno.test("a late playbook gets only the shared command budget left, then no new playbook starts", () => {
    const directory = Deno.makeTempDirSync();
    try {
        const script = `${directory}/budget.ts`;
        Deno.writeTextFileSync(
            script,
            String.raw`
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
let clock = 0;
Date.now = () => clock;
Deno.env.delete("ORBIT_INSTANCE_HOST_DEADLINE_MS");
const { fixture } = await import(${
                JSON.stringify(
                    new URL("./_support/instance_host.ts", import.meta.url)
                        .href,
                )
            });
const f = await fixture();
try {
    Deno.writeTextFileSync(f.folder + "/bin/ansible-playbook", '#!/bin/sh\necho started >> "$HOST_FIXTURE/starts"\nexec sleep 60\n');
    Deno.chmodSync(f.folder + "/bin/ansible-playbook", 0o755);
    clock = 719_900;
    const started = performance.now();
    await assertRejects(() => f.run(), Error, "timed out after 0.1s");
    assert(performance.now() - started < 4500, "late playbook received a fresh allowance");
    clock = 720_001;
    await assertRejects(() => f.run(), Error, "exhausted its 720s budget");
    assertEquals(Deno.readTextFileSync(f.folder + "/starts"), "started\n");
} finally {
    f.cleanup();
}
`,
        );
        const result = new Deno.Command(Deno.execPath(), {
            args: ["run", "-A", script],
        }).outputSync();
        assert(result.success, new TextDecoder().decode(result.stderr));
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});

Deno.test("the shared command budget stays exhausted across Deno test file isolates", () => {
    const directory = Deno.makeTempDirSync();
    try {
        const clockFile = `${directory}/clock`;
        Deno.writeTextFileSync(clockFile, "0");
        const files = ["first_test.ts", "second_test.ts"].map((name, index) => {
            const file = `${directory}/${name}`;
            Deno.writeTextFileSync(
                file,
                `
import { assert, assertRejects } from "jsr:@std/assert@1";
let clock = Number(Deno.readTextFileSync(${JSON.stringify(clockFile)}));
Date.now = () => 1_000_000_000_000 + clock;
Object.defineProperty(performance, "now", { value: () => clock });
const { fixture } = await import(${
                    JSON.stringify(
                        new URL("./_support/instance_host.ts", import.meta.url)
                            .href,
                    )
                });
Deno.test("${
                    index === 0
                        ? "spend the command budget"
                        : "refuse a launch in the next isolate"
                }", async () => {
    const f = await fixture();
    try {
        Deno.writeTextFileSync(f.folder + "/bin/ansible-playbook", '#!/bin/sh\\necho started > "$HOST_FIXTURE/started"\\n');
        Deno.chmodSync(f.folder + "/bin/ansible-playbook", 0o755);
        ${
                    index === 0
                        ? `
        assert((await f.run()).ok);
        Deno.removeSync(f.folder + "/started");
        clock = 720_001;
        Deno.writeTextFileSync(${JSON.stringify(clockFile)}, String(clock));
        `
                        : ""
                }
        await assertRejects(() => f.run(), Error, "exhausted its 720s budget");
        assert(!f.has("started"), "a playbook launched after the shared budget expired");
    } finally {
        f.cleanup();
    }
});
`,
            );
            return file;
        });
        const result = new Deno.Command(Deno.execPath(), {
            args: ["test", "-A", ...files],
            env: {
                DENO_JOBS: "1",
                ORBIT_INSTANCE_HOST_DEADLINE_MS: "1000000720000",
            },
        }).outputSync();
        const decoder = new TextDecoder();
        assert(
            result.success,
            decoder.decode(result.stdout) + decoder.decode(result.stderr),
        );
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});

Deno.test("an ordinary playbook refusal preserves its exit status, including 124", async () => {
    const f = await fixture();
    try {
        Deno.writeTextFileSync(
            `${f.folder}/bin/ansible-playbook`,
            "#!/bin/sh\necho fixture-refusal >&2\nexit 124\n",
        );
        Deno.chmodSync(`${f.folder}/bin/ansible-playbook`, 0o755);
        const result = await f.run();
        assert(!result.ok);
        assertStringIncludes(result.output, "fixture-refusal");
    } finally {
        f.cleanup();
    }
});

// These leaders disappear before cleanup can discover their descendants by ancestry.
for (const duringTerm of [false, true]) {
    Deno.test(`deadline reaps a detached stdout holder forked ${duringTerm ? "during TERM" : "before leader exit"}`, async () => {
        const f = await fixture();
        let timer: ReturnType<typeof setTimeout> | undefined;
        let running: Promise<unknown> | undefined;
        try {
            Deno.writeTextFileSync(
                `${f.folder}/orphan.py`,
                `import os, signal, subprocess, sys

def detach(*_):
    child = subprocess.Popen([sys.executable, "-c", '''import os, signal, time
os.setsid()
signal.signal(signal.SIGTERM, signal.SIG_IGN)
with open(${JSON.stringify(f.folder + "/orphan.pid")}, "w") as output:
    output.write(str(os.getpid()))
print("TASK [detached stdout holder]", flush=True)
time.sleep(60)
'''])
    sys.exit(0)

${
                    duringTerm
                        ? "signal.signal(signal.SIGTERM, detach)\nsignal.pause()"
                        : "detach()"
                }
`,
            );
            Deno.writeTextFileSync(
                `${f.folder}/bin/ansible-playbook`,
                `#!/bin/sh\nexec python3 '${f.folder}/orphan.py'\n`,
            );
            Deno.chmodSync(`${f.folder}/bin/ansible-playbook`, 0o755);
            running = f.run(1);
            const error = await assertRejects(
                () =>
                    Promise.race([
                        running!,
                        new Promise((_, reject) => {
                            timer = setTimeout(
                                () =>
                                    reject(new Error("cleanup exceeded 4.5s")),
                                4500,
                            );
                        }),
                    ]),
                Error,
                "Instance host playbook timed out after 1s",
            );
            assertStringIncludes(
                error.message,
                "TASK [detached stdout holder]",
            );
            const pid = Deno.readTextFileSync(`${f.folder}/orphan.pid`).trim();
            let exists = true;
            try {
                Deno.statSync(`/proc/${pid}`);
            } catch (error) {
                if (!(error instanceof Deno.errors.NotFound)) throw error;
                exists = false;
            }
            assert(!exists, `orphan ${pid} was not reaped`);
        } finally {
            clearTimeout(timer);
            rescueFixtureChild(f.folder, `${f.folder}/orphan.pid`);
            await running?.catch(() => {});
            f.cleanup();
        }
    });
}

Deno.test({
    name:
        "a real Ansible deadline kills a stalled command after its worker detaches",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        const pidFile = `${f.folder}/stalled.pid`;
        try {
            Deno.writeTextFileSync(
                `${f.folder}/play.yml`,
                JSON.stringify([{
                    hosts: "localhost",
                    gather_facts: false,
                    tasks: [{
                        name: "real Ansible child stalls after worker detaches",
                        "ansible.builtin.command": {
                            argv: [
                                "{{ ansible_playbook_python }}",
                                "-c",
                                `import os, signal, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
with open(${JSON.stringify(pidFile)}, "w") as output:
    output.write(str(os.getpid()))
time.sleep(60)
`,
                            ],
                        },
                    }],
                }]),
            );
            const error = await assertRejects(
                () => f.run(5),
                Error,
                "Instance host playbook timed out after 5s",
            );
            assertStringIncludes(
                error.message,
                "TASK [real Ansible child stalls after worker detaches]",
            );
            const pid = Number(Deno.readTextFileSync(pidFile).trim());
            // A killed orphan can wait briefly for PID 1 to reap it.
            for (let attempt = 0; attempt < 100; attempt++) {
                try {
                    Deno.statSync(`/proc/${pid}`);
                } catch (error) {
                    if (error instanceof Deno.errors.NotFound) return;
                    throw error;
                }
                await new Promise((resolve) => setTimeout(resolve, 10));
            }
            throw new Error(
                `stalled Ansible command ${pid} survived its deadline`,
            );
        } finally {
            rescueFixtureChild(f.folder, pidFile);
            f.cleanup();
        }
    },
});


Deno.test("the default playbook hang guard allows four minutes before the shared deadline cap", async () => {
    const f = await fixture();
    try {
        // Record the supervisor allowance without running a real playbook or waiting for the guard.
        Deno.writeTextFileSync(`${f.folder}/bin/python3`, `#!/bin/sh
printf '%s' "$2" > '${f.folder}/guard'
printf '{"timedOut":false}' > "$3"
`);
        Deno.chmodSync(`${f.folder}/bin/python3`, 0o755);
        assert((await f.run()).ok);
        assertEquals(Deno.readTextFileSync(`${f.folder}/guard`), "240");
    } finally {
        f.cleanup();
    }
});
