import { z } from "npm:zod@4.4.3";
import { AdoptInput, Facts, Observation, queryScript, repositoryError } from "./adopt.ts";
import { homebrewRemovalBlock } from "./adopt_homebrew.ts";
import { repositoryJson, sourceInput, ToolRefusal } from "./contract.ts";
import { writeInventory } from "./write.ts";
export const RemoveInput = AdoptInput.safeExtend({ intent_only: z.boolean() });
type Input = z.infer<typeof RemoveInput>;
const Public = z.record(z.string(), z.unknown());
const Probe = z.object({ ready: z.boolean(), present: z.boolean().optional(), facts: Facts.optional(), error_code: z.string().optional() });
type Context = { extensionFile(name: string): string; writeResource(spec: string, name: string, value: unknown): Promise<unknown> };
export function removalProbe(input: Input, raw: unknown) {
    try {
        const { facts, present } = writeInventory(raw);
        if (present && input.manager === "composer" && !facts.roots?.includes(input.package)) throw new ToolRefusal("tool.remove_failed");
        if (present && homebrewRemovalBlock(input, facts)) throw new ToolRefusal("tool.remove_failed");
        return { ready: true, present, facts };
    } catch (error) {
        return { ready: false, error_code: error instanceof ToolRefusal ? error.code : "tool.version_probe_failed" };
    }
}
const Step = z.object({ status: z.string(), outputs: Public.optional() }).passthrough();
export function removalResult(input: Input, steps: Record<string, z.infer<typeof Step>>) {
    const before = Probe.safeParse(steps["before-0"]?.outputs);
    const fact = before.success ? before.data : null;
    const success = (name: string) => steps[name + "-0"]?.status === "succeeded";
    let error = null;
    if (!input.intent_only) {
        if (!success("before") || !fact?.ready) error = fact?.error_code ?? "tool.version_probe_failed";
        else if (fact.present) {
            if (input.manager === "apt" && (!success("plan") || steps["plan-0"]?.outputs?.ready !== true)) {
                error = steps["plan-0"]?.outputs?.error_code ?? "tool.remove_failed";
            } else if (!success("remove") || !success("after") || steps["after-0"]?.outputs?.ready !== true ||
                steps["after-0"]?.outputs?.present !== false) error = "tool.remove_failed";
        }
    }
    if (error === null && input.remove_repository && input.repository) {
        error = success("repository_cleanup") ? repositoryError(steps["repository_cleanup-0"]?.outputs) : "tool.repository_failed";
    }
    return { ok: error === null, stdout: JSON.stringify({ manager_version: fact?.facts?.manager_version ?? null,
        installed_version: error === null ? null : input.installed_version, ...(error ? { error_code: error } : { outcome: "applied" }) }) };
}
export const behavior = {
    type: "@nckrtl/tool-remove-command", version: "2026.11.01.2",
    resources: {
        assets: { schema: z.object({ script_body: z.string(), repository_body: z.string(), repository_json: z.string() }), lifetime: "1d", garbageCollection: 10 },
        probe: { schema: Probe, lifetime: "1d", garbageCollection: 10 },
        plan: { schema: z.object({ ready: z.boolean(), error_code: z.string().optional() }), lifetime: "1d", garbageCollection: 10 },
        result: { schema: z.object({ stdout: z.string(), ok: z.boolean() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description: "Read one canonical manager query or removal action",
            arguments: z.object({ action: z.enum(["query", "plan", "remove"]), input: RemoveInput, before: Probe.optional() }),
            execute: async (args: { action: "query" | "plan" | "remove"; input: Input; before?: z.infer<typeof Probe> }, ctx: Context) => {
                let script_body;
                if (args.action === "query") script_body = await queryScript(args.input, ctx);
                else {
                    const file = args.action === "plan" ? "apt_plan" : args.input.manager.replace("-", "_");
                    let source = await Deno.readTextFile(ctx.extensionFile("mutations/" + file + ".py"));
                    if (args.action === "remove" && (!args.before?.ready || !args.before.present || !args.before.facts)) {
                        throw new ToolRefusal("tool.remove_failed");
                    }
                    const input = { ...sourceInput(args.input), before: args.before?.facts };
                    if (args.input.manager !== "apt") source = await Deno.readTextFile(ctx.extensionFile("queries/support.py")) + "\n" + source.replace(/^from support import [^\n]*\n/gm, "");
                    const encoded = JSON.stringify(JSON.stringify(input));
                    script_body = args.input.manager === "apt"
                        ? source.replace("REMOVE_INPUT = None", "REMOVE_INPUT = json.loads(" + encoded + ")")
                        : source.replace("QUERY_INPUT = None", "QUERY_INPUT = " + encoded);
                }
                const result = { script_body, repository_body: await Deno.readTextFile(ctx.extensionFile("apt_repository.py")), repository_json: repositoryJson(args.input) }; await ctx.writeResource("assets", "assets", result); return result;
            },
        },
        observe: {
            description: "Select installed facts without hiding invalid or incomplete reads",
            arguments: z.object({ input: RemoveInput, observation: Observation }),
            execute: async (args: { input: Input; observation: z.infer<typeof Observation> }, ctx: Context) => {
                const result = removalProbe(args.input, args.observation); await ctx.writeResource("probe", "probe", result); return result;
            },
        },
        plan: {
            description: "Refuse an apt plan that removes any other package",
            arguments: z.object({ package: z.string(), observation: Observation }),
            execute: async (args: { package: string; observation: z.infer<typeof Observation> }, ctx: Context) => {
                const packages = z.object({ packages: z.array(z.string()) }).parse(JSON.parse(args.observation.stdout)).packages;
                const ready = packages.length === 1 && packages[0] === args.package;
                const result = { ready, ...(!ready ? { error_code: "tool.removal_plan_unsafe" } : {}) };
                await ctx.writeResource("plan", "plan", result); return result;
            },
        },
        result: {
            description: "Project only proven absence or admitted intent retirement",
            arguments: z.object({ input: RemoveInput, steps: z.record(z.string(), Step) }),
            execute: async (args: { input: Input; steps: Record<string, z.infer<typeof Step>> }, ctx: Context) => {
                const result = removalResult(args.input, args.steps); await ctx.writeResource("result", "result", result); return result;
            },
        },
    },
};
