import { z } from "npm:zod@4.4.3";
const path = FIXTURE_STATE_PATH;
const hash = "FIXTURE_ENV_HASH";
async function execute(
    args: Record<string, unknown>,
    ctx: {
        globalArgs: Record<string, unknown>;
        writeResource(s: string, n: string, v: unknown): Promise<unknown>;
    },
    method: string,
) {
    const state = JSON.parse(Deno.readTextFileSync(path));
    const name = String(args.name ?? "");
    const argv = (args.argv ?? []) as string[];
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
        if (!state.present || state.state !== "running") {
            fail("fixture.endpoint_unavailable");
        }
        if (["health", "restore"].includes(state.mode)) {
            fail("fixture.health_failed");
        }
    } else if (argv.includes("ls")) {
        stdout = state.present ? `fixture ${state.spec}\n` : "";
    } else if (argv.includes("--format")) {
        stdout = argv.includes("{{.State.Status}}")
            ? `${state.state}\n`
            : `true\nprocess\n9\n${state.spec}\n${state.state}\n`;
    } else if (argv.includes("run")) {
        const install = {
            spec: argv.find((value) =>
                value.startsWith("orbit.process.spec=")
            )!.split("=")[1],
        };
        const tag = install.spec[0];
        state.calls.push("create:" + tag);
        if (
            state.mode === "install" && tag === "b" ||
            state.mode === "restore" && tag === "a"
        ) fail("fixture.create_failed");
        state.spec = install.spec;
        state.present = true;
        state.state = "running";
    } else if (argv.includes("start")) state.state = "running";
    else if (argv.includes("stop")) state.state = "exited";
    else if (
        ctx.globalArgs.ensure === "absent" &&
        typeof ctx.globalArgs.name === "string"
    ) {
        if (method === "apply" && state.present) {
            state.calls.push("remove");
            state.present = false;
            state.state = "absent";
            if (state.mode === "partial-remove" && !state.removeFailed) {
                state.removeFailed = true;
                fail("fixture.remove_reply_lost");
            }
        }
        await ctx.writeResource("state", String(ctx.globalArgs.nodeHost), {
            status: state.present ? "non_compliant" : "compliant",
            current: { containerExists: state.present },
            changes: [],
        });
    }
    if (
        method === "check" && ctx.globalArgs.ensure !== "absent" &&
        typeof ctx.globalArgs.image === "string"
    ) {
        await ctx.writeResource("state", String(ctx.globalArgs.nodeHost), {
            status: state.present && state.state === "running"
                ? "compliant"
                : "non_compliant",
            current: {
                containerExists: state.present,
                containerStatus: state.state,
            },
            changes: [],
        });
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
        state: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: Object.fromEntries(
        ["exec", "copy", "apply", "check"].map(
            (name) => [name, {
                description: "Disposable host fixture",
                arguments: z.record(z.string(), z.unknown()),
                execute: (
                    args: Record<string, unknown>,
                    ctx: Parameters<typeof execute>[1],
                ) => execute(args, ctx, name),
            }],
        ),
    ),
};
