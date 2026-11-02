import {
    ExecArgsSchema,
    GlobalArgsSchema,
    ScriptArgsSchema,
} from "OFFICIAL_SCHEMA_PATH";
import { z } from "npm:zod@4.4.3";
const statePath = FIXTURE_STATE_PATH, callsPath = FIXTURE_CALLS_PATH;
// One appended line per call keeps the order of parallel forEach items without a shared rewrite.
async function execute(
    args: Record<string, unknown>,
    ctx: {
        globalArgs: Record<string, unknown>;
        writeResource: (s: string, n: string, v: unknown) => Promise<unknown>;
    },
) {
    const type = String(args._fixture_type);
    const workflow = String(args._fixture_workflow);
    const step = String(args._fixture_step);
    if (type === "@swamp/ssh") {
        GlobalArgsSchema.parse(ctx.globalArgs);
        (args._fixture_method === "script" ? ScriptArgsSchema : ExecArgsSchema)
            .parse(args);
    } else if (type !== "stub") {
        const module = await import(
            OFFICIAL_CFG_PATH + type.split("/").at(-1) + ".ts"
        );
        module.model.globalArguments.parse(ctx.globalArgs);
    }
    const state = JSON.parse(Deno.readTextFileSync(statePath));
    const { stdin, ...publicArgs } = args;
    let stdinSha256: string | null = null;
    if (stdin !== undefined) {
        stdinSha256 = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(String(stdin)),
                ),
            ),
        ).map((n) => n.toString(16).padStart(2, "0")).join("");
    }
    Deno.writeTextFileSync(
        callsPath,
        JSON.stringify({
            workflow,
            step,
            type,
            method: args._fixture_method,
            globals: ctx.globalArgs,
            args: publicArgs,
            stdinSha256,
        }) + "\n",
        { append: true },
    );
    if ((state.fail as string[]).includes(workflow + ":" + step)) {
        throw new Error("fixture." + workflow + "." + step);
    }
    await ctx.writeResource("output", "result", { stdout: "", ready: true });
    return {};
}
export const behavior = {
    type: "@fixture/metrics-role-host",
    version: "2026.11.02.1",
    globalArguments: z.record(z.string(), z.unknown()),
    resources: {
        output: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: Object.fromEntries(
        ["exec", "script", "apply", "restart"].map(
            (name) => [name, {
                description: "Official schema with synthetic host effect",
                arguments: z.record(z.string(), z.unknown()),
                execute,
            }],
        ),
    ),
};
