import { z } from "npm:zod@4.4.3";
const path = FIXTURE_STATE_PATH;
const hash = "FIXTURE_ENV_HASH";
async function execute(
    args: Record<string, unknown>,
    ctx: {
        globalArgs: Record<string, unknown>;
        writeResource(s: string, n: string, v: unknown): Promise<unknown>;
    },
) {
    const state = JSON.parse(Deno.readTextFileSync(path));
    const name = String(args.name ?? "");
    const argv = (args.argv ?? []) as string[];
    const command = String(ctx.globalArgs.command ?? "");
    let stdout = "";
    const fail = (code: string) => {
        Deno.writeTextFileSync(path, JSON.stringify(state));
        throw new Error(code);
    };
    if (
        ctx.globalArgs.content !== undefined &&
        String(ctx.globalArgs.path).endsWith("intent.json")
    ) state.intent = JSON.parse(String(ctx.globalArgs.content));
    if (argv[0] === "/usr/bin/getent") {
        stdout = "orbit:x:1000:1000::/home/orbit:/bin/sh\n";
    } else if (name === "createdb" || name === "migrate") {
        const digest = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(String(args.stdin)),
                ),
            ),
            (b) => b.toString(16).padStart(2, "0"),
        ).join("");
        if (digest !== hash) fail("fixture.missing_private_stdin");
        state.calls.push(name);
        if (state.mode === "initialization" && name === "migrate") {
            fail("analytics.initialization_failed");
        }
    } else if (name === "health") {
        state.calls.push(name);
        const control = JSON.parse(
            argv.find((x) => x.startsWith("ORBIT_PROCESS_CONTROL="))!.slice(
                "ORBIT_PROCESS_CONTROL=".length,
            ),
        );
        if (control.expected_spec !== state.spec) fail("process.spec_changed");
        if (["health", "restore"].includes(state.mode)) {
            fail("fixture.health_failed");
        }
    } else if (argv.includes("--format")) {
        stdout = `true\nprocess\n9\n${state.spec}\n${state.state}\n`;
    } else if (argv.some((x) => x.endsWith("inspect.py"))) {
        stdout = JSON.stringify({
            present: state.present,
            state: state.state,
            spec: state.spec,
        });
    } else if (argv.some((x) => x.endsWith("lifecycle_container.py"))) {
        const spec = state.intent.installation.spec;
        const tag = spec[0];
        state.calls.push("create:" + tag);
        if (
            state.mode === "install" && tag === "b" ||
            state.mode === "restore" && tag === "a"
        ) fail("fixture.create_failed");
        if (state.present && state.spec !== spec) fail("process.spec_changed");
        state.spec = spec;
        state.present = true;
        state.state = "created";
    } else if (command.includes("container stop")) state.state = "exited";
    else if (command.includes("container rm")) {
        state.calls.push("remove");
        state.present = false;
        state.state = "absent";
        if (state.mode === "partial-remove" && !state.removeFailed) {
            state.removeFailed = true;
            fail("fixture.remove_reply_lost");
        }
    } else if (command.includes("container start")) state.state = "running";
    else if (argv.some((x) => x.endsWith("verify.py"))) {
        if (!state.present || state.state !== "running") {
            fail("fixture.verify_failed");
        }
        stdout = '{"ready":true,"state":"running"}';
    }
    Deno.writeTextFileSync(path, JSON.stringify(state));
    const value = { stdout, ready: true };
    await ctx.writeResource("result", "result", value);
    return value;
}
export const behavior = {
    type: "@fixture/analytics-host",
    version: "2026.11.01.1",
    globalArguments: z.record(z.string(), z.unknown()),
    resources: {
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: Object.fromEntries(
        ["exec", "copy", "apply"].map(
            (name) => [name, {
                description: "Disposable host fixture",
                arguments: z.record(z.string(), z.unknown()),
                execute,
            }],
        ),
    ),
};
