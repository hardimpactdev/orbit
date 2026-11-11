import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
    invokeNativeModel,
    workerUnit,
} from "../extensions/behaviors/native_host_method.ts";

const packageInput = {
    workItem: "tg-7",
    targets: [{
        path: ".",
        manager: "composer",
        package: "acme/example",
        version: "2.0.0",
        purpose: "major",
        advisories: [],
        advisoryUrls: [],
    }],
};

async function fixture(body: string) {
    const root = await Deno.makeTempDir({ prefix: "native-host-test-" });
    await Deno.mkdir(`${root}/bin`);
    await Deno.copyFile(
        new URL("../bin/native-host-start.py", import.meta.url),
        `${root}/bin/native-host-start.py`,
    );
    const executable = `${root}/command`;
    await Deno.writeTextFile(
        executable,
        `#!/usr/bin/python3\nimport os,sys,json,pathlib,time,signal,subprocess\nroot=pathlib.Path(${
            JSON.stringify(root)
        })\n${body}`,
    );
    await Deno.chmod(executable, 0o700);
    return {
        root,
        runtime: { repo: root, home: root, executable, graceMs: 100 },
    };
}

Deno.test("placed coordinator invokes nested models on its native worker with literal input", async () => {
    const f = await fixture(`
input=json.load(sys.stdin)
(root/'proof').write_text(json.dumps({'args':sys.argv[1:],'input':input,'keys':list(os.environ)}))
`);
    const OriginalCommand = Deno.Command;
    const unitCalls: string[][] = [];
    // Keep the real lease guard, child process and stdin. Substitute only the
    // host cgroup and systemd boundary; unit tests must not need a host service.
    class HostCommand {
        constructor(
            private command: string,
            private options: Deno.CommandOptions = {},
        ) {}
        output() {
            const args = this.options.args ?? [];
            let stdout = "";
            if (this.command === "/usr/bin/cat") {
                assertEquals(args, ["/proc/self/cgroup"]);
                stdout = "0::/system.slice/orbit-node-worker.service\n";
            } else {
                assertEquals(this.command, "/usr/bin/sudo");
                assertEquals(args.slice(0, 3), [
                    "-n",
                    "/usr/bin/systemctl",
                    "stop",
                ]);
                assertEquals(
                    args[3],
                    unitCalls[0].find((arg) => arg.startsWith("--unit="))!
                        .slice(7),
                );
                unitCalls.push([...args]);
            }
            return Promise.resolve({
                success: true,
                code: 0,
                signal: null,
                stdout: new TextEncoder().encode(stdout),
                stderr: new Uint8Array(),
            });
        }
        spawn() {
            assertEquals(this.command, "/usr/bin/sudo");
            const args = this.options.args ?? [];
            assertEquals(args.slice(0, 2), ["-n", "/usr/bin/systemd-run"]);
            assert(
                args.includes("--property=BindsTo=orbit-node-worker.service"),
            );
            assert(args.includes("--property=KillMode=control-group"));
            unitCalls.push([...args]);
            const [command, ...invocation] = args.slice(args.indexOf("--") + 1);
            return new OriginalCommand(command, {
                ...this.options,
                args: invocation,
            }).spawn();
        }
    }
    Deno.Command = HostCommand as unknown as typeof Deno.Command;
    try {
        const input = {
            workItem: "tg-7",
            note: "@/unread-${{ vault.get(default, secret) }}",
        };
        assertEquals(
            await invokeNativeModel(
                {
                    definition: "fixture-factory",
                    method: "status",
                    arguments: input,
                },
                undefined,
                f.runtime,
            ),
            { ok: true },
        );
        const proof = JSON.parse(await Deno.readTextFile(`${f.root}/proof`));
        assertEquals(proof.args.slice(0, 4), [
            "behavior",
            "run",
            "fixture-factory",
            "status",
        ]);
        assertEquals(proof.input, input);
        assert(proof.args.includes("--literal-inputs"));
        assert(!proof.args.includes("--server"));
        assert(!proof.keys.includes("SWAMP_REMOTE_ONLY"));
        assertEquals(
            unitCalls.length,
            2,
            "the host unit starts and stops once",
        );
    } finally {
        Deno.Command = OriginalCommand;
        await Deno.remove(f.root, { recursive: true });
    }
});

Deno.test("nested model invocation refuses CLI option injection before spawning", async () => {
    for (
        const request of [
            { definition: "--server", method: "status" },
            { definition: "factory", method: "--help" },
            { modelType: "@swamp/ssh", name: "--repo-dir", method: "status" },
        ]
    ) await assertRejects(() => invokeNativeModel(request));
});

Deno.test("the worker unit is found directly in system.slice or in its template slice", () => {
    assertEquals(
        workerUnit("0::/system.slice/orbit-node-worker.service\n"),
        "orbit-node-worker.service",
    );
    assertEquals(
        workerUnit(
            "0::/system.slice/system-orbit\\x2dswamp\\x2dworker.slice/orbit-swamp-worker@a.service\n",
        ),
        "orbit-swamp-worker@a.service",
    );
    assertEquals(
        workerUnit("0::/user.slice/user-0.slice/session-3588.scope\n"),
        undefined,
    );
    assertEquals(
        workerUnit("0::/system.slice/a.slice/b.slice/c.service\n"),
        undefined,
    );
});
