import { ExecArgsSchema, GlobalArgsSchema } from "OFFICIAL_SCHEMA_PATH";
import { z } from "npm:zod@4.4.3";
const path = FIXTURE_STATE_PATH;
async function execute(a: Record<string, unknown>, ctx: {
    globalArgs: Record<string, unknown>;
    writeResource: (s: string, n: string, v: unknown) => Promise<unknown>;
}) {
    const type = String(a._fixture_type), step = String(a._fixture_name);
    if (type === "@swamp/ssh") {
        GlobalArgsSchema.parse(ctx.globalArgs);
        ExecArgsSchema.parse(a);
    } else {
        const module = await import(
            OFFICIAL_CFG_PATH + type.split("/").at(-1) + ".ts"
        );
        module.model.globalArguments.parse(ctx.globalArgs);
    }
    const s = JSON.parse(Deno.readTextFileSync(path));
    s.calls.push({ step, type, globals: ctx.globalArgs, args: a });
    const fail = () => {
        Deno.writeTextFileSync(path, JSON.stringify(s));
        throw new Error("fixture." + step);
    };
    if (s.fail === step) fail();
    const g = ctx.globalArgs;
    if (step === "candidate") s.candidate = g.content;
    // publish copies the validated candidate over the live file in place.
    if (step === "publish") {
        if (s.candidate === null) fail();
        s.published = s.candidate;
    }
    if (step === "remove_candidate") s.candidate = null;
    // SIGHUP loads the published file; the targets read shows what Prometheus loaded.
    if (step === "reload") s.loaded = s.published;
    Deno.writeTextFileSync(path, JSON.stringify(s));
    const stdout = step === "targets" ? String(s.loaded) : "";
    await ctx.writeResource("output", "result", { stdout, ready: true });
    return {};
}
export const behavior = {
    type: "@fixture/metrics-host",
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
        ["exec", "apply", "restart"].map((name) => [name, {
            description: "Disposable host effect with actual official schemas",
            arguments: z.record(z.string(), z.unknown()),
            execute,
        }]),
    ),
};
