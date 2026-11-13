/** Task VM workflow commands run directly against local fakes: the Pi probes of a resume. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { trackedStep } from "../../../../bin/gate-test-support.ts";

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
