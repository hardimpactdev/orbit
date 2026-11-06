/** Task VM workflow commands run directly against local fakes: the Incus start wait, known_hosts edits and the Pi probes. */
import { assert, assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { ROOT, trackedStep } from "../../../../bin/gate-test-support.ts";

const decoder = new TextDecoder();
async function run(
    argv: string[],
    options: { env?: Record<string, string>; stdin?: string } = {},
) {
    const child = new Deno.Command(argv[0], {
        args: argv.slice(1),
        env: options.env,
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(options.stdin ?? ""));
    await writer.close();
    const result = await child.output();
    return {
        code: result.code,
        stdout: decoder.decode(result.stdout),
        stderr: decoder.decode(result.stderr),
    };
}

/** The Incus start wait: Incus's own bounded wait for the guest agent, then one observation. */
async function startWait(agentOk: boolean) {
    const argv = trackedStep("task-vm-provider-power", "incus-after").task
        .inputs.argv as string;
    const marker = '"/bin/sh", "-c", "';
    const start = argv.indexOf(marker) + marker.length;
    const script = JSON.parse(
        '"' +
            argv.slice(
                start,
                argv.indexOf('", "sh", inputs.intent.vm.project'),
            ) + '"',
    ) as string;
    const directory = await Deno.makeTempDir();
    try {
        const calls = `${directory}/calls`;
        await Deno.writeTextFile(
            `${directory}/sudo`,
            `#!/bin/sh\necho "$*" >> ${calls}\ncase "$*" in *" wait "*) exit ${
                agentOk ? 0 : 1
            };; esac\n` +
                `printf '[{"name":"tvm-42","type":"virtual-machine","status":"Running"}]\\n'\n`,
            { mode: 0o755 },
        );
        const result = await run([
            "timeout",
            "20",
            "/bin/sh",
            "-c",
            script.replaceAll("/usr/bin/sudo", `${directory}/sudo`),
            "sh",
            "tasks",
            "tvm-42",
        ]);
        return {
            ...result,
            calls: (await Deno.readTextFile(calls)).split("\n").filter(Boolean),
        };
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
}

Deno.test("the start wait waits for the guest agent, then prints one observation", async () => {
    const result = await startWait(true);
    assertEquals(result.code, 0, result.stderr);
    assertEquals(JSON.parse(result.stdout)[0].status, "Running");
    assertEquals(result.calls, [
        "-n -- /usr/bin/incus --project tasks wait tvm-42 agent --timeout 120 --interval 1",
        result.calls[1],
    ]);
});

Deno.test("an agent that never comes fails the start wait without an observation", async () => {
    const result = await startWait(false);
    assertNotEquals(result.code, 0);
    assertEquals([result.stdout, result.calls.length], ["", 1]);
});

Deno.test("the start wait is bounded", async () => {
    assert(
        (await Deno.readTextFile(
            `${ROOT}/workflows/workflow-task-vm-provider-power.yaml`,
        )).includes('["timeout", "130", "/bin/sh", "-c"'),
    );
});

const Old =
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";
const New =
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJBLJu/2IaglMhHEKUH6cwtP0rPJz+E9BUcJ/AVFJqx+";
const Other =
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOlS8xvUK3QXeFKFBC3whbWYKkjw4O9fuXv9AWPvUS0T";

/** Task VMs reuse addresses: enrollment pins the observed key for its address, and destroy forgets it. */
async function knownHosts(
    workflow: string,
    step: string,
    before: string,
    env: Record<string, string>,
) {
    const home = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${home}/.ssh`, { mode: 0o700 });
        await Deno.writeTextFile(`${home}/.ssh/known_hosts`, before);
        const result = await run([
            "sh",
            "-c",
            trackedStep(workflow, step).task.inputs.run as string,
        ], {
            env: { ...Deno.env.toObject(), HOME: home, ...env },
        });
        assertEquals(result.code, 0, result.stderr);
        return (await Deno.readTextFile(`${home}/.ssh/known_hosts`)).split("\n")
            .filter(Boolean);
    } finally {
        await Deno.remove(home, { recursive: true });
    }
}

Deno.test("enrollment replaces a stale guest key and keeps other hosts", async () => {
    const lines = await knownHosts(
        "task-vm-enrollment",
        "Pin the guest host key",
        `10.44.0.133 ${Old}\n10.44.0.2 ${Other}\n`,
        {
            ORBIT_ADDRESS: "10.44.0.133",
            ORBIT_LINES: `10.44.0.133 ${New}`,
        },
    );
    assert(lines.includes(`10.44.0.133 ${New}`));
    assert(!lines.includes(`10.44.0.133 ${Old}`));
    assert(lines.includes(`10.44.0.2 ${Other}`));
});

Deno.test("destroy forgets only the guest's address", async () => {
    assertEquals(
        await knownHosts(
            "task-vm-destroy",
            "Forget the guest host key",
            `10.44.0.133 ${New}\n10.44.0.2 ${Other}\n`,
            {
                ORBIT_ADDRESS: "10.44.0.133",
            },
        ),
        [`10.44.0.2 ${Other}`],
    );
});

Deno.test("resume probes the captured Pi bind address with a header-file bearer", async () => {
    const seen: [string, string | null][] = [];
    const server = Deno.serve({
        hostname: "127.0.0.2",
        port: 0,
        onListen: () => {},
    }, (request) => {
        const path = new URL(request.url).pathname;
        seen.push([path, request.headers.get("authorization")]);
        return new Response(null, {
            status: path === "/capabilities" ? 200 : 503,
        });
    });
    const directory = await Deno.makeTempDir();
    try {
        const secret = "guest-local-probe-secret";
        const token = `${directory}/token`;
        await Deno.writeTextFile(token, secret);
        const url = `http://127.0.0.2:${server.addr.port}`;
        const env = { ...Deno.env.toObject(), no_proxy: "*", NO_PROXY: "*" };
        const read = trackedStep(
            "operation-task-vm-resume",
            "Read the guest state",
        ).task.inputs.argv as string;
        // The Pi read is the CEL string literal after `"-c", ` in the forEach argv; CEL and JSON share its escapes.
        const code =
            (JSON.parse(read.match(/"-c", ("(?:[^"\\]|\\.)*")/)![1]) as string)
                .replaceAll(
                    "/home/orbit/.pi/agent/orbit-token",
                    token,
                );
        for (
            const [suffix, expected] of [["", 0], ["/unavailable", 1]] as const
        ) {
            const result = await run(["python3", "-c", code], {
                env,
                stdin: JSON.stringify({ pi_url: url + suffix, pi_port: 1 }),
            });
            assertEquals(result.code, expected, result.stderr);
            assert(!(result.stdout + result.stderr).includes(secret));
        }
        assertEquals(seen.length, 2);
        assert(seen.every(([, auth]) => auth === `Bearer ${secret}`));
    } finally {
        await server.shutdown();
        await Deno.remove(directory, { recursive: true });
    }
});
