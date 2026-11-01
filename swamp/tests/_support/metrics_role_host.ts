import {
    ExecArgsSchema,
    GlobalArgsSchema,
    ScriptArgsSchema,
} from "OFFICIAL_SCHEMA_PATH";
import { z } from "npm:zod@4.4.3";
const path = FIXTURE_STATE_PATH;
async function execute(
    args: Record<string, unknown>,
    ctx: {
        globalArgs: Record<string, unknown>;
        writeResource: (s: string, n: string, v: unknown) => Promise<unknown>;
    },
) {
    const type = String(args._fixture_type), step = String(args._fixture_step);
    if (type === "@swamp/ssh") {
        GlobalArgsSchema.parse(ctx.globalArgs);
        (args._fixture_method === "script" ? ScriptArgsSchema : ExecArgsSchema)
            .parse(args);
    } else {
        const module = await import(
            OFFICIAL_CFG_PATH + type.split("/").at(-1) + ".ts"
        );
        module.model.globalArguments.parse(ctx.globalArgs);
    }
    const state = JSON.parse(Deno.readTextFileSync(path));
    const { stdin, ...publicArgs } = args;
    if (step === "password") {
        const hash = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(String(stdin)),
                ),
            ),
        ).map((n) => n.toString(16).padStart(2, "0")).join("");
        if (hash !== state.passwordHash) {
            throw new Error("fixture.password_mismatch");
        }
    }
    state.calls.push({ step, type, globals: ctx.globalArgs, args: publicArgs });
    Deno.writeTextFileSync(path, JSON.stringify(state));
    if (state.fail === step) throw new Error("fixture." + step);
    await ctx.writeResource("output", "result", { stdout: "", ready: true });
    return {};
}
export const behavior = {
    type: "@fixture/metrics-role-host",
    version: "2026.11.01.1",
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
